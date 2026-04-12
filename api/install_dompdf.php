<?php
/**
 * Dompdf bootstrapper for FixariVan project
 *
 * Upload этот файл в каталог api/ и откройте через браузер (https://ваш-домен/api/install_dompdf.php).
 * Скрипт проверит наличие vendor/autoload.php и, при необходимости, скачает архив Dompdf 2.0.4
 * с GitHub, распакует его в api/dompdf/ и выставит минимальные права на каталоги.
 *
 * После успешного запуска не забудьте удалить этот файл с сервера.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$dompdfDir   = __DIR__ . '/dompdf';
$autoload    = $dompdfDir . '/vendor/autoload.php';
$zipUrl      = 'https://github.com/dompdf/dompdf/releases/download/v2.0.4/dompdf_2-0-4.zip';
$zipFile     = __DIR__ . '/dompdf.zip';
$log         = [];

function add_log(&$log, $message, $status = 'info')
{
    $log[] = [
        'message' => $message,
        'status'  => $status
    ];
}

function download_file($url, $destination, &$log)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($destination, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'FixariVan Dompdf Installer'
        ]);
        $result = curl_exec($ch);
        $error  = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$result) {
            unlink($destination);
            add_log($log, 'cURL не смог скачать архив: ' . $error, 'error');
            return false;
        }
        return true;
    }

    if (ini_get('allow_url_fopen')) {
        $data = @file_get_contents($url);
        if ($data === false) {
            add_log($log, 'Не удалось скачать архив (allow_url_fopen).', 'error');
            return false;
        }
        file_put_contents($destination, $data);
        return true;
    }

    add_log($log, 'Нет способов скачать файл (ни cURL, ни allow_url_fopen).', 'error');
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (file_exists($autoload)) {
        add_log($log, 'Dompdf уже установлен. Удалите dompdf/ для переустановки.', 'success');
    } else {
        if (is_dir($dompdfDir)) {
            add_log($log, 'Каталог dompdf существует, но внутри нет vendor/autoload.php. Удалите папку вручную, чтобы установить заново.', 'error');
        } else {
            add_log($log, 'Скачиваем Dompdf из ' . htmlspecialchars($zipUrl), 'info');
            if (download_file($zipUrl, $zipFile, $log)) {
                add_log($log, 'Архив загружен: ' . basename($zipFile), 'success');

                $zip = new ZipArchive();
                if ($zip->open($zipFile) === true) {
                    $zip->extractTo($dompdfDir . '_tmp');
                    $zip->close();
                    unlink($zipFile);

                    // В архиве корневая папка "dompdf"
                    $tmpRoot = glob($dompdfDir . '_tmp/dompdf*');
                    if (!empty($tmpRoot) && is_dir($tmpRoot[0])) {
                        rename($tmpRoot[0], $dompdfDir);
                        add_log($log, 'Архив распакован в ' . basename($dompdfDir), 'success');
                    }
                    if (is_dir($dompdfDir . '_tmp')) {
                        // Удаляем временный каталог
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($dompdfDir . '_tmp', RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ($iterator as $item) {
                            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                        }
                        rmdir($dompdfDir . '_tmp');
                    }

                    if (file_exists($autoload)) {
                        chmod($dompdfDir, 0755);
                        add_log($log, 'Dompdf установлен.', 'success');
                    } else {
                        add_log($log, 'Не найден vendor/autoload.php после распаковки. Проверьте содержимое вручную.', 'error');
                    }
                } else {
                    add_log($log, 'Не удалось распаковать ZIP архив.', 'error');
                }
            }
        }
    }
} else {
    if (file_exists($autoload)) {
        add_log($log, 'Dompdf уже установлен. vendor/autoload.php найден.', 'success');
    } else {
        add_log($log, 'Dompdf не найден. Нажмите кнопку ниже для установки.', 'warning');
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Установка Dompdf для FixariVan</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #e2e8f0; padding: 30px; }
        h1 { margin-top: 0; }
        form { margin-bottom: 30px; }
        button { padding: 12px 24px; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .log { margin-top: 20px; }
        .log-item { padding: 10px 14px; border-radius: 6px; margin-bottom: 10px; }
        .info { background: #1e293b; }
        .success { background: #14532d; color: #bbf7d0; }
        .warning { background: #78350f; color: #fef3c7; }
        .error { background: #7f1d1d; color: #fecaca; }
    </style>
</head>
<body>
    <h1>Установка Dompdf</h1>
    <p>Скрипт скачает Dompdf 2.0.4 из официального репозитория. После успешной установки удалите <code>install_dompdf.php</code> из каталога <code>api/</code>.</p>

    <form method="post">
        <button type="submit">Установить / Проверить Dompdf</button>
    </form>

    <div class="log">
        <?php foreach ($log as $entry): ?>
            <div class="log-item <?php echo htmlspecialchars($entry['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($entry['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
