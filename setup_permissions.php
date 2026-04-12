<?php
/**
 * НАСТРОЙКА ПРАВ ДОСТУПА И ПАПОК
 * Создаёт необходимые папки и настраивает права
 */

header('Content-Type: application/json; charset=utf-8');

$results = [];
$errors = [];

// Создаём необходимые папки
$directories = [
    '../logs',
    '../uploads',
    '../generated_pdfs',
    '../templates',
    '../database',
    '../js',
    '../css'
];

foreach ($directories as $dir) {
    $fullPath = __DIR__ . '/' . $dir;
    if (!is_dir($fullPath)) {
        if (mkdir($fullPath, 0755, true)) {
            $results[] = "✅ Создана папка: {$dir}";
        } else {
            $errors[] = "❌ Не удалось создать папку: {$dir}";
        }
    } else {
        $results[] = "ℹ️ Папка уже существует: {$dir}";
    }
}

// Создаём файлы .htaccess для защиты папок
$htaccessFiles = [
    '../logs/.htaccess' => "Order Deny,Allow\nDeny from all",
    '../uploads/.htaccess' => "Order Allow,Deny\nAllow from all\n<FilesMatch \"\\.php$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>",
    '../database/.htaccess' => "Order Deny,Allow\nDeny from all"
];

foreach ($htaccessFiles as $file => $content) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_put_contents($fullPath, $content) !== false) {
        $results[] = "✅ Создан .htaccess: {$file}";
    } else {
        $errors[] = "❌ Не удалось создать .htaccess: {$file}";
    }
}

// Создаём файл index.php для защиты папок
$indexFiles = [
    '../logs/index.php' => '<?php // Защита папки ?>',
    '../uploads/index.php' => '<?php // Защита папки ?>',
    '../database/index.php' => '<?php // Защита папки ?>'
];

foreach ($indexFiles as $file => $content) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_put_contents($fullPath, $content) !== false) {
        $results[] = "✅ Создан index.php: {$file}";
    } else {
        $errors[] = "❌ Не удалось создать index.php: {$file}";
    }
}

// Проверяем права доступа
$writableDirs = ['../logs', '../uploads', '../generated_pdfs'];
foreach ($writableDirs as $dir) {
    $fullPath = __DIR__ . '/' . $dir;
    if (is_writable($fullPath)) {
        $results[] = "✅ Папка доступна для записи: {$dir}";
    } else {
        $errors[] = "❌ Папка недоступна для записи: {$dir}";
    }
}

// Локальная конфигурация без секретов в репозитории
$exampleCfg = __DIR__ . '/config.example.php';
$localCfg = __DIR__ . '/config.local.php';
if (!is_readable($localCfg) && is_readable($exampleCfg)) {
    if (@copy($exampleCfg, $localCfg)) {
        $results[] = '✅ Создан config.local.php из config.example.php — заполните пароли и БД';
    } else {
        $errors[] = '❌ Не удалось скопировать config.example.php в config.local.php';
    }
} elseif (!is_readable($localCfg)) {
    $errors[] = '❌ Нет config.local.php — скопируйте config.example.php вручную';
}

// Создаём README для папок
$readmeContent = "# FixariVan System

## Структура папок:
- `/api/` - API файлы
- `/logs/` - Логи системы
- `/uploads/` - Загруженные файлы
- `/generated_pdfs/` - Сгенерированные PDF
- `/templates/` - Шаблоны
- `/database/` - SQL файлы
- `/js/` - JavaScript файлы
- `/css/` - CSS файлы

## Безопасность:
- Все папки защищены .htaccess
- Логи недоступны извне
- Загруженные файлы проверяются
";

if (file_put_contents(__DIR__ . '/../README.md', $readmeContent) !== false) {
    $results[] = "✅ Создан README.md";
} else {
    $errors[] = "❌ Не удалось создать README.md";
}

echo json_encode([
    'success' => empty($errors),
    'message' => empty($errors) ? 'Настройка завершена успешно' : 'Настройка завершена с ошибками',
    'results' => $results,
    'errors' => $errors
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
