<?php
declare(strict_types=1);

require_once __DIR__ . '/order_client_portal.php';

/**
 * MVP финансовой аналитики: периоды, агрегаты, прозрачные списки (SQLite).
 * Не бухгалтерия: кассовый учёт по дате оплаты; расход по запчастям — ручные суммы в orders.
 */

function fixarivan_finance_tz(): DateTimeZone
{
    return new DateTimeZone('Europe/Helsinki');
}

/**
 * @return array{start: string, end: string, label: string, preset: string}
 */
function fixarivan_finance_parse_period(array $get): array
{
    $tz = fixarivan_finance_tz();
    $today = new DateTimeImmutable('today', $tz);
    $preset = strtolower(trim((string)($get['preset'] ?? '')));
    $fromIn = trim((string)($get['from'] ?? ''));
    $toIn = trim((string)($get['to'] ?? ''));

    if ($fromIn !== '' && $toIn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIn) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn)) {
        $from = new DateTimeImmutable($fromIn, $tz);
        $to = new DateTimeImmutable($toIn, $tz);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [
            'start' => $from->format('Y-m-d'),
            'end' => $to->format('Y-m-d'),
            'label' => $from->format('Y-m-d') . ' — ' . $to->format('Y-m-d'),
            'preset' => 'custom',
        ];
    }

    $map = [
        'today' => [0, 0],
        '7d' => [6, 0],
        'week' => [6, 0],
        'month' => null,
        'quarter' => null,
        'year' => null,
    ];

    if ($preset === 'month') {
        $start = $today->modify('first day of this month');
        $end = $today->modify('last day of this month');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'label' => $start->format('Y-m') . ' (месяц)',
            'preset' => 'month',
        ];
    }
    if ($preset === 'quarter') {
        $m = (int)$today->format('n');
        $qStartMonth = (int)(floor(($m - 1) / 3) * 3 + 1);
        $start = $today->setDate((int)$today->format('Y'), $qStartMonth, 1);
        $end = $start->modify('+2 months')->modify('last day of this month');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'label' => $start->format('Y') . ' Q' . (string)(int)ceil($m / 3),
            'preset' => 'quarter',
        ];
    }
    if ($preset === 'year') {
        $y = (int)$today->format('Y');
        $start = new DateTimeImmutable($y . '-01-01', $tz);
        $end = new DateTimeImmutable($y . '-12-31', $tz);

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'label' => (string)$y,
            'preset' => 'year',
        ];
    }
    if ($preset === '7d' || $preset === 'week') {
        $start = $today->modify('-6 days');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'label' => '7 дней',
            'preset' => $preset === 'week' ? 'week' : '7d',
        ];
    }

    // default: today
    return [
        'start' => $today->format('Y-m-d'),
        'end' => $today->format('Y-m-d'),
        'label' => 'Сегодня',
        'preset' => $preset === 'today' ? 'today' : 'today',
    ];
}

/** Дата Y-m-d для строки SQLite (ISO или только дата). */
function fixarivan_finance_sql_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Число из человекочитаемой суммы/диапазона:
 * "55 €" -> 55
 * "80-120 €" / "80–120 €" -> 80
 */
function fixarivan_finance_parse_money_like($raw): ?float
{
    if ($raw === null) {
        return null;
    }
    $s = trim((string)$raw);
    if ($s === '') {
        return null;
    }
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    if (is_numeric(str_replace(',', '.', $s))) {
        return (float)str_replace(',', '.', $s);
    }
    if (preg_match('/(\d+(?:[.,]\d+)?)/u', $s, $m)) {
        return (float)str_replace(',', '.', (string)$m[1]);
    }

    return null;
}

/**
 * Кассовая дата квитанции только из явного payment_date (без fallback на дату приёма/создания).
 * Без даты оплаты квитанция не попадает в кассовый период.
 */
