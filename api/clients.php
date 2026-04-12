<?php
declare(strict_types=1);

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/api_response.php';
require_once __DIR__ . '/lib/order_center.php';
require_once __DIR__ . '/lib/order_client_portal.php';
require_once __DIR__ . '/lib/security_settings.php';

function clients_norm_phone_sql(string $col): string {
    return "REPLACE(REPLACE(REPLACE(IFNULL($col, ''), '+', ''), ' ', ''), '-', '')";
}

/** @deprecated используйте fixarivan_orders_estimate_from_lines_json (order_client_portal.php) */
function clients_orders_estimate_from_lines_json(?string $json): ?float
{
    return fixarivan_orders_estimate_from_lines_json($json);
}

/**
 * Группировка квитанций, счетов и отчётов по заказу для карточки клиента.
 *
 * @param list<array<string,mixed>> $orders
 * @param list<array<string,mixed>> $receipts
 * @param list<array<string,mixed>> $invoices
 * @param list<array<string,mixed>> $reports
 * @return list<array{order: array<string,mixed>, estimate_total: ?float, receipts: list, invoices: list, reports: list}>
 */
function clients_build_orders_with_docs(array $orders, array $receipts, array $invoices, array $reports): array
{
    return fixarivan_group_orders_with_documents($orders, $receipts, $invoices, $reports);
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    api_json_send(false, null, 'SQLite: ' . $e->getMessage(), []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $clientId = trim((string)($_GET['client_id'] ?? ''));
    if ($clientId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE client_id = :cid LIMIT 1');
        $stmt->execute([':cid' => $clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            api_json_send(false, null, 'Клиент не найден', []);
            exit;
        }

        $clientRowId = (int)($client['id'] ?? 0);
        $phoneNorm = fixarivan_normalize_phone((string)($client['phone'] ?? ''));
        $emailNorm = fixarivan_safe_lower((string)($client['email'] ?? ''));

        $stmt = $pdo->prepare(
            'SELECT document_id, order_id, status, device_model, client_token, order_status, problem_description,
                    public_status, parts_status, order_lines_json,
                    COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS updated_at
             FROM orders
             WHERE client_id = :id
             ORDER BY updated_at DESC
             LIMIT 50'
        );
        $stmt->execute([':id' => $clientRowId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $activeOrders = [];
        $waitingParts = [];
        foreach ($orders as $o) {
            $status = strtolower(trim((string)($o['status'] ?? '')));
            $isDone = in_array($status, ['completed', 'cancelled', 'signed', 'done'], true);
            if (!$isDone) {
                $activeOrders[] = $o;
            }
            if (strpos($status, 'part') !== false || strpos($status, 'wait') !== false || strpos($status, 'await') !== false) {
                $waitingParts[] = $o;
            }
        }

        $orderIds = [];
        foreach ($orders as $o) {
            $oid = trim((string)($o['order_id'] ?? ''));
            if ($oid !== '') $orderIds[$oid] = true;
        }

        $receipts = [];
        $invoices = [];
        if ($orderIds !== []) {
            $in = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $pdo->prepare(
                'SELECT document_id, order_id, receipt_number, total_amount, status,
                        COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS updated_at
                 FROM receipts
                 WHERE order_id IN (' . $in . ')
                 ORDER BY updated_at DESC
                 LIMIT 50'
            );
            $stmt->execute(array_keys($orderIds));
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare(
                'SELECT document_id, order_id, invoice_id, total_amount, status,
                        COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS updated_at
                 FROM invoices
                 WHERE order_id IN (' . $in . ')
                 ORDER BY updated_at DESC
                 LIMIT 50'
            );
            $stmt->execute(array_keys($orderIds));
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($phoneNorm !== '' || $emailNorm !== '') {
            $where = [];
            $params = [];
            if ($phoneNorm !== '') {
                $where[] = clients_norm_phone_sql('client_phone') . ' = :p';
                $params[':p'] = $phoneNorm;
            }
            if ($emailNorm !== '') {
                $where[] = 'lower(IFNULL(client_email, \'\')) = :e';
                $params[':e'] = $emailNorm;
            }
            $stmt = $pdo->prepare(
                'SELECT document_id, order_id, receipt_number, total_amount, status,
                        COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS updated_at
                 FROM receipts
                 WHERE ' . implode(' OR ', $where) . '
                 ORDER BY updated_at DESC
                 LIMIT 50'
            );
            $stmt->execute($params);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $reports = [];
        if ($orderIds !== []) {
            $in = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $pdo->prepare(
                'SELECT report_id, order_id, model, device_type, created_at
                 FROM mobile_reports
                 WHERE order_id IN (' . $in . ')
                 ORDER BY created_at DESC
                 LIMIT 50'
            );
            $stmt->execute(array_keys($orderIds));
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($phoneNorm !== '') {
            $stmt = $pdo->prepare(
                'SELECT report_id, order_id, model, device_type, created_at
                 FROM mobile_reports
                 WHERE ' . clients_norm_phone_sql('phone') . ' = :p
                 ORDER BY created_at DESC
                 LIMIT 50'
            );
            $stmt->execute([':p' => $phoneNorm]);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $ordersWithDocs = clients_build_orders_with_docs($orders, $receipts, $invoices, $reports);

        api_json_send(true, [
            'client' => $client,
            'history' => [
                'orders' => $orders,
                'receipts' => $receipts,
                'invoices' => $invoices,
                'reports' => $reports,
            ],
            'orders_with_docs' => $ordersWithDocs,
            'active_orders' => $activeOrders,
            'waiting_parts' => $waitingParts,
        ], null, [], []);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if ($limit < 1) $limit = 1;
    if ($limit > 500) $limit = 500;

    if ($q === '') {
        $stmt = $pdo->query(
            'SELECT client_id, full_name, phone, email, updated_at
             FROM clients
             ORDER BY updated_at DESC
             LIMIT ' . (int)$limit
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_json_send(true, ['clients' => $rows], null, [], ['clients' => $rows]);
        exit;
    }

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.client_id, c.full_name, c.phone, c.email, c.updated_at
         FROM clients c
         LEFT JOIN orders o ON o.client_id = c.id
         WHERE c.full_name LIKE :q
            OR IFNULL(c.phone, \'\') LIKE :q
            OR IFNULL(c.email, \'\') LIKE :q
            OR c.client_id LIKE :q
            OR IFNULL(o.device_model, \'\') LIKE :q
            OR IFNULL(o.problem_description, \'\') LIKE :q
            OR IFNULL(o.order_id, \'\') LIKE :q
            OR IFNULL(o.document_id, \'\') LIKE :q
         ORDER BY c.updated_at DESC
         LIMIT ' . (int)$limit
    );
    $stmt->execute([':q' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    api_json_send(true, ['clients' => $rows], null, [], ['clients' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        api_json_send(false, null, 'Некорректный JSON', []);
        exit;
    }

    $action = trim((string)($input['action'] ?? 'save'));
    if ($action === 'delete') {
        $cid = trim((string)($input['client_id'] ?? ''));
        $deletePassword = trim((string)($input['delete_password'] ?? ''));
        if ($cid === '') {
            api_json_send(false, null, 'Нужно client_id', []);
            exit;
        }
        if (!fixarivan_verify_delete_password($deletePassword)) {
            api_json_send(false, null, 'Неверный пароль удаления', []);
            exit;
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM clients WHERE client_id = :cid LIMIT 1');
            $stmt->execute([':cid' => $cid]);
            $rowId = (int)($stmt->fetchColumn() ?: 0);
            if ($rowId <= 0) {
                $pdo->rollBack();
                api_json_send(false, null, 'Клиент не найден', []);
                exit;
            }
            $stO = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE client_id = :id');
            $stO->execute([':id' => $rowId]);
            $cntOrders = (int)$stO->fetchColumn();
            $stI = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = :id');
            $stI->execute([':id' => $rowId]);
            $cntInv = (int)$stI->fetchColumn();
            $stR = $pdo->prepare(
                'SELECT COUNT(*) FROM receipts r INNER JOIN orders o ON o.order_id = r.order_id AND IFNULL(r.order_id,\'\') <> \'\' WHERE o.client_id = :id'
            );
            $stR->execute([':id' => $rowId]);
            $cntRec = (int)$stR->fetchColumn();
            $stMr = $pdo->prepare(
                'SELECT COUNT(*) FROM mobile_reports mr INNER JOIN orders o ON o.order_id = mr.order_id AND IFNULL(mr.order_id,\'\') <> \'\' WHERE o.client_id = :id'
            );
            $stMr->execute([':id' => $rowId]);
            $cntRep = (int)$stMr->fetchColumn();
            if ($cntOrders > 0 || $cntInv > 0 || $cntRec > 0 || $cntRep > 0) {
                $pdo->rollBack();
                api_json_send(false, null, 'Нельзя удалить клиента с заказами или связанными документами.', [
                    'orders' => $cntOrders,
                    'invoices' => $cntInv,
                    'receipts_via_orders' => $cntRec,
                    'reports_via_orders' => $cntRep,
                ]);
                exit;
            }
            $pdo->prepare('DELETE FROM clients WHERE id = :id')->execute([':id' => $rowId]);
            $pdo->commit();
            api_json_send(true, ['client_id' => $cid, 'deleted' => true], 'Клиент удалён', [], ['client_id' => $cid]);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            api_json_send(false, null, 'Ошибка удаления клиента: ' . $e->getMessage(), []);
            exit;
        }
    }

    if ($action !== 'save') {
        api_json_send(false, null, 'Неизвестное action', []);
        exit;
    }

    $cid = trim((string)($input['client_id'] ?? ''));
    $name = trim((string)($input['full_name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    if ($name === '') {
        api_json_send(false, null, 'Нужно full_name', []);
        exit;
    }

    $now = date('c');
    $phoneNorm = fixarivan_normalize_phone($phone);
    $emailNorm = fixarivan_safe_lower($email);

    if ($cid !== '') {
        $stmt = $pdo->prepare('UPDATE clients SET full_name=:n, phone=:p, email=:e, notes=:no, updated_at=:u WHERE client_id=:cid');
        $stmt->execute([
            ':n' => $name,
            ':p' => $phoneNorm !== '' ? $phoneNorm : null,
            ':e' => $emailNorm !== '' ? $emailNorm : null,
            ':no' => $notes !== '' ? $notes : null,
            ':u' => $now,
            ':cid' => $cid,
        ]);
        api_json_send(true, ['client_id' => $cid, 'updated' => true], null, [], ['client_id' => $cid]);
        exit;
    }

    $newId = fixarivan_generate_client_id();
    $stmt = $pdo->prepare(
        'INSERT INTO clients (client_id, full_name, phone, email, notes, created_at, updated_at)
         VALUES (:cid, :n, :p, :e, :no, :c, :u)'
    );
    $stmt->execute([
        ':cid' => $newId,
        ':n' => $name,
        ':p' => $phoneNorm !== '' ? $phoneNorm : null,
        ':e' => $emailNorm !== '' ? $emailNorm : null,
        ':no' => $notes !== '' ? $notes : null,
        ':c' => $now,
        ':u' => $now,
    ]);
    api_json_send(true, ['client_id' => $newId, 'created' => true], null, [], ['client_id' => $newId]);
    exit;
}

api_json_send(false, null, 'Метод не поддерживается', []);
