<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/finance_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Only GET'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'overview')));
$get = $_GET;
$period = fixarivan_finance_parse_period($get);
$start = $period['start'];
$end = $period['end'];
$clientId = trim((string)($get['client_id'] ?? ''));

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'overview') {
    header('Content-Type: application/json; charset=utf-8');
    $overview = fixarivan_finance_overview($pdo, $start, $end, $clientId !== '' ? $clientId : null);
    echo json_encode([
        'success' => true,
        'period_meta' => $period,
        'data' => $overview,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'drilldown') {
    header('Content-Type: application/json; charset=utf-8');
    $kind = trim((string)($get['kind'] ?? ''));
    $allowed = ['receipts_paid', 'receipts_unpaid', 'invoices_paid', 'invoices_unpaid', 'invoices_overdue', 'orders_period'];
    if (!in_array($kind, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Unknown kind', 'errors' => [$allowed]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = fixarivan_finance_drilldown($pdo, $kind, $start, $end, $clientId !== '' ? $clientId : null);
    echo json_encode([
        'success' => true,
        'period_meta' => $period,
        'kind' => $kind,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'export') {
    $kind = trim((string)($get['kind'] ?? 'overview'));
    $format = strtolower(trim((string)($get['format'] ?? 'csv')));
    if ($format !== 'csv' && $format !== 'json') {
        $format = 'csv';
    }

    if ($kind === 'management' || $kind === 'tax' || $kind === 'overview') {
        $dataFull = fixarivan_finance_overview($pdo, $start, $end, $clientId !== '' ? $clientId : null);
        $data = $dataFull;
        if ($kind === 'tax') {
            $data = ['tax' => $dataFull['tax'] ?? [], 'period' => $dataFull['period'] ?? [], 'notes' => $dataFull['notes'] ?? []];
        }
        $payload = json_encode(
            ['period' => $period, 'exported_at' => date('c'), 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="fixarivan-finance-' . $kind . '-' . $start . '-' . $end . '.json"');
            echo $payload !== false ? $payload : '{}';
            exit;
        }
        $flat = fixarivan_finance_management_csv_rows($start, $end, $dataFull);
        $csv = fixarivan_finance_export_csv($kind, $start, $end, $flat);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fixarivan-finance-' . $kind . '-' . $start . '-' . $end . '.csv"');
        echo $csv;
        exit;
    }

    $map = [
        'receipts' => 'receipts_paid',
        'receipts_unpaid' => 'receipts_unpaid',
        'invoices_paid' => 'invoices_paid',
        'invoices_unpaid' => 'invoices_unpaid',
        'invoices' => 'invoices_unpaid',
        'orders' => 'orders_period',
    ];
    $ddKind = $map[$kind] ?? $kind;
    $allowed = ['receipts_paid', 'receipts_unpaid', 'invoices_paid', 'invoices_unpaid', 'invoices_overdue', 'orders_period'];
    if (!in_array($ddKind, $allowed, true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unknown export kind'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = fixarivan_finance_drilldown($pdo, $ddKind, $start, $end, $clientId !== '' ? $clientId : null);
    if ($ddKind === 'orders_period') {
        $rows = fixarivan_finance_orders_csv_flatten($rows);
    }
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="fixarivan-' . $ddKind . '-' . $start . '-' . $end . '.json"');
        echo json_encode(['period' => $period, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    $csv = fixarivan_finance_export_csv($ddKind, $start, $end, $rows);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fixarivan-' . $ddKind . '-' . $start . '-' . $end . '.csv"');
    echo $csv;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'Unknown action', 'errors' => ['overview', 'drilldown', 'export']], JSON_UNESCAPED_UNICODE);
