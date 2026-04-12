<?php
declare(strict_types=1);

/**
 * Backfill для order-center:
 * - orders.order_id/client_id
 * - receipts.order_id
 * - invoices.order_id
 * - mobile_reports.order_id
 *
 * Режимы:
 * - dry-run (по умолчанию): ?apply=0
 * - применение: ?apply=1
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_center.php';
require_once __DIR__ . '/lib/api_response.php';
require_once __DIR__ . '/lib/require_admin_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', []);
    exit;
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5000;
if ($limit < 1) $limit = 1;
if ($limit > 50000) $limit = 50000;

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    api_json_send(false, null, 'SQLite: ' . $e->getMessage(), []);
    exit;
}

$stats = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'orders_scanned' => 0,
    'orders_order_id_set' => 0,
    'orders_client_id_set' => 0,
    'receipts_scanned' => 0,
    'receipts_order_id_set' => 0,
    'invoices_scanned' => 0,
    'invoices_order_id_set' => 0,
    'reports_scanned' => 0,
    'reports_order_id_set' => 0,
];

try {
    if ($apply) {
        $pdo->beginTransaction();
    }

    $orders = $pdo->query(
        'SELECT id, document_id, order_id, client_id, client_name, client_phone, client_email
         FROM orders
         ORDER BY id ASC
         LIMIT ' . (int)$limit
    )->fetchAll(PDO::FETCH_ASSOC);
    $stats['orders_scanned'] = count($orders);

    $updOrder = $pdo->prepare('UPDATE orders SET order_id = :oid, client_id = :cid WHERE id = :id');
    foreach ($orders as $row) {
        $id = (int)($row['id'] ?? 0);
        $docId = trim((string)($row['document_id'] ?? ''));
        if ($id <= 0 || $docId === '') {
            continue;
        }

        $newOrderId = trim((string)($row['order_id'] ?? ''));
        if ($newOrderId === '') {
            $newOrderId = $docId; // безопасный fallback для старых актов
            $stats['orders_order_id_set']++;
        }

        $existingClientId = isset($row['client_id']) ? (int)$row['client_id'] : 0;
        $newClientId = $existingClientId > 0 ? $existingClientId : null;
        if ($newClientId === null) {
            $resolved = fixarivan_ensure_client(
                $pdo,
                (string)($row['client_name'] ?? ''),
                (string)($row['client_phone'] ?? ''),
                (string)($row['client_email'] ?? '')
            );
            if ($resolved !== null) {
                $newClientId = $resolved;
                $stats['orders_client_id_set']++;
            }
        }

        if ($apply) {
            $updOrder->execute([
                ':oid' => $newOrderId !== '' ? $newOrderId : null,
                ':cid' => $newClientId,
                ':id' => $id,
            ]);
        }
    }

    $ordersMap = [];
    $rows = $pdo->query('SELECT order_id, client_name, client_phone, client_email FROM orders')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $oid = trim((string)($r['order_id'] ?? ''));
        if ($oid === '') continue;
        $phone = fixarivan_normalize_phone((string)($r['client_phone'] ?? ''));
        $email = fixarivan_safe_lower((string)($r['client_email'] ?? ''));
        $name = trim((string)($r['client_name'] ?? ''));
        if ($phone !== '' && !isset($ordersMap['p:' . $phone])) $ordersMap['p:' . $phone] = $oid;
        if ($email !== '' && !isset($ordersMap['e:' . $email])) $ordersMap['e:' . $email] = $oid;
        if ($name !== '' && !isset($ordersMap['n:' . $name])) $ordersMap['n:' . $name] = $oid;
    }

    $receipts = $pdo->query(
        'SELECT id, document_id, order_id, client_name, client_phone, client_email
         FROM receipts
         ORDER BY id ASC
         LIMIT ' . (int)$limit
    )->fetchAll(PDO::FETCH_ASSOC);
    $stats['receipts_scanned'] = count($receipts);

    $updReceipt = $pdo->prepare('UPDATE receipts SET order_id = :oid WHERE id = :id');
    foreach ($receipts as $row) {
        $id = (int)($row['id'] ?? 0);
        $existing = trim((string)($row['order_id'] ?? ''));
        if ($id <= 0 || $existing !== '') continue;

        $docId = trim((string)($row['document_id'] ?? ''));
        $phone = fixarivan_normalize_phone((string)($row['client_phone'] ?? ''));
        $email = fixarivan_safe_lower((string)($row['client_email'] ?? ''));
        $name = trim((string)($row['client_name'] ?? ''));

        $oid = '';
        if ($docId !== '') {
            $stmt = $pdo->prepare('SELECT order_id FROM orders WHERE document_id = :d LIMIT 1');
            $stmt->execute([':d' => $docId]);
            $oid = trim((string)$stmt->fetchColumn());
        }
        if ($oid === '' && $phone !== '') $oid = (string)($ordersMap['p:' . $phone] ?? '');
        if ($oid === '' && $email !== '') $oid = (string)($ordersMap['e:' . $email] ?? '');
        if ($oid === '' && $name !== '') $oid = (string)($ordersMap['n:' . $name] ?? '');

        if ($oid !== '') {
            $stats['receipts_order_id_set']++;
            if ($apply) {
                $updReceipt->execute([':oid' => $oid, ':id' => $id]);
            }
        }
    }

    $invoices = $pdo->query(
        'SELECT id, document_id, order_id, client_name, client_phone, client_email
         FROM invoices
         ORDER BY id ASC
         LIMIT ' . (int)$limit
    )->fetchAll(PDO::FETCH_ASSOC);
    $stats['invoices_scanned'] = count($invoices);

    $updInvoice = $pdo->prepare('UPDATE invoices SET order_id = :oid WHERE id = :id');
    foreach ($invoices as $row) {
        $id = (int)($row['id'] ?? 0);
        $existing = trim((string)($row['order_id'] ?? ''));
        if ($id <= 0 || $existing !== '') {
            continue;
        }

        $docId = trim((string)($row['document_id'] ?? ''));
        $phone = fixarivan_normalize_phone((string)($row['client_phone'] ?? ''));
        $email = fixarivan_safe_lower((string)($row['client_email'] ?? ''));
        $name = trim((string)($row['client_name'] ?? ''));

        $oid = '';
        if ($docId !== '') {
            $stmt = $pdo->prepare('SELECT order_id FROM orders WHERE document_id = :d LIMIT 1');
            $stmt->execute([':d' => $docId]);
            $oid = trim((string)$stmt->fetchColumn());
        }
        if ($oid === '' && $phone !== '') {
            $oid = (string)($ordersMap['p:' . $phone] ?? '');
        }
        if ($oid === '' && $email !== '') {
            $oid = (string)($ordersMap['e:' . $email] ?? '');
        }
        if ($oid === '' && $name !== '') {
            $oid = (string)($ordersMap['n:' . $name] ?? '');
        }

        if ($oid !== '') {
            $stats['invoices_order_id_set']++;
            if ($apply) {
                $updInvoice->execute([':oid' => $oid, ':id' => $id]);
            }
        }
    }

    $reports = $pdo->query(
        'SELECT id, report_id, order_id, client_name, phone
         FROM mobile_reports
         ORDER BY id ASC
         LIMIT ' . (int)$limit
    )->fetchAll(PDO::FETCH_ASSOC);
    $stats['reports_scanned'] = count($reports);

    $updReport = $pdo->prepare('UPDATE mobile_reports SET order_id = :oid WHERE id = :id');
    foreach ($reports as $row) {
        $id = (int)($row['id'] ?? 0);
        $existing = trim((string)($row['order_id'] ?? ''));
        if ($id <= 0 || $existing !== '') continue;

        $phone = fixarivan_normalize_phone((string)($row['phone'] ?? ''));
        $name = trim((string)($row['client_name'] ?? ''));

        $oid = '';
        if ($phone !== '') $oid = (string)($ordersMap['p:' . $phone] ?? '');
        if ($oid === '' && $name !== '') $oid = (string)($ordersMap['n:' . $name] ?? '');

        if ($oid !== '') {
            $stats['reports_order_id_set']++;
            if ($apply) {
                $updReport->execute([':oid' => $oid, ':id' => $id]);
            }
        }
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_send(false, null, 'Ошибка миграции: ' . $e->getMessage(), []);
    exit;
}

api_json_send(true, $stats, null, [], $stats);
