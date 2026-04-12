<?php
/**
 * ПРОСТОЙ ТЕСТ БД
 * Проверяет подключение и добавляет недостающее поле
 */

echo "<h1>🔧 Простой тест БД FixariVan</h1>";
echo "<style>body{font-family:Arial;margin:20px;} .success{color:green;} .error{color:red;} .info{color:blue;}</style>";

require_once __DIR__ . '/config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USER;
$password = DB_PASS;

echo "<h2>🔍 Проверка подключения:</h2>";
echo "<div class='info'>Host: $host<br>Database: $dbname<br>Username: $username</div>";

try {
    $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4', $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='success'>✅ ПОДКЛЮЧЕНИЕ УСПЕШНО!</div>";
    
    // Проверяем таблицы
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<div class='info'>📋 Найдено таблиц: " . count($tables) . "</div>";
    
    // Проверяем структуру receipts
    if (in_array('receipts', $tables)) {
        echo "<h2>🔍 Проверка таблицы receipts:</h2>";
        
        $columns = $pdo->query("DESCRIBE receipts")->fetchAll(PDO::FETCH_COLUMN);
        echo "<div class='info'>Поля в receipts: " . implode(', ', $columns) . "</div>";
        
        if (in_array('notes', $columns)) {
            echo "<div class='success'>✅ Поле 'notes' УЖЕ ЕСТЬ в receipts!</div>";
        } else {
            echo "<div class='error'>❌ Поле 'notes' ОТСУТСТВУЕТ в receipts!</div>";
            
            echo "<h2>🔧 Добавляем поле 'notes':</h2>";
            try {
                $pdo->exec("ALTER TABLE receipts ADD COLUMN notes TEXT");
                echo "<div class='success'>✅ Поле 'notes' ДОБАВЛЕНО в receipts!</div>";
            } catch (PDOException $e) {
                echo "<div class='error'>❌ Ошибка добавления поля: " . $e->getMessage() . "</div>";
            }
        }
    }
    
    // Проверяем другие таблицы
    if (in_array('orders', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        echo "<div class='info'>📝 Заказов в БД: $count</div>";
    }
    
    if (in_array('reports', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();
        echo "<div class='info'>📋 Отчётов в БД: $count</div>";
    }
    
    echo "<h2>🎉 РЕЗУЛЬТАТ:</h2>";
    echo "<div class='success'>✅ БД готова к работе! Теперь замените файлы на сервере.</div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ ОШИБКА ПОДКЛЮЧЕНИЯ: " . $e->getMessage() . "</div>";
    echo "<div class='info'>Проверьте credentials в cPanel → MySQL Databases</div>";
}
?>