function fixarivan_finance_receipt_cash_date(array $row): string
{
    $ps = strtolower(trim((string)($row['payment_status'] ?? '')));
    if (!in_array($ps, ['paid', 'partial', 'partially_paid'], true)) {
        return '';
    }

    return fixarivan_finance_sql_date((string)($row['payment_date'] ?? ''));
}

/**
 * Сумма к доходу по квитанции: при partial — только amount_paid (если не задано — 0, не вся сумма);
 * при paid — total_amount.
 */
function fixarivan_finance_receipt_revenue_amount(array $row): float
{
    $ps = strtolower(trim((string)($row['payment_status'] ?? '')));
    $total = (float)($row['total_amount'] ?? 0);
    if ($ps === 'partial' || $ps === 'partially_paid') {
        $ap = $row['amount_paid'] ?? null;
        if ($ap === null || $ap === '') {
            return 0.0;
        }
        $v = (float)$ap;

        return min($total, max(0.0, $v));
    }
    if ($ps === 'paid') {
        return $total;
    }

    return 0.0;
}

function fixarivan_finance_receipt_is_paid(array $row): bool
{
    $ps = strtolower(trim((string)($row['payment_status'] ?? '')));

    return in_array($ps, ['paid', 'partial', 'partially_paid'], true);
}

function fixarivan_finance_receipt_is_unpaid(array $row): bool
{
    $ps = strtolower(trim((string)($row['payment_status'] ?? '')));

    return in_array($ps, ['unpaid', 'pending', ''], true) || $ps === '';
}

/**
 * Дата оплаты счёта для кассового периода: в приоритете payment_date.
 * Если статус paid, а дата не заполнена (старые записи / импорт), берём дату из date_updated —
 * иначе оплаченный счёт не попадает в доход и «пропадает» из финансов.
 */
function fixarivan_finance_invoice_paid_date(array $row): string
{
    $st = strtolower(trim((string)($row['status'] ?? '')));
    if ($st !== 'paid') {
        return '';
    }

    $pd = fixarivan_finance_sql_date((string)($row['payment_date'] ?? ''));
    if ($pd !== '') {
        return $pd;
    }

    $fb = (string)($row['date_updated'] ?? $row['date_created'] ?? '');
    $fb = fixarivan_finance_sql_date($fb);

    return $fb;
}

function fixarivan_finance_invoice_is_overdue(array $row, string $todayYmd): bool
{
    $st = strtolower(trim((string)($row['status'] ?? '')));
    if (in_array($st, ['paid', 'draft', 'cancelled'], true)) {
        return false;
    }
    $due = fixarivan_finance_sql_date((string)($row['due_date'] ?? ''));

    return $due !== '' && $due < $todayYmd;
}

/** order_id → client_id для связи квитанций без client_id. */
function fixarivan_finance_order_client_map(PDO $pdo): array
{
    $m = [];
    $stmt = $pdo->query('SELECT order_id, client_id FROM orders WHERE order_id IS NOT NULL AND trim(order_id) != \'\'');
    if (!$stmt) {
        return $m;
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $m[trim((string)($row['order_id'] ?? ''))] = (int)($row['client_id'] ?? 0);
    }

    return $m;
}

/**
 * Закупка/продажа по строкам order_lines_json (если есть именованные строки).
 *
 * @return array{from_lines: bool, purchase: float, sale: float}
 */
