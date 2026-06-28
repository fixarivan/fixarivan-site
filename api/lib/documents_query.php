<?php
declare(strict_types=1);

require_once __DIR__ . '/site_url.php';
require_once __DIR__ . '/order_center.php';

function order_status_label_ru(?string $status): string {
    $s = trim((string)$status);
    $map = [
        'pending' => 'Черновик',
        'draft' => 'Черновик',
        'sent_to_client' => 'Отправлен клиенту',
        'viewed' => 'Просмотрен',
        'signed' => 'Подписан',
        'cancelled' => 'Отменён',
        'in_progress' => 'В работе',
        'completed' => 'Завершён',
        'waiting_parts' => 'Ожидает запчасть',
        'in_transit' => 'В пути',
        'done' => 'Готово',
        'delivered' => 'Выдан',
    ];
    return $map[$s] ?? ($s !== '' ? $s : '—');
}

function receipt_status_label_ru(?string $status): string {
    $s = trim((string)$status);
    $map = [
        'pending' => 'Ожидает',
        'completed' => 'Оплачен',
        'cancelled' => 'Отменён',
        'draft' => 'Черновик',
    ];
    return $map[$s] ?? ($s !== '' ? $s : '—');
}

function invoice_status_label_ru(?string $status): string {
    $s = trim((string)$status);
    $map = [
        'draft' => 'Черновик',
        'issued' => 'Выставлен',
        'partially_paid' => 'Частично оплачен',
        'paid' => 'Оплачен',
        'overdue' => 'Просрочен',
        'cancelled' => 'Отменён',
    ];
    return $map[$s] ?? ($s !== '' ? $s : '—');
}

/**
 * Компактная строка для поиска по позициям заказа (названия, SKU), в нижнем регистре.
 */
function fixarivan_order_lines_json_search_blob(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '[]') {
        return '';
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return '';
    }
    $parts = [];
    foreach ($arr as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        foreach (['name', 'title', 'sku'] as $k) {
            $v = trim((string) ($ln[$k] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }
    }
    $joined = implode(' ', $parts);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($joined, 'UTF-8');
    }

    return strtolower($joined);
}

/**
 * @return list<array<string,mixed>>
 */
