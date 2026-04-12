<?php
/**
 * Загрузка секретов из config.local.php (не в репозитории).
 *
 * Legacy MySQL helper getDatabaseConnection() — for deprecated endpoints only.
 * Production data path is SQLite (api/sqlite.php).
 */
$fixarivanConfigLocal = __DIR__ . '/config.local.php';
if (!is_readable($fixarivanConfigLocal)) {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        fwrite(STDERR, "FixariVan: создайте config.local.php на основе config.example.php\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('FixariVan: отсутствует config.local.php. Скопируйте config.example.php в config.local.php и заполните.');
}

require_once $fixarivanConfigLocal;

ini_set('log_errors', '1');

date_default_timezone_set('Europe/Helsinki');

require_once __DIR__ . '/api/lib/cors.php';

function getDatabaseConnection() {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('SET CHARACTER SET ' . DB_CHARSET);
        $pdo->exec('SET NAMES ' . DB_CHARSET . ' COLLATE ' . DB_CHARSET . '_unicode_ci');

        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

function checkAuth($username, $password) {
    return $username === AUTH_USERNAME && $password === AUTH_PASSWORD;
}

function generateDocumentId($prefix = PREFIX_ORDER) {
    $timestamp = date('YmdHis');
    $random = strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 6));
    return $prefix . $timestamp . '-' . $random;
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidPhone($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    return strlen($phone) >= 10;
}

function logActivity($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = '[' . $timestamp . '] [' . $level . '] ' . $message . PHP_EOL;
    error_log($logMessage, 3, __DIR__ . '/logs/activity.log');
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim((string) $data), ENT_QUOTES, 'UTF-8');
}

function checkCORS() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || !is_array(ALLOWED_ORIGINS)) {
        return;
    }
    if (!in_array($origin, ALLOWED_ORIGINS, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
}

function setCORSHeaders() {
    fixarivan_send_cors_headers();
    header('Content-Type: application/json; charset=utf-8');
}

function handleOptionsRequest() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        setCORSHeaders();
        exit(0);
    }
}

function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data');
    }
    return $input;
}

function sendJsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function validateRequiredFields($data, $requiredFields) {
    $missing = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            $missing[] = $field;
        }
    }
    return $missing;
}

function ensureLogDirectory() {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
}

ensureLogDirectory();
