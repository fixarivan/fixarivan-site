<?php
declare(strict_types=1);

/**
 * Быстрое обновление заказа из Track: статус, комментарии, язык портала, ожидаемая дата (public_expected_date).
 * Дата — единый источник для портала и напоминаний AUTO_SUPPLY (при непустой заявке на закуп).
 * Успешный ответ может содержать supply_warning (TZ P2 блок 7), если нужна закупка, а дата не задана.
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_center.php';
require_once __DIR__ . '/lib/order_supply.php';

function fixarivan_track_parse_money_string(?string $raw): float
{
    $s = trim((string)$raw);
    if ($s === '') {
        return 0.0;
    }
    if (preg_match('/([0-9]+(?:[.,][0-9]+)?)/', str_replace("\xc2\xa0", ' ', $s), $m)) {
        return (float)str_replace(',', '.', (string)$m[1]);
    }

    return 0.0;
}

function fixarivan_track_is_repair_service_line(string $name, string $description): bool
{
    $nameLower = mb_strtolower(trim($name), 'UTF-8');
    $descLower = mb_strtolower(trim($description), 'UTF-8');
    return $nameLower === 'repair service'
        || str_contains($nameLower, 'repair service')
        || str_contains($nameLower, 'услуга ремонта')
        || str_contains($descLower, 'услуга ремонта')
        || str_contains($descLower, 'ориентировочная стоимость работы')
        || str_contains($descLower, 'estimated labor')
        || str_contains($descLower, 'estimated work');
}

function fixarivan_track_sync_receipts_work_cost(PDO $pdo, string $orderId, string $workCostRaw): void
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return;
    }
    $newCost = fixarivan_track_parse_money_string($workCostRaw);
    $now = date('c');
    $st = $pdo->prepare('SELECT document_id, services_rendered, total_amount FROM receipts WHERE order_id = :oid');
    $st->execute([':oid' => $orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return;
    }
    $upd = $pdo->prepare('UPDATE receipts SET services_rendered = :srv, total_amount = :tot, date_updated = :u WHERE document_id = :id');
    foreach ($rows as $row) {
        $rawServices = trim((string)($row['services_rendered'] ?? ''));
        if ($rawServices === '') {
            continue;
        }
        $lines = preg_split('/\r\n|\r|\n/', $rawServices) ?: [];
        $changed = false;
        $oldRepairCost = 0.0;
        $nextLines = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $price = 0.0;
            $body = $line;
            if (preg_match('/^(.*?)(?:\s*-\s*|\s+)([0-9]+(?:[.,][0-9]+)?)\s*(?:€|eur)?$/iu', $line, $m)) {
                $body = trim((string)$m[1]);
                $price = (float)str_replace(',', '.', (string)$m[2]);
            }
            $name = $body;
            $description = '';
            if (preg_match('/^(.*?)\s*\((.*?)\)\s*$/u', $body, $parts)) {
                $name = trim((string)$parts[1]);
                $description = trim((string)$parts[2]);
            }
            if (fixarivan_track_is_repair_service_line($name, $description)) {
                $nextLines[] = 'Repair service - ' . number_format($newCost, 2, '.', '');
                $oldRepairCost += $price;
                $changed = true;
                continue;
            }
            $nextLines[] = $line;
        }
        if (!$changed) {
            continue;
        }
        $currentTotal = isset($row['total_amount']) && is_numeric($row['total_amount']) ? (float)$row['total_amount'] : 0.0;
        $nextTotal = max(0.0, $currentTotal - $oldRepairCost + $newCost);
        $upd->execute([
            ':srv' => implode("\n", $nextLines),
            ':tot' => $nextTotal,
            ':u' => $now,
            ':id' => (string)($row['document_id'] ?? ''),
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$documentId = trim((string)($input['documentId'] ?? $input['document_id'] ?? ''));
if ($documentId === '') {
    echo json_encode(['success' => false, 'message' => 'Нужен documentId'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hasPublic = array_key_exists('publicStatus', $input) || array_key_exists('public_status', $input);
$hasInternal = array_key_exists('internalComment', $input) || array_key_exists('internal_comment', $input);
$hasPublicComment = array_key_exists('publicComment', $input) || array_key_exists('public_comment', $input);
$hasLanguage = array_key_exists('language', $input) || array_key_exists('portal_language', $input);
$hasExpectedDate = array_key_exists('publicExpectedDate', $input) || array_key_exists('public_expected_date', $input);
$hasPublicEstimatedCost = array_key_exists('publicEstimatedCost', $input) || array_key_exists('public_estimated_cost', $input);

if (!$hasPublic && !$hasInternal && !$hasPublicComment && !$hasLanguage && !$hasExpectedDate && !$hasPublicEstimatedCost) {
    echo json_encode(['success' => false, 'message' => 'Укажите publicStatus, publicComment, internalComment, language, publicExpectedDate и/или publicEstimatedCost'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getSqliteConnection();
    $stmt = $pdo->prepare(
        'SELECT document_id, order_id, public_status, order_status, public_comment, public_estimated_cost, estimated_labor_cost, internal_comment, language, public_expected_date,
                supply_request, supply_urgency, device_model, client_name, priority, order_lines_json
         FROM orders WHERE document_id = :d LIMIT 1'
    );
    $stmt->execute([':d' => $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = date('c');
    $pubNorm = fixarivan_normalize_public_status($row['public_status'] ?? $row['order_status'] ?? null);
    $pcVal = trim((string)($row['public_comment'] ?? ''));
    $icVal = trim((string)($row['internal_comment'] ?? ''));
    $publicEstimatedCostVal = trim((string)($row['public_estimated_cost'] ?? ''));
    $estimatedLaborCostVal = trim((string)($row['estimated_labor_cost'] ?? ''));
    $langVal = strtolower(trim((string)($row['language'] ?? '')));
    if (!in_array($langVal, ['ru', 'en', 'fi'], true)) {
        $langVal = 'ru';
    }
    $expVal = fixarivan_normalize_public_expected_date((string)($row['public_expected_date'] ?? ''));

    if ($hasPublic) {
        $rawPub = $input['publicStatus'] ?? $input['public_status'] ?? '';
        $pubNorm = fixarivan_normalize_public_status(is_string($rawPub) ? $rawPub : null);
    }
    if ($hasPublicComment) {
        $pcVal = trim((string)($input['publicComment'] ?? $input['public_comment'] ?? ''));
    }
    if ($hasInternal) {
        $icVal = trim((string)($input['internalComment'] ?? $input['internal_comment'] ?? ''));
    }
    if ($hasPublicEstimatedCost) {
        $publicEstimatedCostVal = trim((string)($input['publicEstimatedCost'] ?? $input['public_estimated_cost'] ?? ''));
        $estimatedLaborCostVal = $publicEstimatedCostVal;
    }
    if ($hasLanguage) {
        $rawLang = strtolower(trim((string)($input['language'] ?? $input['portal_language'] ?? '')));
        $langVal = in_array($rawLang, ['ru', 'en', 'fi'], true) ? $rawLang : 'ru';
    }
    if ($hasExpectedDate) {
        $rawExp = $input['publicExpectedDate'] ?? $input['public_expected_date'] ?? '';
        $expVal = fixarivan_normalize_public_expected_date(is_string($rawExp) ? $rawExp : (string)$rawExp);
    }

    $upd = $pdo->prepare(
        'UPDATE orders SET public_status = :p, order_status = :p2, public_comment = :pc, internal_comment = :ic, language = :lang,
                public_expected_date = :exp, public_estimated_cost = :pec, estimated_labor_cost = :elc, date_updated = :u WHERE document_id = :d'
    );
    $upd->execute([
        ':p' => $pubNorm,
        ':p2' => $pubNorm,
        ':pc' => $pcVal,
        ':ic' => $icVal,
        ':lang' => $langVal,
        ':exp' => $expVal,
        ':pec' => $publicEstimatedCostVal,
        ':elc' => $estimatedLaborCostVal,
        ':u' => $now,
        ':d' => $documentId,
    ]);

    $oidHook = trim((string)($row['order_id'] ?? ''));
    if ($oidHook === '') {
        $oidHook = $documentId;
    }

    if ($hasPublic) {
        fixarivan_on_order_terminal_public_status($pdo, $oidHook, $pubNorm);
    }
    if ($hasPublicEstimatedCost) {
        fixarivan_track_sync_receipts_work_cost($pdo, $oidHook, $publicEstimatedCostVal);
    }

    if ($hasExpectedDate) {
        if ($expVal === '') {
            fixarivan_clear_supply_calendar_for_order($pdo, $oidHook);
        } else {
            $supplyRaw = trim((string)($row['supply_request'] ?? ''));
            if ($supplyRaw !== '') {
                $urgency = (string)($row['supply_urgency'] ?? $row['priority'] ?? 'medium');
                fixarivan_apply_supply_effects(
                    $pdo,
                    $oidHook,
                    $supplyRaw,
                    (string)($row['device_model'] ?? ''),
                    $urgency,
                    $expVal,
                    (string)($row['client_name'] ?? '')
                );
            }
        }
    }

    $supplyWarning = fixarivan_supply_missing_expected_date_warning(
        (string)($row['supply_request'] ?? ''),
        $expVal,
        (string)($row['order_lines_json'] ?? '')
    );

    echo json_encode([
        'success' => true,
        'document_id' => $documentId,
        'public_status' => $pubNorm,
        'public_comment' => $pcVal,
        'internal_comment' => $icVal,
        'public_estimated_cost' => $publicEstimatedCostVal,
        'estimated_labor_cost' => $estimatedLaborCostVal,
        'language' => $langVal,
        'public_expected_date' => $expVal,
        'supply_warning' => $supplyWarning,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
