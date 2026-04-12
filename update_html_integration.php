<?php
/**
 * ОБНОВЛЕНИЕ HTML ФАЙЛОВ
 * Интегрирует новые API в существующие HTML файлы
 */

header('Content-Type: application/json; charset=utf-8');

$results = [];
$errors = [];

// Список HTML файлов для обновления
$htmlFiles = [
    'receipt.html' => [
        'old_api' => './api/save_receipt_ultimate.php',
        'new_api' => './api/save_document_universal.php',
        'pdf_api' => './api/generate_pdf_universal.php'
    ],
    'pages/diagnostika_mobile.html' => [
        'old_api' => '../api/save_report_ultimate.php',
        'new_api' => '../api/save_document_universal.php',
        'pdf_api' => '../api/generate_pdf_universal.php'
    ],
];

foreach ($htmlFiles as $file => $apis) {
    $filePath = __DIR__ . '/../' . $file;
    
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        $originalContent = $content;
        
        // Обновляем API вызовы
        $content = str_replace($apis['old_api'], $apis['new_api'], $content);
        
        // Добавляем подключение core.js если его нет
        if (strpos($content, 'core.js') === false) {
            $jsInclude = '<script src="./js/core.js"></script>';
            $content = str_replace('</head>', "    {$jsInclude}\n</head>", $content);
        }
        
        // Добавляем проверку авторизации если её нет
        if (strpos($content, 'checkAuth') === false) {
            $authScript = '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    FixariVan.utils.checkAuth();
                });
            </script>';
            $content = str_replace('</body>', "    {$authScript}\n</body>", $content);
        }
        
        if ($content !== $originalContent) {
            if (file_put_contents($filePath, $content) !== false) {
                $results[] = "✅ Обновлён файл: {$file}";
            } else {
                $errors[] = "❌ Не удалось обновить файл: {$file}";
            }
        } else {
            $results[] = "ℹ️ Файл не требует обновления: {$file}";
        }
    } else {
        $errors[] = "❌ Файл не найден: {$file}";
    }
}

// Создаём универсальный шаблон для всех форм
$universalTemplate = '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FixariVan - Система управления</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><text y=\'.9em\' font-size=\'90\'>🔧</text></svg>">
    <script src="./js/core.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background: #f4f4f4; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;
        }
        .btn { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .error { border-color: #dc3545 !important; }
        .notification { position: fixed; top: 20px; right: 20px; z-index: 10000; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Контент формы -->
    </div>
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            FixariVan.utils.checkAuth();
        });
    </script>
</body>
</html>';

if (file_put_contents(__DIR__ . '/../templates/universal_template.html', $universalTemplate) !== false) {
    $results[] = "✅ Создан универсальный шаблон";
} else {
    $errors[] = "❌ Не удалось создать универсальный шаблон";
}

// Создаём файл с общими стилями
$commonStyles = '/* FIXARIVAN COMMON STYLES */
:root {
    --primary-color: #4e6ef2;
    --secondary-color: #f4f4f4;
    --accent-color: #007bff;
    --success-color: #28a745;
    --error-color: #dc3545;
    --warning-color: #ffc107;
    --info-color: #17a2b8;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;
    background: #f4f4f4;
    color: #333;
    line-height: 1.6;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.btn {
    display: inline-block;
    padding: 10px 20px;
    background: var(--primary-color);
    color: white;
    text-decoration: none;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn:hover {
    background: var(--accent-color);
    transform: translateY(-2px);
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 16px;
    transition: border-color 0.3s ease;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(78, 110, 242, 0.2);
}

.error {
    border-color: var(--error-color) !important;
    box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.2) !important;
}

.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    max-width: 400px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideIn 0.3s ease-out;
}

.notification-success { border-left: 4px solid var(--success-color); }
.notification-error { border-left: 4px solid var(--error-color); }
.notification-warning { border-left: 4px solid var(--warning-color); }
.notification-info { border-left: 4px solid var(--info-color); }

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid var(--primary-color);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .container {
        padding: 10px;
    }
    
    .btn {
        width: 100%;
        margin-bottom: 10px;
    }
}';

if (file_put_contents(__DIR__ . '/../css/common.css', $commonStyles) !== false) {
    $results[] = "✅ Создан файл общих стилей";
} else {
    $errors[] = "❌ Не удалось создать файл общих стилей";
}

echo json_encode([
    'success' => empty($errors),
    'message' => empty($errors) ? 'Интеграция HTML завершена успешно' : 'Интеграция завершена с ошибками',
    'results' => $results,
    'errors' => $errors
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
