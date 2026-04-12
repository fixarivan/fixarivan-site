<?php
/**
 * API для получения информации о документе (SQLite + JSON fallback для актов/квитанций).
 */

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

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/mobile_report_document.php';
require_once __DIR__ . '/lib/order_document_load.php';

function loadJsonDocument(string $documentType, string $documentId): array {
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
    if ($documentType === 'order') {
        $path = $base . 'orders' . DIRECTORY_SEPARATOR . $documentId . '.json';
    } elseif ($documentType === 'receipt') {
        $path = $base . 'receipts' . DIRECTORY_SEPARATOR . $documentId . '.json';
    } elseif ($documentType === 'invoice') {
        $path = $base . 'invoices' . DIRECTORY_SEPARATOR . $documentId . '.json';
    } else {
        return [];
    }

    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function loadDocumentFromSqliteOrJson(string $documentType, string $documentId): array {
    if ($documentType === 'order') {
        try {
            $pdo = getSqliteConnection();

            return fixarivan_load_order_from_sqlite_or_json($pdo, $documentId);
        } catch (Throwable $e) {
            return fixarivan_load_order_from_json_file_only($documentId);
        }
    }
    $table = $documentType === 'invoice' ? 'invoices' : 'receipts';
    try {
        $pdo = getSqliteConnection();
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE document_id = :id LIMIT 1");
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch();
        if (is_array($row) && $row !== []) {
            return $row;
        }
    } catch (Throwable $e) {
        // fallback to JSON
    }

    return loadJsonDocument($documentType, $documentId);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $documentId = $_GET['id'] ?? '';
    $documentType = $_GET['type'] ?? '';

    if ($documentId === '' || $documentType === '') {
        echo json_encode(['success' => false, 'message' => 'ID документа и тип обязательны.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($documentType === 'order' || $documentType === 'receipt' || $documentType === 'invoice') {
        $doc = loadDocumentFromSqliteOrJson($documentType, (string) $documentId);
        if ($doc !== []) {
            if ($documentType === 'invoice' && isset($doc['items_json'])) {
                $items = json_decode((string)$doc['items_json'], true);
                $doc['items'] = is_array($items) ? $items : [];
            }
            echo json_encode(['success' => true, 'data' => $doc], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Документ не найден.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($documentType === 'report') {
        try {
            $pdo = getSqliteConnection();
            $doc = fixarivan_load_mobile_report_by_id($pdo, (string) $documentId);
            if ($doc !== null) {
                echo json_encode(['success' => true, 'data' => $doc], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Throwable $e) {
            error_log('get_document report SQLite: ' . $e->getMessage());
        }
        echo json_encode(['success' => false, 'message' => 'Документ не найден.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Неизвестный тип документа.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Метод не поддерживается.'], JSON_UNESCAPED_UNICODE);
