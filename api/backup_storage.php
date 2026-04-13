<?php
declare(strict_types=1);

/**
 * Скачивание ZIP каталога storage/ (SQLite, токены, JSON) — только для администратора (PHP session).
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', '');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Only GET'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!class_exists(ZipArchive::class)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Не установлено расширение PHP zip (ZipArchive).'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/sqlite.php';

$storage = sqliteStorageDir();
if (!is_dir($storage)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Каталог storage не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseReal = realpath($storage);
if ($baseReal === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Не удалось прочитать путь к storage'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(300);
ini_set('memory_limit', '512M');

$ts = date('Y-m-d_H-i-s');
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fv_storage_' . $ts . '_' . bin2hex(random_bytes(4)) . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Не удалось создать временный архив'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $full = $file->getRealPath();
        if ($full === false) {
            continue;
        }
        if (strpos($full, $baseReal) !== 0) {
            continue;
        }
        $sub = str_replace('\\', '/', substr($full, strlen($baseReal)));
        $sub = ltrim($sub, '/');
        if ($sub === '') {
            continue;
        }
        $rel = 'storage/' . $sub;
        if (!$zip->addFile($full, $rel)) {
            // пропускаем проблемный файл
        }
    }
    $zip->close();
} catch (Throwable $e) {
    try {
        $zip->close();
    } catch (Throwable $e2) {
    }
    @unlink($tmp);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Ошибка при сборке архива'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_readable($tmp)) {
    @unlink($tmp);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Архив не создан'], JSON_UNESCAPED_UNICODE);
    exit;
}

$downloadName = 'fixarivan_storage_' . $ts . '.zip';
$len = filesize($tmp);
if ($len !== false) {
    header('Content-Length: ' . (string)$len);
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($tmp);
@unlink($tmp);
exit;
