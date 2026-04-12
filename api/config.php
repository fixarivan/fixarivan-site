<?php
/**
 * Совместимость со скриптами, ожидающими $dsn / $username / $password.
 *
 * Параметры MySQL (legacy) задаются в корневом config.local.php и константах DB_* из config.php.
 * Рабочие данные заказов/портала — SQLite (api/sqlite.php), не этот DSN.
 */
require_once dirname(__DIR__) . '/config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USER;
$password = DB_PASS;
$charset = DB_CHARSET;

$dsn = 'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=' . $charset;

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];

function testDatabaseConnection() {
    global $dsn, $username, $password, $options;

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
        return [
            'success' => true,
            'message' => 'Подключение к базе данных успешно',
            'pdo' => $pdo,
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка подключения к БД: ' . $e->getMessage(),
            'error_code' => $e->getCode(),
        ];
    }
}

function getDatabaseInfo() {
    global $dsn, $username, $password, $options;

    try {
        $pdo = new PDO($dsn, $username, $password, $options);

        $stmt = $pdo->query('SELECT VERSION() as version');
        $version = $stmt->fetch()['version'];

        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return [
            'success' => true,
            'mysql_version' => $version,
            'tables' => $tables,
            'table_count' => count($tables),
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка получения информации о БД: ' . $e->getMessage(),
        ];
    }
}
