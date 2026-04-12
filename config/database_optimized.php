<?php
/**
 * Оптимизированное подключение MySQL (legacy).
 *
 * DEPRECATED — not used in SQLite flow.
 */
require_once dirname(__DIR__) . '/config.php';

$dbConnections = [];

function getFastDBConnection() {
    global $dbConnections;

    $key = 'main';

    if (!isset($dbConnections[$key])) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET . ' COLLATE utf8mb4_unicode_ci',
                ]
            );

            $dbConnections[$key] = $pdo;
        } catch (PDOException $e) {
            error_log('Fast DB connection failed: ' . $e->getMessage());
            return null;
        }
    }

    return $dbConnections[$key];
}

function quickDBFetch($sql, $params = []) {
    $pdo = getFastDBConnection();
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Quick DB fetch failed: ' . $e->getMessage());
        return null;
    }
}

function quickDBFetchAll($sql, $params = []) {
    $pdo = getFastDBConnection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Quick DB fetch all failed: ' . $e->getMessage());
        return [];
    }
}

function quickDBExecute($sql, $params = []) {
    $pdo = getFastDBConnection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log('Quick DB execute failed: ' . $e->getMessage());
        return false;
    }
}

function setCORSHeaders() {
    require_once dirname(__DIR__) . '/api/lib/cors.php';
    fixarivan_send_cors_headers('GET, POST, PUT, DELETE, OPTIONS', 'Content-Type, Authorization');
}

function handleOptionsRequest() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
}

function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true);
}

function sendJsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