function fixarivan_finance_order_lines_purchase_sale(array $orderRow): array
{
    $json = (string)($orderRow['order_lines_json'] ?? '[]');
    $lines = json_decode($json, true);
    if (!is_array($lines) || $lines === []) {
        return ['from_lines' => false, 'purchase' => 0.0, 'sale' => 0.0];
    }
    $purchase = 0.0;
    $sale = 0.0;
    $any = false;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $name = trim((string)($ln['name'] ?? $ln['title'] ?? ''));
        if ($name === '') {
            continue;
        }
        $any = true;
        $q = fixarivan_track_parse_decimal($ln['qty'] ?? $ln['quantity'] ?? 1);
        if ($q <= 0) {
            $q = 1.0;
        }
        $pp = fixarivan_track_parse_decimal($ln['purchase'] ?? $ln['purchase_price'] ?? $ln['cost'] ?? 0);
        $sp = fixarivan_track_parse_decimal($ln['sale'] ?? $ln['sale_price'] ?? $ln['price'] ?? 0);
        $purchase += $pp * $q;
        $sale += $sp * $q;
    }

    return ['from_lines' => $any, 'purchase' => $purchase, 'sale' => $sale];
}

/**
 * Закупка по конкретному заказу: строки JSON приоритетнее агрегатов.
 */
function fixarivan_finance_order_purchase_amount(array $orderRow): float
{
    $lineTot = fixarivan_finance_order_lines_purchase_sale($orderRow);
    if ($lineTot['from_lines']) {
        return (float)$lineTot['purchase'];
    }
    $pp = $orderRow['parts_purchase_total'] ?? null;
    if ($pp !== null && $pp !== '') {
        return (float)$pp;
    }

    return 0.0;
}

function fixarivan_finance_order_status_code(array $orderRow): string
{
    $candidates = [
        $orderRow['public_status'] ?? null,
        $orderRow['order_status'] ?? null,
        $orderRow['status'] ?? null,
    ];
    foreach ($candidates as $value) {
        $s = strtolower(trim((string)$value));
        if ($s !== '') {
            return $s;
        }
    }

    return '';
}

function fixarivan_finance_row_matches_client(array $row, ?string $clientId, array $orderClientMap): bool
{
    if ($clientId === null || $clientId === '') {
        return true;
    }
    $want = (int)$clientId;
    if (isset($row['client_id']) && (int)$row['client_id'] === $want) {
        return true;
    }
    $oid = trim((string)($row['order_id'] ?? ''));
    if ($oid !== '' && isset($orderClientMap[$oid]) && (int)$orderClientMap[$oid] === $want) {
        return true;
    }

    return false;
}

/**
 * @return array<string,mixed>
 */
