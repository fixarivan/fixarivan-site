<?php
declare(strict_types=1);

/**
 * TZ P2 блок 8: единая структура «клиент → заказы → документы → позиции» и общие хелперы с api/clients.php.
 */

/** Сумма по позициям заказа (продажа) из order_lines_json для предпросмотра в UI. */
function fixarivan_orders_estimate_from_lines_json(?string $json): ?float
{
    $rows = json_decode($json ?? '[]', true);
    if (!is_array($rows) || $rows === []) {
        return null;
    }
    $sum = 0.0;
    $has = false;
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $q = (float) ($r['qty'] ?? $r['quantity'] ?? 1);
        if ($q <= 0) {
            $q = 1.0;
        }
        $p = (float) ($r['sale'] ?? $r['sale_price'] ?? $r['price'] ?? 0);
        $sum += $q * $p;
        $has = true;
    }

    return $has ? round($sum, 2) : null;
}

/**
 * Позиции заказа для клиентского портала: название, количество, цена продажи (без закупки/себестоимости).
 *
 * @return list<array{name: string, qty: float, sale: float}>
 */
function fixarivan_portal_public_order_lines(string $json): array
{
    $rows = json_decode($json, true);
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $q = (float) ($r['qty'] ?? $r['quantity'] ?? 1);
        if ($q <= 0) {
            $q = 1.0;
        }
        $out[] = [
            'name' => (string) ($r['name'] ?? $r['title'] ?? '—'),
            'qty' => $q,
            'sale' => (float) ($r['sale'] ?? $r['sale_price'] ?? $r['price'] ?? 0),
        ];
    }

    return $out;
}

function fixarivan_portal_first_order_line_name(?string $json): string
{
    $rows = fixarivan_portal_public_order_lines((string)$json);
    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '' && $name !== '—') {
            return $name;
        }
    }

    return '';
}

function fixarivan_portal_order_display_name(array $order): string
{
    $type = strtolower(trim((string)($order['order_type'] ?? 'repair')));
    $deviceModel = trim((string)($order['device_model'] ?? ''));
    $deviceType = trim((string)($order['device_type'] ?? ''));
    $description = trim((string)($order['problem_description'] ?? ''));
    $comment = trim((string)($order['public_comment'] ?? ''));
    $firstLine = fixarivan_portal_first_order_line_name(isset($order['order_lines_json']) ? (string)$order['order_lines_json'] : '');

    if ($type === 'sale') {
        if ($description !== '') {
            return $description;
        }
        if ($firstLine !== '') {
            return $firstLine;
        }

        return 'Продажа';
    }

    if ($type === 'custom') {
        if ($description !== '' && $comment !== '') {
            return $description . ' — ' . $comment;
        }
        if ($description !== '') {
            return $description;
        }
        if ($comment !== '') {
            return $comment;
        }
        if ($firstLine !== '') {
            return $firstLine;
        }

        return 'Нестандартный заказ';
    }

    if ($deviceModel !== '') {
        return $deviceModel;
    }
    if ($deviceType !== '') {
        return $deviceType;
    }

    return 'Заказ';
}

/**
 * Идентификаторы заказа для сопоставления с order_id у квитанций/счетов/отчётов.
 *
 * @return list<string>
 */
function fixarivan_order_match_ids(array $order): array
{
    $out = [];
    foreach (['order_id', 'document_id'] as $k) {
        $v = trim((string) ($order[$k] ?? ''));
        if ($v !== '') {
            $out[$v] = true;
        }
    }

    return array_keys($out);
}

/**
 * Основной ключ группы: order_id, иначе document_id (заказы без номера).
 */
function fixarivan_order_group_key(array $order): string
{
    $oid = trim((string) ($order['order_id'] ?? ''));
    if ($oid !== '') {
        return $oid;
    }

    return trim((string) ($order['document_id'] ?? ''));
}

/**
 * @param list<array<string,mixed>> $orders
 * @param list<array<string,mixed>> $receipts
 * @param list<array<string,mixed>> $invoices
 * @param list<array<string,mixed>> $reports
 * @return list<array{order: array<string,mixed>, estimate_total: ?float, receipts: list, invoices: list, reports: list}>
 */
