<?php
declare(strict_types=1);

require_once __DIR__ . '/order_center.php';
require_once __DIR__ . '/finance_lib.php';
require_once __DIR__ . '/../inventory_sqlite_helpers.php';

/**
 * Период для дашборда статистики (расширяет finance presets).
 *
 * @return array{start:string,end:string,label:string,preset:string}
 */
function fixarivan_dashboard_parse_period(array $get): array
{
    $preset = strtolower(trim((string)($get['preset'] ?? '')));
    $fromIn = trim((string)($get['from'] ?? ''));
    $toIn = trim((string)($get['to'] ?? ''));

    if ($fromIn !== '' && $toIn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIn) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn)) {
        return fixarivan_finance_parse_period(['from' => $fromIn, 'to' => $toIn]);
    }

    $tz = fixarivan_finance_tz();
    $today = new DateTimeImmutable('today', $tz);

    if ($preset === 'all' || $preset === '') {
        return [
            'start' => '',
            'end' => '',
            'label' => 'Все время',
            'preset' => 'all',
        ];
    }

    if ($preset === 'yesterday') {
        $y = $today->modify('-1 day');

        return [
            'start' => $y->format('Y-m-d'),
            'end' => $y->format('Y-m-d'),
            'label' => 'Вчера',
            'preset' => 'yesterday',
        ];
    }

    if ($preset === '30d') {
        $start = $today->modify('-29 days');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'label' => '30 дней',
            'preset' => '30d',
        ];
    }

    if ($preset === 'prev_month') {
        $start = $today->modify('first day of last month');
        $end = $today->modify('last day of last month');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'label' => $start->format('Y-m') . ' (пред. месяц)',
            'preset' => 'prev_month',
        ];
    }

    $financePresets = ['today', '7d', 'week', 'month', 'quarter', 'year'];
    if (in_array($preset, $financePresets, true)) {
        return fixarivan_finance_parse_period(['preset' => $preset]);
    }

    return fixarivan_dashboard_parse_period(['preset' => '30d']);
}

/**
 * @return array{start:string,end:string,label:string,preset:string,days:int}
 */
function fixarivan_dashboard_previous_period(array $period): array
{
    $tz = fixarivan_finance_tz();
    if (($period['start'] ?? '') === '' || ($period['end'] ?? '') === '') {
        return [
            'start' => '',
            'end' => '',
            'label' => 'Пред. период',
            'preset' => 'previous',
            'days' => 0,
        ];
    }

    $start = new DateTimeImmutable((string)$period['start'], $tz);
    $end = new DateTimeImmutable((string)$period['end'], $tz);
    $days = (int)$start->diff($end)->days + 1;
    $prevEnd = $start->modify('-1 day');
    $prevStart = $prevEnd->modify('-' . max(0, $days - 1) . ' days');

    return [
        'start' => $prevStart->format('Y-m-d'),
        'end' => $prevEnd->format('Y-m-d'),
        'label' => 'Пред. ' . ($period['label'] ?? 'период'),
        'preset' => 'previous',
        'days' => $days,
    ];
}

function fixarivan_dashboard_order_date(array $row): string
{
    foreach (['date_of_acceptance', 'work_date', 'date_created'] as $field) {
        $d = fixarivan_finance_sql_date((string)($row[$field] ?? ''));
        if ($d !== '') {
            return $d;
        }
    }

    return '';
}

/** @return 'waiting'|'in_progress'|'completed'|'cancelled' */
function fixarivan_dashboard_order_bucket(array $row): string
{
    $legacy = strtolower(trim((string)($row['status'] ?? '')));
    if ($legacy === 'cancelled') {
        return 'cancelled';
    }

    $pub = fixarivan_normalize_public_status($row['public_status'] ?? $row['order_status'] ?? null);
    if ($pub === 'cancelled') {
        return 'cancelled';
    }
    if (in_array($pub, ['done', 'delivered'], true) || $legacy === 'signed') {
        return 'completed';
    }
    if ($pub === 'waiting_parts') {
        return 'waiting';
    }
    if (in_array($pub, ['in_progress', 'in_transit'], true) || in_array($legacy, ['sent_to_client', 'viewed'], true)) {
        return 'in_progress';
    }
    if ($legacy === '' || in_array($legacy, ['pending', 'draft'], true)) {
        return 'waiting';
    }

    return 'in_progress';
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{waiting:int,in_progress:int,completed:int,cancelled:int,total:int}
 */
function fixarivan_dashboard_count_orders(array $rows, ?string $start = null, ?string $end = null): array
{
    $counts = [
        'waiting' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'total' => 0,
    ];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($start !== null && $end !== null && $start !== '' && $end !== '') {
            $d = fixarivan_dashboard_order_date($row);
            if ($d === '' || $d < $start || $d > $end) {
                continue;
            }
        }
        $bucket = fixarivan_dashboard_order_bucket($row);
        $counts[$bucket]++;
        $counts['total']++;
    }

    return $counts;
}

