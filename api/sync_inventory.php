<?php
/**
 * БЫСТРЫЙ API для синхронизации склада между устройствами
 *
 * DEPRECATED — not used in SQLite flow (MySQL). Kept for reference; do not use from new UI.
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, GET, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

// Подключаем оптимизированную БД
require_once '../config/database_optimized.php';

// Создаём таблицу inventory если её нет
function createInventoryTable($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        category VARCHAR(100),
        quantity INT DEFAULT 0,
        min_stock INT DEFAULT 5,
        cost_price DECIMAL(10,2) DEFAULT 0,
        selling_price DECIMAL(10,2) DEFAULT 0,
        description TEXT,
        qr_code VARCHAR(255),
        image_url VARCHAR(500),
        metadata LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    $pdo->exec($sql);

    $columns = $pdo->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('metadata', $columns, true)) {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN metadata LONGTEXT AFTER image_url");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Сохраняем данные склада в БД
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['inventory'])) {
        echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
        exit;
    }
    
    try {
        $pdo = getFastDBConnection();
        if (!$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }
        
        // Создаём таблицу если её нет
        createInventoryTable($pdo);
        
        // Очищаем старые данные
        $pdo->exec("DELETE FROM inventory");
        
        // Сохраняем новые данные
        $stmt = $pdo->prepare("
            INSERT INTO inventory (name, category, quantity, min_stock, cost_price, selling_price, description, qr_code, image_url, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($input['inventory'] as $item) {
            $metadata = [
                'sku' => $item['sku'] ?? null,
                'compatibility' => $item['compatibility'] ?? null,
                'location' => $item['location'] ?? null,
                'notes' => $item['notes'] ?? null,
                'history' => $item['history'] ?? [],
                'status' => $item['status'] ?? null,
                'deviceType' => $item['deviceType'] ?? null
            ];

            $stmt->execute([
                $item['name'] ?? '',
                $item['category'] ?? '',
                $item['quantity'] ?? 0,
                $item['minStock'] ?? 5,
                $item['costPrice'] ?? 0,
                $item['sellingPrice'] ?? ($item['sellPrice'] ?? 0),
                is_array($item['description'] ?? null) ? json_encode($item['description'], JSON_UNESCAPED_UNICODE) : ($item['description'] ?? ''),
                $item['qrCode'] ?? ($item['qr_code'] ?? ''),
                $item['imageUrl'] ?? ($item['image'] ?? ''),
                json_encode($metadata, JSON_UNESCAPED_UNICODE)
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Склад синхронизирован с БД']);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Получаем данные склада из БД
    try {
        $pdo = getFastDBConnection();
        if (!$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }
        
        // Создаём таблицу если её нет
        createInventoryTable($pdo);
        
        // Получаем данные
        $stmt = $pdo->query("SELECT * FROM inventory ORDER BY created_at DESC");
        $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $normalized = array_map(function ($item) {
            $metadata = [];
            if (!empty($item['metadata'])) {
                $decoded = json_decode($item['metadata'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            return array_merge(
                [
                    'id' => (int)$item['id'],
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'quantity' => (int)($item['quantity'] ?? 0),
                    'minStock' => (int)($item['min_stock'] ?? 0),
                    'costPrice' => (float)($item['cost_price'] ?? 0),
                    'sellingPrice' => (float)($item['selling_price'] ?? 0),
                    'description' => $item['description'],
                    'qrCode' => $item['qr_code'],
                    'imageUrl' => $item['image_url'],
                    'created' => $item['created_at'],
                    'updated' => $item['updated_at']
                ],
                $metadata
            );
        }, $inventory);
        
        echo json_encode(['success' => true, 'inventory' => $normalized]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>