function documents_list_from_sqlite(PDO $pdo, string $typeFilter, int $limit): array {
    $limit = max(1, min(500, $limit));
    $out = [];

    if ($typeFilter === 'all' || $typeFilter === 'order') {
        $stmt = $pdo->query(
            'SELECT document_id, order_id, client_id, client_name, client_phone, client_email, device_model, device_type, device_serial, problem_description, status, public_status, order_status, parts_status, public_expected_date, public_comment, public_estimated_cost, internal_comment, client_token, language, order_type, unique_code, order_lines_json, parts_sale_total, parts_prepayment_status, parts_prepayment_amount,
                    COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS sort_date
             FROM orders
             ORDER BY sort_date DESC
             LIMIT ' . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $token = trim((string)($row['client_token'] ?? ''));
            $pubRaw = trim((string)($row['public_status'] ?? ''));
            $pubNorm = $pubRaw !== '' ? fixarivan_normalize_public_status($pubRaw) : fixarivan_normalize_public_status($row['order_status'] ?? null);
            $langRaw = strtolower(trim((string)($row['language'] ?? '')));
            $portalLang = in_array($langRaw, ['ru', 'en', 'fi'], true) ? $langRaw : 'ru';
            $portal = $token !== ''
                ? fixarivan_absolute_url('client_portal.php?token=' . rawurlencode($token))
                : null;
            $viewer = $portal ?? ($token !== ''
                ? fixarivan_absolute_url('order_view.php?token=' . rawurlencode($token))
                : null);
            $out[] = [
                'type' => 'order',
                'document_id' => $row['document_id'],
                'order_id' => $row['order_id'] ?? null,
                'client_id' => $row['client_id'] ?? null,
                'display_id' => $row['document_id'],
                'client_name' => $row['client_name'],
                'client_phone' => (string)($row['client_phone'] ?? ''),
                'client_email' => (string)($row['client_email'] ?? ''),
                'device_model' => $row['device_model'],
                'device_type' => (string)($row['device_type'] ?? ''),
                'device_serial' => trim((string)($row['device_serial'] ?? '')),
                'unique_code' => trim((string)($row['unique_code'] ?? '')),
                'lines_search' => fixarivan_order_lines_json_search_blob($row['order_lines_json'] ?? null),
                'problem_description' => (string)($row['problem_description'] ?? ''),
                'status' => $row['status'],
                'public_status' => $pubNorm,
                'order_status' => $pubNorm,
                'parts_status' => trim((string)($row['parts_status'] ?? '')) !== '' ? trim((string)$row['parts_status']) : null,
                'public_expected_date' => trim((string)($row['public_expected_date'] ?? '')),
                'public_comment' => (string)($row['public_comment'] ?? ''),
                'public_estimated_cost' => (string)($row['public_estimated_cost'] ?? ''),
                'internal_comment' => (string)($row['internal_comment'] ?? ''),
                'parts_sale_total' => isset($row['parts_sale_total']) && $row['parts_sale_total'] !== null && $row['parts_sale_total'] !== ''
                    ? (float)$row['parts_sale_total']
                    : null,
                'parts_prepayment_status' => fixarivan_normalize_parts_prepayment_status($row['parts_prepayment_status'] ?? null),
                'parts_prepayment_amount' => isset($row['parts_prepayment_amount']) && $row['parts_prepayment_amount'] !== null && $row['parts_prepayment_amount'] !== ''
                    ? (float)$row['parts_prepayment_amount']
                    : null,
                'order_type' => (string)($row['order_type'] ?? 'repair'),
                'language' => $portalLang,
                'status_label' => order_status_label_ru($pubNorm),
                'date_created' => $row['sort_date'],
                'client_token' => $token !== '' ? $token : null,
                'portal_url' => $portal,
                'viewer_url' => $viewer,
                'has_viewer_link' => $viewer !== null,
            ];
        }
    }

    if ($typeFilter === 'all' || $typeFilter === 'receipt') {
        $stmt = $pdo->query(
            'SELECT document_id, order_id, receipt_number, client_name, client_phone, client_email, device_model, status, client_token, total_amount, amount_paid, payment_method, payment_status, payment_date, payment_note,
                    COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS sort_date
             FROM receipts
             ORDER BY sort_date DESC
             LIMIT ' . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $token = trim((string)($row['client_token'] ?? ''));
            $viewer = $token !== ''
                ? fixarivan_absolute_url('receipt_view.php?token=' . rawurlencode($token))
                : null;
            $display = trim((string)($row['receipt_number'] ?? '')) !== ''
                ? (string)$row['receipt_number']
                : (string)$row['document_id'];
            $out[] = [
                'type' => 'receipt',
                'document_id' => $row['document_id'],
                'order_id' => $row['order_id'] ?? null,
                'display_id' => $display,
                'client_name' => $row['client_name'],
                'client_phone' => (string)($row['client_phone'] ?? ''),
                'client_email' => (string)($row['client_email'] ?? ''),
                'device_model' => (string)($row['device_model'] ?? ''),
                'status' => $row['status'],
                'status_label' => receipt_status_label_ru($row['status'] ?? null),
                'date_created' => $row['sort_date'],
                'total_amount' => $row['total_amount'],
                'amount_paid' => $row['amount_paid'] ?? null,
                'payment_method' => (string)($row['payment_method'] ?? ''),
                'payment_status' => (string)($row['payment_status'] ?? ''),
                'payment_date' => (string)($row['payment_date'] ?? ''),
                'payment_note' => (string)($row['payment_note'] ?? ''),
                'client_token' => $token !== '' ? $token : null,
                'viewer_url' => $viewer,
                'has_viewer_link' => $viewer !== null,
            ];
        }
    }

    if ($typeFilter === 'all' || $typeFilter === 'report') {
        $stmt = $pdo->query(
            'SELECT report_id, token, order_id, client_name, phone, model, device_type, created_at
             FROM mobile_reports
             ORDER BY created_at DESC
             LIMIT ' . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $token = strtolower(trim((string)($row['token'] ?? '')));
            $viewer = $token !== ''
                ? fixarivan_absolute_url('report_view.php?token=' . rawurlencode($token))
                : null;
            $out[] = [
                'type' => 'report',
                'document_id' => $row['report_id'],
                'order_id' => $row['order_id'] ?? null,
                'display_id' => $row['report_id'],
                'client_name' => $row['client_name'],
                'client_phone' => (string)($row['phone'] ?? ''),
                'client_email' => '',
                'device_model' => $row['model'] ?? $row['device_type'],
                'status' => 'completed',
                'status_label' => 'Отчёт',
                'date_created' => $row['created_at'],
                'client_token' => $token !== '' ? $token : null,
                'viewer_url' => $viewer,
                'has_viewer_link' => $viewer !== null,
            ];
        }
    }

    if ($typeFilter === 'all' || $typeFilter === 'invoice') {
        $stmt = $pdo->query(
            'SELECT document_id, invoice_id, order_id, client_id, client_name, client_phone, client_email, service_object, status, due_date, total_amount, client_token, payment_date, payment_method,
                    COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS sort_date
             FROM invoices
             ORDER BY sort_date DESC
             LIMIT ' . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $display = trim((string)($row['invoice_id'] ?? '')) !== ''
                ? (string)$row['invoice_id']
                : (string)$row['document_id'];
            $token = trim((string)($row['client_token'] ?? ''));
            $viewer = $token !== ''
                ? fixarivan_absolute_url('invoice_view.php?token=' . rawurlencode($token))
                : null;
            $portal = $token !== ''
                ? fixarivan_absolute_url('client_portal.php?token=' . rawurlencode($token))
                : null;
            $out[] = [
                'type' => 'invoice',
                'document_id' => $row['document_id'],
                'order_id' => $row['order_id'] ?? null,
                'client_id' => $row['client_id'] ?? null,
                'display_id' => $display,
                'client_name' => $row['client_name'],
                'client_phone' => (string)($row['client_phone'] ?? ''),
                'client_email' => (string)($row['client_email'] ?? ''),
                'device_model' => (string)($row['service_object'] ?? ''),
                'status' => $row['status'],
                'status_label' => invoice_status_label_ru($row['status'] ?? null),
                'date_created' => $row['sort_date'],
                'due_date' => (string)($row['due_date'] ?? ''),
                'total_amount' => $row['total_amount'],
                'payment_date' => (string)($row['payment_date'] ?? ''),
                'payment_method' => (string)($row['payment_method'] ?? ''),
                'viewer_url' => $viewer,
                'portal_url' => $portal,
                'client_token' => $token !== '' ? $token : null,
                'has_viewer_link' => $viewer !== null,
            ];
        }
    }

    usort($out, static function ($a, $b) {
        $ta = strtotime((string)($a['date_created'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['date_created'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    return array_slice($out, 0, $limit);
}