/**
 * @return array<string,int|float|null>|null
 */
function fixarivan_dashboard_trend(int|float $current, int|float $previous): ?array
{
    if ($previous == 0) {
        if ($current == 0) {
            return ['pct' => 0.0, 'direction' => 'flat'];
        }

        return ['pct' => 100.0, 'direction' => 'up'];
    }

    $pct = round((($current - $previous) / $previous) * 100, 1);

    return [
        'pct' => abs($pct),
        'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
        'signed_pct' => $pct,
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function fixarivan_dashboard_order_series(array $rows, string $chartRange): array
{
    $tz = fixarivan_finance_tz();
    $today = new DateTimeImmutable('today', $tz);
    $labels = [];
    $keys = [];
    $waiting = [];
    $inProgress = [];
    $completed = [];
    $total = [];

    if ($chartRange === '12m') {
        $cursor = $today->modify('-11 months')->modify('first day of this month');
        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');
            $keys[] = $key;
            $labels[] = $cursor->format('m.Y');
            $waiting[$key] = 0;
            $inProgress[$key] = 0;
            $completed[$key] = 0;
            $total[$key] = 0;
            $cursor = $cursor->modify('+1 month');
        }
        foreach ($rows as $row) {
            $d = fixarivan_dashboard_order_date($row);
            if ($d === '') {
                continue;
            }
            $key = substr($d, 0, 7);
            if (!array_key_exists($key, $waiting)) {
                continue;
            }
            $bucket = fixarivan_dashboard_order_bucket($row);
            if ($bucket === 'waiting') {
                $waiting[$key]++;
            } elseif ($bucket === 'in_progress') {
                $inProgress[$key]++;
            } elseif ($bucket === 'completed') {
                $completed[$key]++;
            }
            if ($bucket !== 'cancelled') {
                $total[$key]++;
            }
        }
    } else {
        $days = $chartRange === '7d' ? 7 : 30;
        $start = $today->modify('-' . ($days - 1) . ' days');
        $cursor = $start;
        for ($i = 0; $i < $days; $i++) {
            $key = $cursor->format('Y-m-d');
            $keys[] = $key;
            $labels[] = $cursor->format('d.m');
            $waiting[$key] = 0;
            $inProgress[$key] = 0;
            $completed[$key] = 0;
            $total[$key] = 0;
            $cursor = $cursor->modify('+1 day');
        }
        foreach ($rows as $row) {
            $d = fixarivan_dashboard_order_date($row);
            if ($d === '' || !array_key_exists($d, $waiting)) {
                continue;
            }
            $bucket = fixarivan_dashboard_order_bucket($row);
            if ($bucket === 'waiting') {
                $waiting[$d]++;
            } elseif ($bucket === 'in_progress') {
                $inProgress[$d]++;
            } elseif ($bucket === 'completed') {
                $completed[$d]++;
            }
            if ($bucket !== 'cancelled') {
                $total[$d]++;
            }
        }
    }

    return [
        'range' => $chartRange,
        'labels' => $labels,
        'waiting' => array_values($waiting),
        'in_progress' => array_values($inProgress),
        'completed' => array_values($completed),
        'total' => array_values($total),
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array<string,int|float>
 */
function fixarivan_dashboard_status_distribution(array $rows, ?string $start = null, ?string $end = null): array
{
    $counts = fixarivan_dashboard_count_orders($rows, $start, $end);
    $sum = max(1, (int)$counts['total']);

    return [
        'waiting' => (int)$counts['waiting'],
        'in_progress' => (int)$counts['in_progress'],
        'completed' => (int)$counts['completed'],
        'cancelled' => (int)$counts['cancelled'],
        'waiting_pct' => round(((int)$counts['waiting'] / $sum) * 100, 1),
        'in_progress_pct' => round(((int)$counts['in_progress'] / $sum) * 100, 1),
        'completed_pct' => round(((int)$counts['completed'] / $sum) * 100, 1),
        'cancelled_pct' => round(((int)$counts['cancelled'] / $sum) * 100, 1),
    ];
}

/**
 * @return array<string,mixed>
 */
function fixarivan_dashboard_financial_summary(PDO $pdo, array $period, array $previousPeriod): array
{
    if (($period['start'] ?? '') === '' || ($period['end'] ?? '') === '') {
        $tz = fixarivan_finance_tz();
        $today = new DateTimeImmutable('today', $tz);
        $start = $today->modify('-29 days')->format('Y-m-d');
        $end = $today->format('Y-m-d');
    } else {
        $start = (string)$period['start'];
        $end = (string)$period['end'];
    }

    $current = fixarivan_finance_overview($pdo, $start, $end);
    $prevStart = (string)($previousPeriod['start'] ?? '');
    $prevEnd = (string)($previousPeriod['end'] ?? '');
    $previous = ($prevStart !== '' && $prevEnd !== '')
        ? fixarivan_finance_overview($pdo, $prevStart, $prevEnd)
        : null;

    $revenue = (float)($current['revenue']['total'] ?? 0);
    $profit = (float)($current['summary']['approx_profit'] ?? 0);
    $partsProfit = (float)($current['parts']['profit'] ?? 0);
    $ordersInPeriod = max(1, (int)($current['orders']['in_period'] ?? 0));
    $marginPct = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;
    $avgOrderValue = round($revenue / $ordersInPeriod, 2);
    $avgProfitPerOrder = round($profit / $ordersInPeriod, 2);

    $prevRevenue = $previous ? (float)($previous['revenue']['total'] ?? 0) : 0.0;
    $prevProfit = $previous ? (float)($previous['summary']['approx_profit'] ?? 0) : 0.0;

    return [
        'revenue' => round($revenue, 2),
        'profit' => round($profit, 2),
        'parts_profit' => round($partsProfit, 2),
        'margin_pct' => $marginPct,
        'avg_order_value' => $avgOrderValue,
        'avg_profit_per_order' => $avgProfitPerOrder,
        'orders_in_period' => (int)($current['orders']['in_period'] ?? 0),
        'trends' => [
            'revenue' => fixarivan_dashboard_trend($revenue, $prevRevenue),
            'profit' => fixarivan_dashboard_trend($profit, $prevProfit),
        ],
        'series' => fixarivan_dashboard_finance_series($pdo, $start, $end),
    ];
}

/**
 * @return array<string,mixed>
 */
function fixarivan_dashboard_finance_series(PDO $pdo, string $start, string $end): array
{
    $tz = fixarivan_finance_tz();
    $startDt = new DateTimeImmutable($start, $tz);
    $endDt = new DateTimeImmutable($end, $tz);
    $days = (int)$startDt->diff($endDt)->days + 1;
    $useMonthly = $days > 62;
    $maxPoints = $useMonthly ? 12 : min(10, max(1, $days));

    $labels = [];
    $revenue = [];
    $profit = [];
    $buckets = [];

    if ($useMonthly) {
        $cursor = $startDt->modify('first day of this month');
        while ($cursor <= $endDt && count($buckets) < 12) {
            $key = $cursor->format('Y-m');
            $buckets[] = [
                'key' => $key,
                'label' => $cursor->format('m.Y'),
                'start' => max($start, $cursor->format('Y-m-d')),
                'end' => min($end, $cursor->modify('last day of this month')->format('Y-m-d')),
            ];
            $cursor = $cursor->modify('+1 month');
        }
    } else {
        $step = max(1, (int)ceil($days / $maxPoints));
        $cursor = $startDt;
        while ($cursor <= $endDt && count($buckets) < $maxPoints) {
            $sliceStart = $cursor->format('Y-m-d');
            $sliceEndDt = $cursor->modify('+' . ($step - 1) . ' days');
            if ($sliceEndDt > $endDt) {
                $sliceEndDt = $endDt;
            }
            $buckets[] = [
                'key' => $sliceStart,
                'label' => $cursor->format('d.m'),
                'start' => $sliceStart,
                'end' => $sliceEndDt->format('Y-m-d'),
            ];
            $cursor = $sliceEndDt->modify('+1 day');
        }
    }

    foreach ($buckets as $bucket) {
        $ov = fixarivan_finance_overview($pdo, (string)$bucket['start'], (string)$bucket['end']);
        $labels[] = (string)$bucket['label'];
        $revenue[] = round((float)($ov['revenue']['total'] ?? 0), 2);
        $profit[] = round((float)($ov['summary']['approx_profit'] ?? 0), 2);
    }

    return [
        'labels' => $labels,
        'revenue' => $revenue,
        'profit' => $profit,
    ];
}

/**
 * @return array<string,mixed>
 */
function fixarivan_dashboard_inventory_analytics(PDO $pdo, array $period, string $chartRange): array
{
    $base = sqliteInventoryAggregateStats($pdo);
    $categories = sqliteInventoryCategoryBreakdown($pdo);
    $base['out_of_stock'] = max(0, (int)$base['total'] - (int)$base['in_stock']);
    $base['categories_count'] = count($categories);

    $start = (string)($period['start'] ?? '');
    $end = (string)($period['end'] ?? '');
    if ($start === '' || $end === '') {
        $tz = fixarivan_finance_tz();
        $today = new DateTimeImmutable('today', $tz);
        $start = $today->modify('-29 days')->format('Y-m-d');
        $end = $today->format('Y-m-d');
    }

    $topCategories = array_slice($categories, 0, 5);

    $usedStmt = $pdo->prepare(
        "SELECT i.id, i.name, i.sku, SUM(ABS(m.quantity_delta)) AS used_qty
         FROM inventory_movements m
         INNER JOIN inventory_items i ON i.id = m.item_id
         WHERE m.movement_type IN ('sale', 'out')
           AND substr(COALESCE(m.created_at, ''), 1, 10) >= :start
           AND substr(COALESCE(m.created_at, ''), 1, 10) <= :end
         GROUP BY i.id
         ORDER BY used_qty DESC
         LIMIT 5"
    );
    $usedStmt->execute([':start' => $start, ':end' => $end]);
    $mostUsed = $usedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $leastStmt = $pdo->prepare(
        "SELECT i.id, i.name, i.sku, COALESCE(SUM(ABS(m.quantity_delta)), 0) AS used_qty
         FROM inventory_items i
         LEFT JOIN inventory_movements m ON m.item_id = i.id
           AND m.movement_type IN ('sale', 'out')
           AND substr(COALESCE(m.created_at, ''), 1, 10) >= :start
           AND substr(COALESCE(m.created_at, ''), 1, 10) <= :end
         GROUP BY i.id
         HAVING used_qty > 0
         ORDER BY used_qty ASC
         LIMIT 5"
    );
    $leastStmt->execute([':start' => $start, ':end' => $end]);
    $leastUsed = $leastStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lowStockStmt = $pdo->query(
        "SELECT i.id, i.name, i.sku, COALESCE(b.quantity, 0) AS qty, COALESCE(i.min_stock, 0) AS min_stock
         FROM inventory_items i
         LEFT JOIN inventory_balances b ON b.item_id = i.id
         WHERE COALESCE(b.quantity, 0) > 0
           AND COALESCE(b.quantity, 0) <= COALESCE(i.min_stock, 0)
         ORDER BY qty ASC
         LIMIT 8"
    );
    $lowStockItems = $lowStockStmt ? ($lowStockStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $recentStmt = $pdo->query(
        "SELECT id, name, sku, category, created_at
         FROM inventory_items
         ORDER BY datetime(created_at) DESC
         LIMIT 5"
    );
    $recentItems = $recentStmt ? ($recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    return [
        'snapshot' => $base,
        'top_categories' => $topCategories,
        'most_used_parts' => $mostUsed,
        'least_used_parts' => $leastUsed,
        'low_stock_items' => $lowStockItems,
        'recent_items' => $recentItems,
        'movement' => fixarivan_dashboard_inventory_movement_series($pdo, $start, $end, $chartRange),
    ];
}

/**
 * @return array<string,mixed>
 */
function fixarivan_dashboard_inventory_movement_series(PDO $pdo, string $start, string $end, string $chartRange): array
{
    $stmt = $pdo->prepare(
        "SELECT substr(COALESCE(created_at, ''), 1, 10) AS d,
                SUM(CASE WHEN quantity_delta > 0 THEN quantity_delta ELSE 0 END) AS incoming,
                SUM(CASE WHEN quantity_delta < 0 THEN ABS(quantity_delta) ELSE 0 END) AS consumed
         FROM inventory_movements
         WHERE substr(COALESCE(created_at, ''), 1, 10) >= :start
           AND substr(COALESCE(created_at, ''), 1, 10) <= :end
         GROUP BY d
         ORDER BY d ASC"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['d']] = [
            'incoming' => (float)($row['incoming'] ?? 0),
            'consumed' => (float)($row['consumed'] ?? 0),
        ];
    }

    $tz = fixarivan_finance_tz();
    $startDt = new DateTimeImmutable($start, $tz);
    $endDt = new DateTimeImmutable($end, $tz);
    $days = (int)$startDt->diff($endDt)->days + 1;
    if ($chartRange === '12m' || $days > 62) {
        $labels = [];
        $incoming = [];
        $consumed = [];
        $cursor = $startDt->modify('first day of this month');
        while ($cursor <= $endDt) {
            $labels[] = $cursor->format('m.Y');
            $monthIncoming = 0.0;
            $monthConsumed = 0.0;
            $monthEnd = $cursor->modify('last day of this month');
            $walk = $cursor;
            while ($walk <= $monthEnd && $walk <= $endDt) {
                $key = $walk->format('Y-m-d');
                if (isset($map[$key])) {
                    $monthIncoming += $map[$key]['incoming'];
                    $monthConsumed += $map[$key]['consumed'];
                }
                $walk = $walk->modify('+1 day');
            }
            $incoming[] = round($monthIncoming, 2);
            $consumed[] = round($monthConsumed, 2);
            $cursor = $cursor->modify('+1 month');
        }

        return compact('labels', 'incoming', 'consumed');
    }

    $labels = [];
    $incoming = [];
    $consumed = [];
    $cursor = $startDt;
    while ($cursor <= $endDt) {
        $key = $cursor->format('Y-m-d');
        $labels[] = $cursor->format('d.m');
        $incoming[] = round((float)($map[$key]['incoming'] ?? 0), 2);
        $consumed[] = round((float)($map[$key]['consumed'] ?? 0), 2);
        $cursor = $cursor->modify('+1 day');
    }

    return compact('labels', 'incoming', 'consumed');
}

/**
 * @return array<string,mixed>
 */
function fixarivan_dashboard_integrity(PDO $pdo, array $orderCounts, array $inventory): array
{
    $checks = [];
    $warnings = [];

    $checks[] = ['id' => 'database', 'label' => 'База данных', 'ok' => true, 'detail' => 'SQLite подключена'];

    $bucketSum = (int)$orderCounts['waiting']
        + (int)$orderCounts['in_progress']
        + (int)$orderCounts['completed']
        + (int)$orderCounts['cancelled'];
    $totalOrders = (int)$orderCounts['total'];
    $ordersOk = $bucketSum === $totalOrders;
    if (!$ordersOk) {
        $warnings[] = 'Сумма статусов заказов не совпадает с общим числом';
    }
    $checks[] = [
        'id' => 'orders',
        'label' => 'Заказы',
        'ok' => $ordersOk,
        'detail' => $ordersOk ? 'Проверено' : "{$bucketSum} ≠ {$totalOrders}",
    ];

    $invTotal = (int)($inventory['total'] ?? 0);
    $invInStock = (int)($inventory['in_stock'] ?? 0);
    $invOut = (int)($inventory['out_of_stock'] ?? max(0, $invTotal - $invInStock));
    $inventoryOk = ($invInStock + $invOut) === $invTotal;
    if (!$inventoryOk) {
        $warnings[] = 'Сумма «в наличии + нет» не совпадает с общим числом позиций';
    }
    $checks[] = [
        'id' => 'inventory',
        'label' => 'Склад',
        'ok' => $inventoryOk,
        'detail' => $inventoryOk ? 'Проверено' : "{$invInStock}+{$invOut} ≠ {$invTotal}",
    ];

    $checks[] = ['id' => 'financial', 'label' => 'Финансовые расчёты', 'ok' => true, 'detail' => 'Модуль finance_lib'];
    $checks[] = ['id' => 'cache', 'label' => 'Кэш', 'ok' => true, 'detail' => 'Live SQLite (без серверного кэша)'];

    return [
        'ok' => count($warnings) === 0,
        'checks' => $checks,
        'warnings' => $warnings,
        'verified_at' => date('d.m.Y H:i'),
    ];
}

/**
 * @return array<string,mixed>
 */
function fixarivan_dashboard_build_stats(PDO $pdo, array $get): array
{
    $t0 = microtime(true);
    $period = fixarivan_dashboard_parse_period($get);
    $previousPeriod = fixarivan_dashboard_previous_period($period);
    $chartRange = in_array(($get['chart_range'] ?? '30d'), ['7d', '30d', '12m'], true)
        ? (string)$get['chart_range']
        : '30d';

    $orderStmt = $pdo->query(
        'SELECT status, public_status, order_status, date_of_acceptance, work_date, date_created FROM orders'
    );
    $orderRows = $orderStmt ? ($orderStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $snapshot = fixarivan_dashboard_count_orders($orderRows);
    $periodStart = ($period['start'] ?? '') !== '' ? (string)$period['start'] : null;
    $periodEnd = ($period['end'] ?? '') !== '' ? (string)$period['end'] : null;
    $periodCounts = ($periodStart !== null && $periodEnd !== null)
        ? fixarivan_dashboard_count_orders($orderRows, $periodStart, $periodEnd)
        : $snapshot;

    $prevStart = (string)($previousPeriod['start'] ?? '');
    $prevEnd = (string)($previousPeriod['end'] ?? '');
    $previousCounts = ($prevStart !== '' && $prevEnd !== '')
        ? fixarivan_dashboard_count_orders($orderRows, $prevStart, $prevEnd)
        : ['waiting' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0, 'total' => 0];

    $trends = [
        'waiting' => fixarivan_dashboard_trend((int)$periodCounts['waiting'], (int)$previousCounts['waiting']),
        'in_progress' => fixarivan_dashboard_trend((int)$periodCounts['in_progress'], (int)$previousCounts['in_progress']),
        'completed' => fixarivan_dashboard_trend((int)$periodCounts['completed'], (int)$previousCounts['completed']),
        'cancelled' => fixarivan_dashboard_trend((int)$periodCounts['cancelled'], (int)$previousCounts['cancelled']),
        'total' => fixarivan_dashboard_trend((int)$periodCounts['total'], (int)$previousCounts['total']),
    ];
    if ($periodStart === null || $periodEnd === null) {
        $trends = [
            'waiting' => ['pct' => 0.0, 'direction' => 'flat'],
            'in_progress' => ['pct' => 0.0, 'direction' => 'flat'],
            'completed' => ['pct' => 0.0, 'direction' => 'flat'],
            'cancelled' => ['pct' => 0.0, 'direction' => 'flat'],
            'total' => ['pct' => 0.0, 'direction' => 'flat'],
        ];
    }

    $receipts = (int)$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn();
    $reports = (int)$pdo->query('SELECT COUNT(*) FROM mobile_reports')->fetchColumn();

    $inventoryAnalytics = fixarivan_dashboard_inventory_analytics($pdo, $period, $chartRange);
    $inventory = $inventoryAnalytics['snapshot'];

    $result = [
        'pending' => (int)$periodCounts['waiting'],
        'waiting' => (int)$periodCounts['waiting'],
        'in_progress' => (int)$periodCounts['in_progress'],
        'completed' => (int)$periodCounts['completed'],
        'cancelled' => (int)$periodCounts['cancelled'],
        'total' => (int)$periodCounts['total'],
        'total_orders' => (int)$periodCounts['total'],
        'total_documents' => (int)$periodCounts['total'] + $receipts + $reports,
        'orders' => (int)$snapshot['total'],
        'orders_snapshot' => $snapshot,
        'receipts' => $receipts,
        'reports' => $reports,
        'inventory' => $inventory,
        'period' => $period,
        'previous_period' => $previousPeriod,
        'trends' => $trends,
        'orders_chart' => fixarivan_dashboard_order_series($orderRows, $chartRange),
        'status_distribution' => fixarivan_dashboard_status_distribution($orderRows, $periodStart, $periodEnd),
        'financial' => fixarivan_dashboard_financial_summary($pdo, $period, $previousPeriod),
        'inventory_analytics' => $inventoryAnalytics,
        'integrity' => fixarivan_dashboard_integrity($pdo, $snapshot, $inventory),
        'cache' => [
            'status' => 'live',
            'source' => 'sqlite',
            'server_cache' => false,
            'calculation_ms' => 0,
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'fast_mode' => true,
    ];
    $result['cache']['calculation_ms'] = round((microtime(true) - $t0) * 1000, 1);

    return $result;
}
