<?php
/**
 * Поиск документов в SQLite (акты, квитанции, отчёты).
 */

declare(strict_types=1);

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$typeFilter = isset($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : 'all';
if (!in_array($typeFilter, ['all', 'order', 'receipt', 'report', 'invoice'], true)) {
    $typeFilter = 'all';
}

if ($query === '') {
    echo json_encode(['success' => false, 'message' => 'Query parameter "q" is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/sqlite.php';

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'SQLite недоступна: ' . $e->getMessage(),
        'results' => [],
        'count' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $query . '%';
$results = [];

try {
    if ($typeFilter === 'all' || $typeFilter === 'order') {
        $sql = 'SELECT document_id, order_id, client_id, client_name, client_phone, client_email, device_model, device_serial,
                problem_description, device_password, priority, status, place_of_acceptance, date_of_acceptance,
                unique_code,
                COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS date_created
            FROM orders
            WHERE client_name LIKE :q OR client_phone LIKE :q OR client_email LIKE :q OR document_id LIKE :q
               OR IFNULL(order_id, '') LIKE :q
               OR device_model LIKE :q OR device_serial LIKE :q OR problem_description LIKE :q
               OR unique_code LIKE :q
               OR IFNULL(internal_comment, '') LIKE :q
               OR IFNULL(order_lines_json, '') LIKE :q
            ORDER BY date_created DESC
            LIMIT 30';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => $like]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['type'] = 'order';
            $results[] = $row;
        }
    }

    if ($typeFilter === 'all' || $typeFilter === 'receipt') {
        $sql = 'SELECT document_id, order_id, NULL AS client_id, client_name, client_phone, client_email, IFNULL(device_model, \'\') AS device_model,
                total_amount, payment_method, payment_status, payment_date, payment_note, status,
                place_of_acceptance, date_of_acceptance, unique_code,
                COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS date_created
            FROM receipts
            WHERE client_name LIKE :q OR client_phone LIKE :q OR client_email LIKE :q OR document_id LIKE :q
               OR receipt_number LIKE :q OR services_rendered LIKE :q OR notes LIKE :q
               OR IFNULL(device_model, \'\') LIKE :q
            ORDER BY date_created DESC
            LIMIT 30';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => $like]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['type'] = 'receipt';
            $results[] = $row;
        }
    }

    if ($typeFilter === 'all' || $typeFilter === 'report') {
        $sql = 'SELECT report_id AS document_id, order_id, NULL AS client_id, client_name, phone AS client_phone, \'\' AS client_email,
                model AS device_model, diagnosis, recommendations,
                created_at AS date_created,
                IFNULL(serial_number, \'\') AS device_serial, \'\' AS problem_description, \'\' AS device_password,
                \'\' AS priority, \'completed\' AS status,
                IFNULL(master_name, \'\') AS technician_name,
                IFNULL(work_date, \'\') AS work_date,
                \'\' AS place_of_acceptance,
                \'\' AS date_of_acceptance, report_id AS unique_code,
                COALESCE(
                    NULLIF(TRIM(json_extract(raw_json, \'$.repairCost\')), \'\'),
                    NULLIF(TRIM(json_extract(raw_json, \'$.estimatedCost\')), \'\'),
                    NULLIF(TRIM(json_extract(raw_json, \'$.repair_cost\')), \'\')
                ) AS repair_cost,
                COALESCE(
                    NULLIF(TRIM(json_extract(raw_json, \'$.repairTime\')), \'\'),
                    NULLIF(TRIM(json_extract(raw_json, \'$.repair_time\')), \'\')
                ) AS repair_time,
                COALESCE(NULLIF(TRIM(json_extract(raw_json, \'$.warranty\')), \'\'), \'\') AS warranty
            FROM mobile_reports
            WHERE client_name LIKE :q OR phone LIKE :q OR report_id LIKE :q OR model LIKE :q
               OR device_type LIKE :q OR diagnosis LIKE :q OR recommendations LIKE :q
               OR IFNULL(serial_number, \'\') LIKE :q
            ORDER BY created_at DESC
            LIMIT 30';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => $like]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['type'] = 'report';
            $row['total_amount'] = null;
            $row['payment_method'] = '';
            $results[] = $row;
        }
    }

    if ($typeFilter === 'all' || $typeFilter === 'invoice') {
        $sql = 'SELECT document_id, order_id, client_id, client_name, client_phone, client_email,
                service_object AS device_model, status, due_date, total_amount, payment_terms,
                COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS date_created,
                invoice_id
            FROM invoices
            WHERE client_name LIKE :q OR client_phone LIKE :q OR client_email LIKE :q
               OR document_id LIKE :q OR invoice_id LIKE :q OR service_object LIKE :q OR note LIKE :q
            ORDER BY date_created DESC
            LIMIT 30';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => $like]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['type'] = 'invoice';
            $row['payment_method'] = 'bank_transfer';
            $results[] = $row;
        }
    }

    usort($results, static function ($a, $b) {
        $ta = strtotime((string) ($a['date_created'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['date_created'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    $results = array_slice($results, 0, 60);

    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results),
        'query' => $query,
        'type_filter' => $typeFilter,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('search_optimized SQLite: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка поиска: ' . $e->getMessage(),
        'results' => [],
        'count' => 0,
    ], JSON_UNESCAPED_UNICODE);
}