function fixarivan_group_orders_with_documents(array $orders, array $receipts, array $invoices, array $reports): array
{
    $byKey = [];
    foreach ($orders as $o) {
        $key = fixarivan_order_group_key($o);
        if ($key === '') {
            continue;
        }
        $byKey[$key] = [
            'order' => $o,
            'estimate_total' => fixarivan_orders_estimate_from_lines_json(isset($o['order_lines_json']) ? (string) $o['order_lines_json'] : null),
            'receipts' => [],
            'invoices' => [],
            'reports' => [],
        ];
    }

    $mapDocToKey = [];
    foreach ($byKey as $key => $g) {
        foreach (fixarivan_order_match_ids($g['order']) as $mid) {
            $mapDocToKey[$mid] = $key;
        }
    }

    foreach ($receipts as $r) {
        $rid = trim((string) ($r['order_id'] ?? ''));
        if ($rid !== '' && isset($mapDocToKey[$rid])) {
            $byKey[$mapDocToKey[$rid]]['receipts'][] = $r;
        }
    }
    foreach ($invoices as $inv) {
        $iid = trim((string) ($inv['order_id'] ?? ''));
        if ($iid !== '' && isset($mapDocToKey[$iid])) {
            $byKey[$mapDocToKey[$iid]]['invoices'][] = $inv;
        }
    }
    $invoicePlaced = [];
    foreach ($byKey as $g) {
        foreach ($g['invoices'] as $x) {
            $did = trim((string) ($x['document_id'] ?? ''));
            if ($did !== '') {
                $invoicePlaced[$did] = true;
            }
        }
    }
    foreach ($invoices as $inv) {
        $docId = trim((string) ($inv['document_id'] ?? ''));
        if ($docId !== '' && isset($invoicePlaced[$docId])) {
            continue;
        }
        $icid = (int) ($inv['client_id'] ?? 0);
        if ($icid <= 0) {
            continue;
        }
        foreach ($byKey as $key => &$g2) {
            $ocid = (int) ($g2['order']['client_id'] ?? 0);
            if ($ocid === $icid) {
                $g2['invoices'][] = $inv;
                if ($docId !== '') {
                    $invoicePlaced[$docId] = true;
                }
                break;
            }
        }
        unset($g2);
    }
    foreach ($reports as $rep) {
        $roid = trim((string) ($rep['order_id'] ?? ''));
        if ($roid !== '' && isset($mapDocToKey[$roid])) {
            $byKey[$mapDocToKey[$roid]]['reports'][] = $rep;
        }
    }

    $list = array_values($byKey);
    usort($list, static function ($a, $b): int {
        $oa = $a['order'] ?? [];
        $ob = $b['order'] ?? [];
        $ta = strtotime((string) ($oa['updated_at'] ?? $oa['date_updated'] ?? '')) ?: 0;
        $tb = strtotime((string) ($ob['updated_at'] ?? $ob['date_updated'] ?? '')) ?: 0;

        return $tb <=> $ta;
    });

    return $list;
}

/**
 * Квитанции/счета/отчёты, относящиеся только к указанному заказу (для портала при focus_order).
 *
 * @return array{receipts: list, invoices: list, reports: list}
 */
function fixarivan_filter_documents_for_order(array $receipts, array $invoices, array $reports, array $order): array
{
    $ids = fixarivan_order_match_ids($order);
    if ($ids === []) {
        return ['receipts' => [], 'invoices' => [], 'reports' => []];
    }
    $set = array_fill_keys($ids, true);

    $fr = [];
    foreach ($receipts as $r) {
        $rid = trim((string) ($r['order_id'] ?? ''));
        if ($rid !== '' && isset($set[$rid])) {
            $fr[] = $r;
        }
    }
    $fi = [];
    foreach ($invoices as $inv) {
        $iid = trim((string) ($inv['order_id'] ?? ''));
        if ($iid !== '' && isset($set[$iid])) {
            $fi[] = $inv;
        }
    }
    $freps = [];
    foreach ($reports as $rep) {
        $roid = trim((string) ($rep['order_id'] ?? ''));
        if ($roid !== '' && isset($set[$roid])) {
            $freps[] = $rep;
        }
    }

    return ['receipts' => $fr, 'invoices' => $fi, 'reports' => $freps];
}

/**
 * Отчёты диагностики с тем же телефоном, что у клиента, но без order_id (не попали в выборку по заказам).
 *
 * @param list<string> $excludeReportIds
 * @return list<array<string,mixed>>
 */
function fixarivan_portal_orphan_mobile_reports(PDO $pdo, string $normalizedPhone, array $excludeReportIds): array
{
    $normalizedPhone = trim($normalizedPhone);
    if ($normalizedPhone === '') {
        return [];
    }
    $excludeReportIds = array_values(array_filter(array_map(static function ($id): string {
        return trim((string) $id);
    }, $excludeReportIds), static fn (string $s): bool => $s !== ''));

    $sql = 'SELECT report_id, token, model, order_id FROM mobile_reports
        WHERE (order_id IS NULL OR TRIM(order_id) = \'\')
        AND REPLACE(REPLACE(REPLACE(IFNULL(phone,\'\'), \'+\', \'\'), \' \', \'\'), \'-\', \'\') = :p
        ORDER BY created_at DESC LIMIT 40';
    $st = $pdo->prepare($sql);
    $st->execute([':p' => $normalizedPhone]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($excludeReportIds === []) {
        return $rows;
    }
    $ex = array_fill_keys($excludeReportIds, true);
    $out = [];
    foreach ($rows as $r) {
        $rid = trim((string) ($r['report_id'] ?? ''));
        if ($rid === '' || isset($ex[$rid])) {
            continue;
        }
        $out[] = $r;
    }

    return $out;
}

/**
 * Убирает дубли документов в портале (один и тот же document_id / report_id не показываем дважды).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function client_portal_dedupe_by_key(array $rows, string $idKey): array
{
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $id = trim((string) ($r[$idKey] ?? ''));
        if ($id === '') {
            $out[] = $r;
            continue;
        }
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $r;
    }

    return $out;
}
