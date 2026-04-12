<?php
/**
 * Удаление документов из SQLite (админ-сессия).
 */

declare(strict_types=1);

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/lib/security_settings.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_supply.php';

function fixarivan_delete_inventory_movements_for_document(PDO $pdo, string $documentType, string $documentId, array $orderVariants = []): void
{
    $documentType = strtolower(trim($documentType));
    $documentId = trim($documentId);
    if ($documentId === '') {
        return;
    }
    if ($documentType === 'order') {
        $variants = array_values(array_unique(array_filter(array_map('trim', $orderVariants), static function ($v) {
            return $v !== '';
        })));
        if ($variants === []) {
            $variants = [$documentId];
        }
        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $sql = "DELETE FROM inventory_movements WHERE ref_kind = 'order' AND ref_id IN ($placeholders)";
        $pdo->prepare($sql)->execute($variants);
        return;
    }
    if (!in_array($documentType, ['receipt', 'invoice', 'report'], true)) {
        return;
    }
    $pdo->prepare('DELETE FROM inventory_movements WHERE ref_kind = ? AND ref_id = ?')->execute([$documentType, $documentId]);
}

/**
 * @return array{success:bool,message?:string,document_id?:string}
 */
function deleteDocumentSqlite(string $documentId, string $documentType): array {
    $documentId = trim($documentId);
    $documentType = strtolower(trim($documentType));
    if ($documentId === '') {
        return ['success' => false, 'message' => 'Пустой идентификатор'];
    }

    try {
        $pdo = getSqliteConnection();
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'SQLite недоступна: ' . $e->getMessage()];
    }

    try {
        $pdo->beginTransaction();
        if ($documentType === 'order') {
            $oidRow = $pdo->prepare('SELECT order_id, document_id FROM orders WHERE document_id = ? LIMIT 1');
            $oidRow->execute([$documentId]);
            $or = $oidRow->fetch(PDO::FETCH_ASSOC);
            $oidForWh = is_array($or) ? trim((string) ($or['order_id'] ?? '')) : '';
            if ($oidForWh === '') {
                $oidForWh = $documentId;
            }
            $variants = fixarivan_order_id_variants_for_pdo($pdo, $documentId, $oidForWh);
            if ($variants === []) {
                $variants = [$oidForWh, $documentId];
            }
            fixarivan_cleanup_order_supply_on_order_delete($pdo, $variants);
            fixarivan_delete_inventory_movements_for_document($pdo, 'order', $documentId, $variants);
            $stmt = $pdo->prepare('DELETE FROM orders WHERE document_id = ?');
            $stmt->execute([$documentId]);
        } elseif ($documentType === 'receipt') {
            fixarivan_delete_inventory_movements_for_document($pdo, 'receipt', $documentId);
            $stmt = $pdo->prepare('DELETE FROM receipts WHERE document_id = ?');
            $stmt->execute([$documentId]);
        } elseif ($documentType === 'report') {
            fixarivan_delete_inventory_movements_for_document($pdo, 'report', $documentId);
            $stmt = $pdo->prepare('DELETE FROM mobile_reports WHERE report_id = ?');
            $stmt->execute([$documentId]);
        } elseif ($documentType === 'invoice') {
            fixarivan_delete_inventory_movements_for_document($pdo, 'invoice', $documentId);
            $stmt = $pdo->prepare('DELETE FROM invoices WHERE document_id = ?');
            $stmt->execute([$documentId]);
        } else {
            return ['success' => false, 'message' => 'Неизвестный тип документа'];
        }

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Документ не найден'];
        }

        // Дополнительно убираем JSON-бэкапы (если есть)
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
        if ($documentType === 'order') {
            $p = $root . 'orders' . DIRECTORY_SEPARATOR . $documentId . '.json';
            if (is_file($p)) {
                @unlink($p);
            }
        } elseif ($documentType === 'receipt') {
            $p = $root . 'receipts' . DIRECTORY_SEPARATOR . $documentId . '.json';
            if (is_file($p)) {
                @unlink($p);
            }
        } elseif ($documentType === 'invoice') {
            $p = $root . 'invoices' . DIRECTORY_SEPARATOR . $documentId . '.json';
            if (is_file($p)) {
                @unlink($p);
            }
        }

        $pdo->commit();
        return ['success' => true, 'message' => 'Документ успешно удален', 'document_id' => $documentId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('deleteDocumentSqlite: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка при удалении: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['documentId'], $input['documentType'])) {
        echo json_encode(['success' => false, 'message' => 'Не указаны обязательные поля'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $deletePassword = trim((string)($input['delete_password'] ?? ''));
    if (!fixarivan_verify_delete_password($deletePassword)) {
        echo json_encode(['success' => false, 'message' => 'Неверный пароль удаления'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = deleteDocumentSqlite((string) $input['documentId'], (string) $input['documentType']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