function fixarivan_finance_overview(PDO $pdo, string $start, string $end, ?string $clientId = null): array
{
    $tz = fixarivan_finance_tz();
    $todayYmd = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $orderClientMap = fixarivan_finance_order_client_map($pdo);

    // --- Receipts (касса по дате оплаты / fallback date) — выборка в PHP для прозрачности
    $rStmt = $pdo->query('SELECT * FROM receipts');
    $receiptRows = $rStmt ? $rStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $paidOrderIdsInPeriod = [];

    $recPaidInPeriod = 0.0;
    $recUnpaidSum = 0.0;
    $recPartialSum = 0.0;
    $recPaidCount = 0;
    $recUnpaidCount = 0;
    $receiptPaidOrderIdsInPeriod = [];
    $invoicePaidOrderIdsInPeriod = [];
    $paymentMethods = [];

    foreach ($receiptRows as $r) {
        if (!fixarivan_finance_row_matches_client($r, $clientId, $orderClientMap)) {
            continue;
        }
        $amt = (float)($r['total_amount'] ?? 0);
        if (fixarivan_finance_receipt_is_paid($r)) {
            $cashD = fixarivan_finance_receipt_cash_date($r);
            $rev = fixarivan_finance_receipt_revenue_amount($r);
            if ($cashD !== '' && $cashD >= $start && $cashD <= $end && $rev > 0) {
                $recPaidInPeriod += $rev;
                $recPaidCount++;
                $oid = trim((string)($r['order_id'] ?? ''));
                if ($oid !== '') {
                    $paidOrderIdsInPeriod[$oid] = true;
                    $receiptPaidOrderIdsInPeriod[$oid] = ($receiptPaidOrderIdsInPeriod[$oid] ?? 0.0) + $rev;
                }
                $pm = strtolower(trim((string)($r['payment_method'] ?? 'other')));
                if ($pm === '') {
                    $pm = 'other';
                }
                if (!isset($paymentMethods[$pm])) {
                    $paymentMethods[$pm] = ['method' => $pm, 'count' => 0, 'sum' => 0.0];
                }
                $paymentMethods[$pm]['count']++;
                $paymentMethods[$pm]['sum'] += $rev;
            }
            $ps = strtolower(trim((string)($r['payment_status'] ?? '')));
            if (($ps === 'partial' || $ps === 'partially_paid') && $cashD !== '' && $cashD >= $start && $cashD <= $end) {
                $recPartialSum += $rev;
            }
        } elseif (strtolower(trim((string)($r['payment_status'] ?? ''))) === 'cancelled') {
            continue;
        } else {
            $recUnpaidSum += $amt;
            $recUnpaidCount++;
        }
    }

    // --- Invoices
    $iStmt = $pdo->query('SELECT * FROM invoices');
    $invRows = $iStmt ? $iStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $invPaidInPeriod = 0.0;
    $invPaidCount = 0;
    $invNonDraftCount = 0;
    $invOverdueSum = 0.0;
    $invOverdueCount = 0;
    $invUnpaidSum = 0.0;

    foreach ($invRows as $inv) {
        if (!fixarivan_finance_row_matches_client($inv, $clientId, $orderClientMap)) {
            continue;
        }
        $st = strtolower(trim((string)($inv['status'] ?? '')));
        if (in_array($st, ['draft', 'cancelled'], true)) {
            continue;
        }
        $amt = (float)($inv['total_amount'] ?? 0);
        $invNonDraftCount++;

        if ($st === 'paid') {
            $pd = fixarivan_finance_invoice_paid_date($inv);
            if ($pd !== '' && $pd >= $start && $pd <= $end) {
                $invPaidInPeriod += $amt;
                $invPaidCount++;
                $oid = trim((string)($inv['order_id'] ?? ''));
                if ($oid !== '') {
                    $paidOrderIdsInPeriod[$oid] = true;
                    $invoicePaidOrderIdsInPeriod[$oid] = ($invoicePaidOrderIdsInPeriod[$oid] ?? 0.0) + $amt;
                }
            }
        } else {
            $invUnpaidSum += $amt;
            if (fixarivan_finance_invoice_is_overdue($inv, $todayYmd)) {
                $invOverdueSum += $amt;
                $invOverdueCount++;
            }
        }
    }

    // --- Orders (счётчики + запчасти по дате приёма)
    $oStmt = $pdo->query('SELECT * FROM orders');
    $orderRows = $oStmt ? $oStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $ordersById = [];
    foreach ($orderRows as $o) {
        $oid = trim((string)($o['order_id'] ?? ''));
        if ($oid !== '') {
            $ordersById[$oid] = $o;
        }
    }

    $ordersInPeriod = 0;
    $ordersDone = 0;
    $ordersActive = 0;
    $partsPurchase = 0.0;
    $partsSale = 0.0;
    $ordersLaborEst = 0.0;

    foreach ($orderRows as $o) {
        if (!fixarivan_finance_row_matches_client($o, $clientId, $orderClientMap)) {
            continue;
        }
        $d = fixarivan_finance_sql_date((string)($o['date_of_acceptance'] ?? $o['work_date'] ?? $o['date_created'] ?? ''));
        if ($d === '' || $d < $start || $d > $end) {
            continue;
        }
        $ordersInPeriod++;
        $st = fixarivan_finance_order_status_code($o);
        if (in_array($st, ['completed', 'done', 'closed', 'signed'], true)) {
            $ordersDone++;
        } elseif (in_array($st, ['delivered'], true)) {
            $ordersDone++;
        } else {
            $ordersActive++;
        }
        $lineTot = fixarivan_finance_order_lines_purchase_sale($o);
        if ($lineTot['from_lines']) {
            $partsPurchase += $lineTot['purchase'];
            $partsSale += $lineTot['sale'];
        } else {
            $pp = $o['parts_purchase_total'] ?? null;
            $ps = $o['parts_sale_total'] ?? null;
            if ($pp !== null && $pp !== '') {
                $partsPurchase += (float)$pp;
            }
            if ($ps !== null && $ps !== '') {
                $partsSale += (float)$ps;
            }
        }
        $lab = null;
        if (isset($o['estimated_labor_cost']) && $o['estimated_labor_cost'] !== null && $o['estimated_labor_cost'] !== '') {
            $lab = (float)$o['estimated_labor_cost'];
        } elseif (isset($o['public_estimated_cost']) && trim((string)$o['public_estimated_cost']) !== '') {
            $lab = fixarivan_finance_parse_money_like($o['public_estimated_cost']);
        }
        if ($lab !== null) {
            $ordersLaborEst += $lab;
        }
    }

    $partsProfit = $partsSale - $partsPurchase;
    $revenueManagement = $recPaidInPeriod + $invPaidInPeriod;
    $expenseTaxParts = 0.0;
    foreach (array_keys($paidOrderIdsInPeriod) as $oid) {
        if (!isset($ordersById[$oid]) || !is_array($ordersById[$oid])) {
            continue;
        }
        if (!fixarivan_finance_row_matches_client($ordersById[$oid], $clientId, $orderClientMap)) {
            continue;
        }
        $expenseTaxParts += fixarivan_finance_order_purchase_amount($ordersById[$oid]);
    }
    $profitTaxApprox = $revenueManagement - $expenseTaxParts;
    $marginPctCash = $revenueManagement > 0 ? round(($profitTaxApprox / $revenueManagement) * 100, 1) : 0.0;

    $duplicateOrderIds = [];
    $duplicateInvoiceSum = 0.0;
    foreach ($invoicePaidOrderIdsInPeriod as $oid => $invSum) {
        if (isset($receiptPaidOrderIdsInPeriod[$oid])) {
            $duplicateOrderIds[] = $oid;
            $duplicateInvoiceSum += (float)$invSum;
        }
    }

    $paymentMethodsList = array_values($paymentMethods);
    usort($paymentMethodsList, static function ($a, $b): int {
        return ($b['sum'] <=> $a['sum']);
    });
    foreach ($paymentMethodsList as &$pmRow) {
        $pmRow['sum'] = round((float)$pmRow['sum'], 2);
    }
    unset($pmRow);

    return [
        'period' => ['start' => $start, 'end' => $end],
        'revenue' => [
            'receipts_cash' => round($recPaidInPeriod, 2),
            'invoices_paid_cash' => round($invPaidInPeriod, 2),
            'total' => round($revenueManagement, 2),
        ],
        'payment_receipts' => [
            'paid_in_period_count' => $recPaidCount,
            'paid_in_period_sum' => round($recPaidInPeriod, 2),
            'unpaid_count' => $recUnpaidCount,
            'unpaid_sum' => round($recUnpaidSum, 2),
            'partial_sum' => round($recPartialSum, 2),
        ],
        'invoices' => [
            'documents_count' => $invNonDraftCount,
            'paid_in_period_count' => $invPaidCount,
            'paid_in_period_sum' => round($invPaidInPeriod, 2),
            'unpaid_open_sum' => round($invUnpaidSum, 2),
            'overdue_count' => $invOverdueCount,
            'overdue_sum' => round($invOverdueSum, 2),
        ],
        'orders' => [
            'in_period' => $ordersInPeriod,
            'completed' => $ordersDone,
            'in_progress' => $ordersActive,
            'estimated_labor_sum' => round($ordersLaborEst, 2),
        ],
        'parts' => [
            'purchase' => round($partsPurchase, 2),
            'sale' => round($partsSale, 2),
            'profit' => round($partsProfit, 2),
        ],
        'summary' => [
            'income' => round($revenueManagement, 2),
            'expense_parts_manual' => round($expenseTaxParts, 2),
            'approx_profit' => round($revenueManagement - $expenseTaxParts, 2),
            'margin_pct_cash' => $marginPctCash,
            'orders_income_estimate' => round($partsSale + $ordersLaborEst, 2),
            'orders_profit_estimate' => round($partsSale + $ordersLaborEst - $partsPurchase, 2),
        ],
        'payment_methods' => $paymentMethodsList,
        'warnings' => [
            'duplicate_order_count' => count($duplicateOrderIds),
            'duplicate_invoice_sum' => round($duplicateInvoiceSum, 2),
            'duplicate_order_ids' => array_slice($duplicateOrderIds, 0, 25),
            'duplicate_hint' => count($duplicateOrderIds) > 0
                ? 'У этих заказов в периоде есть и оплаченная квитанция, и оплаченный счёт — сумма счетов может дублировать кассу.'
                : '',
        ],
        'tax' => [
            'receipts_paid_total' => round($recPaidInPeriod, 2),
            'invoices_paid_total' => round($invPaidInPeriod, 2),
            'total_income' => round($revenueManagement, 2),
            'expense_parts_orders' => round($expenseTaxParts, 2),
            'approx_profit' => round($profitTaxApprox, 2),
        ],
        'notes' => [
            'revenue_basis' => 'Касса: квитанции — по payment_date при оплате; счёт paid — по payment_date, если пусто — по дате обновления записи (чтобы старые оплаченные счета не терялись). Частичная оплата квитанции: доход = amount_paid.',
            'expense_basis' => 'Для cash summary/tax расход по запчастям считается по заказам, у которых есть оплаченные квитанции/счета в периоде. Для блока «Запчасти по заказам» расход/продажа считаются по заказам с датой приёма в периоде.',
            'parts_sync' => 'Если в заказе заполнены позиции (order_lines_json), закупка/продажа и маржа по запчастям считаются по строкам (purchase/sale × qty); иначе — по полям parts_purchase_total / parts_sale_total.',
            'labor_orders' => 'Сумма оценки работ по заказам за период: orders.estimated_labor_cost (число); для старых строк без колонки — берётся первое число из public_estimated_cost.',
            'orders_income_estimate' => 'По заказам за период (не касса): продажа позиций из строк + estimated_labor; прибыль = это минус закупка по строкам/агрегатам.',
        ],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function fixarivan_finance_drilldown(PDO $pdo, string $kind, string $start, string $end, ?string $clientId = null): array
{
    $tz = fixarivan_finance_tz();
    $todayYmd = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $orderClientMap = fixarivan_finance_order_client_map($pdo);

    $matchClient = static function (array $row) use ($clientId, $orderClientMap): bool {
        return fixarivan_finance_row_matches_client($row, $clientId, $orderClientMap);
    };

    if ($kind === 'receipts_paid') {
        $out = [];
        $stmt = $pdo->query('SELECT * FROM receipts');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            if (!$matchClient($r) || !fixarivan_finance_receipt_is_paid($r)) {
                continue;
            }
            $cashD = fixarivan_finance_receipt_cash_date($r);
            if ($cashD === '' || $cashD < $start || $cashD > $end) {
                continue;
            }
            $r['_cash_date'] = $cashD;
            $r['_revenue_amount'] = round(fixarivan_finance_receipt_revenue_amount($r), 2);
            $out[] = $r;
        }

        return $out;
    }

    if ($kind === 'receipts_unpaid') {
        $out = [];
        $stmt = $pdo->query('SELECT * FROM receipts');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            if (!$matchClient($r)) {
                continue;
            }
            if (strtolower(trim((string)($r['payment_status'] ?? ''))) === 'cancelled') {
                continue;
            }
            if (fixarivan_finance_receipt_is_paid($r)) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    if ($kind === 'invoices_paid') {
        $out = [];
        $stmt = $pdo->query('SELECT * FROM invoices');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $inv) {
            if (!$matchClient($inv)) {
                continue;
            }
            if (strtolower(trim((string)($inv['status'] ?? ''))) !== 'paid') {
                continue;
            }
            $pd = fixarivan_finance_invoice_paid_date($inv);
            if ($pd === '' || $pd < $start || $pd > $end) {
                continue;
            }
            $inv['_paid_date'] = $pd;
            $inv['_revenue_amount'] = round((float)($inv['total_amount'] ?? 0), 2);
            $out[] = $inv;
        }

        return $out;
    }

    if ($kind === 'invoices_unpaid') {
        $out = [];
        $stmt = $pdo->query('SELECT * FROM invoices');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $inv) {
            if (!$matchClient($inv)) {
                continue;
            }
            $st = strtolower(trim((string)($inv['status'] ?? '')));
            if (in_array($st, ['paid', 'draft', 'cancelled'], true)) {
                continue;
            }
            $inv['_is_overdue'] = fixarivan_finance_invoice_is_overdue($inv, $todayYmd);
            $out[] = $inv;
        }

        return $out;
    }

    if ($kind === 'invoices_overdue') {
        $out = [];
        foreach (fixarivan_finance_drilldown($pdo, 'invoices_unpaid', $start, $end, $clientId) as $inv) {
            if (!empty($inv['_is_overdue'])) {
                unset($inv['_is_overdue']);
                $out[] = $inv;
            }
        }

        return $out;
    }

    if ($kind === 'orders_period') {
        $out = [];
        $stmt = $pdo->query('SELECT * FROM orders');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $o) {
            if (!$matchClient($o)) {
                continue;
            }
            $d = fixarivan_finance_sql_date((string)($o['date_of_acceptance'] ?? $o['work_date'] ?? $o['date_created'] ?? ''));
            if ($d === '' || $d < $start || $d > $end) {
                continue;
            }
            $o['_period_date'] = $d;
            $out[] = $o;
        }

        return $out;
    }

    return [];
}

