<?php
/**
 * Полная очистка документов в SQLite (акты, квитанции, отчёты) + JSON-бэкапы.
 * Склад (inventory) не трогаем.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/lib/security_settings.php';
require_once __DIR__ . '/sqlite.php';

/**
 * @return int количество удалённых файлов
 */
function fixarivan_clear_json_dir(string $dir): int {
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        if (is_file($file) && @unlink($file)) {
            $n++;
        }
    }
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['confirm']) || $input['confirm'] !== 'YES_DELETE_ALL') {
        echo json_encode(['success' => false, 'message' => 'Требуется подтверждение для удаления всех данных'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $deletePassword = trim((string)($input['delete_password'] ?? ''));
    if (!fixarivan_verify_delete_password($deletePassword)) {
        echo json_encode(['success' => false, 'message' => 'Неверный пароль удаления'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $currentTime = time();
    $lastClearTime = isset($_SESSION['last_clear_time']) ? (int) $_SESSION['last_clear_time'] : 0;

    if ($currentTime - $lastClearTime < 10) {
        echo json_encode(['success' => false, 'message' => 'Слишком частые запросы на очистку. Подождите 10 секунд.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['last_clear_time'] = $currentTime;

    try {
        $pdo = getSqliteConnection();
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'SQLite недоступна: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $cOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $cReceipts = (int) $pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn();
        $cReports = (int) $pdo->query('SELECT COUNT(*) FROM mobile_reports')->fetchColumn();

        $pdo->exec('DELETE FROM orders');
        $pdo->exec('DELETE FROM receipts');
        $pdo->exec('DELETE FROM mobile_reports');

        try {
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('orders','receipts','mobile_reports')");
        } catch (Throwable $e) {
            // sqlite_sequence может отсутствовать на чистой БД — не критично
        }

        $pdo->commit();

        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
        $jsonRemoved = 0;
        $jsonRemoved += fixarivan_clear_json_dir($root . 'orders');
        $jsonRemoved += fixarivan_clear_json_dir($root . 'receipts');
        $jsonRemoved += fixarivan_clear_json_dir($root . 'reports');
        $jsonRemoved += fixarivan_clear_json_dir($root . 'orders_tokens');

        $deletedCounts = [
            'orders' => $cOrders,
            'receipts' => $cReceipts,
            'mobile_reports' => $cReports,
        ];

        $totalDeleted = $cOrders + $cReceipts + $cReports;

        error_log('SQLite documents cleared: ' . json_encode($deletedCounts, JSON_UNESCAPED_UNICODE));

        echo json_encode([
            'success' => true,
            'message' => 'Документы в SQLite очищены',
            'deleted_counts' => $deletedCounts,
            'total_deleted' => $totalDeleted,
            'json_files_removed' => $jsonRemoved,
            'details' => [
                'orders' => "Удалено актов: {$cOrders}",
                'receipts' => "Удалено квитанций: {$cReceipts}",
                'mobile_reports' => "Удалено отчётов: {$cReports}",
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('clear_database SQLite: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Ошибка очистки: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
