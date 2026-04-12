<?php
/**
 * Пример конфигурации. Скопируйте в config.local.php и заполните значения.
 * Файл config.local.php не должен попадать в git.
 */

// MySQL (legacy-скрипты: update_document, search_optimized, clear_*, sync и т.д.)
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('DB_CHARSET', 'utf8mb4');

// Админ-панель (admin/login.php) — только в config.local.php.
// Важно: вход в /admin/ проверяет именно ADMIN_*, не AUTH_* (старый index.html).
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'change_this_strong_password');

// После смены пароля в admin/settings.php создаётся storage/admin_auth.json (bcrypt);
// тогда используются только он + логин из файла, а не эти константы.

// Совместимость со старым checkAuth() в PHP / клиентским index (если используется)
define('AUTH_USERNAME', 'spacefix');
define('AUTH_PASSWORD', 'change_legacy_if_used');

define('API_KEY', 'generate_random_api_key_if_needed');
define('ALLOWED_ORIGINS', ['https://your-domain.example', 'https://www.your-domain.example']);

define('SESSION_TIMEOUT', 8 * 60 * 60);

define('PREFIX_ORDER', 'FV-');
define('PREFIX_RECEIPT', 'RCP-');
define('PREFIX_REPORT', 'RPT-');

define('COMPANY_NAME', 'FixariVan');
define('COMPANY_OWNER', '');
define('DEFAULT_LANGUAGE', 'ru');
define('SUPPORTED_LANGUAGES', ['ru', 'en', 'fi']);

/**
 * Опционально: каталог, в котором лежит fixarivan.sqlite (без имени файла).
 * Удобно на cPanel, если БД хранится вне public_html. По умолчанию используется ./storage/
 *
 * define('FIXARIVAN_SQLITE_STORAGE_DIR', '/home/USERNAME/fixarivan_private');
 */