/**
 * TZ P2 блок 10: читаемые колонки CSV по заказам (не сырой SELECT *).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,string>>
 */
function fixarivan_finance_orders_csv_flatten(array $rows): array
{
    $out = [];
    foreach ($rows as $o) {
        if (!is_array($o)) {
            continue;
        }
        $lines = json_decode((string) ($o['order_lines_json'] ?? '[]'), true);
        $lineCount = is_array($lines) ? count($lines) : 0;
        $saleSum = fixarivan_orders_estimate_from_lines_json(isset($o['order_lines_json']) ? (string) $o['order_lines_json'] : null);

        $out[] = [
            'document_id' => (string) ($o['document_id'] ?? ''),
            'order_id' => (string) ($o['order_id'] ?? ''),
            'client_name' => (string) ($o['client_name'] ?? ''),
            'client_phone' => (string) ($o['client_phone'] ?? ''),
            'client_email' => (string) ($o['client_email'] ?? ''),
            'device_model' => (string) ($o['device_model'] ?? ''),
            'public_status' => (string) ($o['public_status'] ?? ''),
            'order_status' => (string) ($o['order_status'] ?? ''),
            'parts_status' => (string) ($o['parts_status'] ?? ''),
            'date_of_acceptance' => (string) ($o['date_of_acceptance'] ?? ''),
            'public_expected_date' => (string) ($o['public_expected_date'] ?? ''),
            'date_created' => (string) ($o['date_created'] ?? ''),
            'date_updated' => (string) ($o['date_updated'] ?? ''),
            'period_date' => (string) ($o['_period_date'] ?? ''),
            'estimated_labor_cost' => (string) ($o['estimated_labor_cost'] ?? ''),
            'parts_purchase_total' => (string) ($o['parts_purchase_total'] ?? ''),
            'parts_sale_total' => (string) ($o['parts_sale_total'] ?? ''),
            'order_lines_count' => (string) $lineCount,
            'order_lines_sale_sum' => $saleSum !== null ? (string) $saleSum : '',
        ];
    }

    return $out;
}

function fixarivan_finance_csv_escape(string $v): string
{
    $v = str_replace(["\r\n", "\r", "\n"], ' ', $v);
    if (str_contains($v, '"') || str_contains($v, ';') || str_contains($v, ',')) {
        return '"' . str_replace('"', '""', $v) . '"';
    }

    return $v;
}

function fixarivan_finance_export_csv(string $kind, string $start, string $end, array $rows): string
{
    $lines = [];
    $lines[] = '# FixariVan export ' . $kind . ' ' . $start . '..' . $end;
    if ($rows === []) {
        $lines[] = 'empty';

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }
    $first = $rows[0];
    $headers = array_keys($first);
    $lines[] = implode(';', array_map('fixarivan_finance_csv_escape', $headers));
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $v = $row[$h] ?? '';
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $line[] = fixarivan_finance_csv_escape((string)$v);
        }
        $lines[] = implode(';', $line);
    }

    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

/**
 * Плоская таблица для Excel: метрика / значение / валюта.
 *
 * @param array<string,mixed> $overview
 * @return list<array<string,string>>
 */
function fixarivan_finance_management_csv_rows(string $start, string $end, array $overview): array
{
    $r = $overview['revenue'] ?? [];
    $pr = $overview['payment_receipts'] ?? [];
    $inv = $overview['invoices'] ?? [];
    $ord = $overview['orders'] ?? [];
    $parts = $overview['parts'] ?? [];
    $sum = $overview['summary'] ?? [];
    $tax = $overview['tax'] ?? [];

    return [
        ['metric' => 'period_start', 'value' => $start, 'unit' => 'date'],
        ['metric' => 'period_end', 'value' => $end, 'unit' => 'date'],
        ['metric' => 'revenue_receipts_cash', 'value' => (string)($r['receipts_cash'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'revenue_invoices_paid', 'value' => (string)($r['invoices_paid_cash'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'revenue_total_cash', 'value' => (string)($r['total'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'parts_purchase', 'value' => (string)($parts['purchase'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'parts_sale', 'value' => (string)($parts['sale'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'parts_margin_gross', 'value' => (string)($parts['profit'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'summary_income', 'value' => (string)($sum['income'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'summary_expense_parts', 'value' => (string)($sum['expense_parts_manual'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'summary_approx_profit', 'value' => (string)($sum['approx_profit'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'summary_margin_pct_cash', 'value' => (string)($sum['margin_pct_cash'] ?? ''), 'unit' => 'percent'],
        ['metric' => 'warnings_duplicate_invoice_sum', 'value' => (string)($overview['warnings']['duplicate_invoice_sum'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'tax_total_income', 'value' => (string)($tax['total_income'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'tax_expense_parts', 'value' => (string)($tax['expense_parts_orders'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'tax_approx_profit', 'value' => (string)($tax['approx_profit'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'receipts_unpaid_sum', 'value' => (string)($pr['unpaid_sum'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'invoices_unpaid_sum', 'value' => (string)($inv['unpaid_open_sum'] ?? ''), 'unit' => 'EUR'],
        ['metric' => 'orders_in_period', 'value' => (string)($ord['in_period'] ?? ''), 'unit' => 'count'],
    ];
}

/**
 * Динамика кассового дохода и прибыли по периоду (для графика на finance.html).
 *
 * @return array{labels: list<string>, revenue: list<float>, profit: list<float>}
 */
function fixarivan_finance_cash_series(PDO $pdo, string $start, string $end): array
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
            $buckets[] = [
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
