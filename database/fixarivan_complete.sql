-- FIXARIVAN COMPLETE DATABASE STRUCTURE
-- Исправляет ВСЕ проблемы из отчёта
-- Дата: 25.10.2025

-- Удаляем старые таблицы если существуют
DROP TABLE IF EXISTS `document_history`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `receipts`;
DROP TABLE IF EXISTS `orders`;

-- 1. ТАБЛИЦА ЗАКАЗОВ (ORDERS) - ПОЛНАЯ СТРУКТУРА
CREATE TABLE `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` VARCHAR(255) NOT NULL UNIQUE,
    `client_name` VARCHAR(255) NOT NULL,
    `client_phone` VARCHAR(20) DEFAULT NULL,
    `client_email` VARCHAR(255) DEFAULT NULL,
    `client_address` TEXT DEFAULT NULL,
    `device_model` VARCHAR(255) NOT NULL DEFAULT 'Не указано',
    `device_type` VARCHAR(100) DEFAULT NULL,
    `device_serial` VARCHAR(100) DEFAULT NULL,
    `device_imei` VARCHAR(20) DEFAULT NULL,
    `problem_description` TEXT NOT NULL,
    `device_password` VARCHAR(100) DEFAULT NULL,
    `device_pattern` VARCHAR(100) DEFAULT NULL,
    `device_condition` VARCHAR(255) DEFAULT NULL,
    `accessories` TEXT DEFAULT NULL,
    `estimated_cost` DECIMAL(10,2) DEFAULT 0.00,
    `estimated_time` VARCHAR(100) DEFAULT NULL,
    `warranty_period` VARCHAR(100) DEFAULT NULL,
    `client_signature` TEXT DEFAULT NULL,
    `master_signature` TEXT DEFAULT NULL,
    `status` ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
    `priority` ENUM('low','normal','high','urgent') DEFAULT 'normal',
    `deleted` TINYINT(1) DEFAULT 0,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. ТАБЛИЦА КВИТАНЦИЙ (RECEIPTS) - ПОЛНАЯ СТРУКТУРА
CREATE TABLE `receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` VARCHAR(255) NOT NULL UNIQUE,
    `client_name` VARCHAR(255) NOT NULL,
    `client_phone` VARCHAR(20) DEFAULT NULL,
    `client_email` VARCHAR(255) DEFAULT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(100) DEFAULT NULL,
    `services_rendered` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `client_signature` TEXT DEFAULT NULL,
    `status` ENUM('pending','completed','cancelled') DEFAULT 'completed',
    `deleted` TINYINT(1) DEFAULT 0,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 3. ТАБЛИЦА ОТЧЁТОВ (REPORTS) - ПОЛНАЯ СТРУКТУРА
CREATE TABLE `reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` VARCHAR(255) NOT NULL UNIQUE,
    `client_name` VARCHAR(255) NOT NULL,
    `client_phone` VARCHAR(20) DEFAULT NULL,
    `client_email` VARCHAR(255) DEFAULT NULL,
    `device_model` VARCHAR(255) NOT NULL DEFAULT 'Не указано',
    `device_serial` VARCHAR(100) DEFAULT NULL,
    `device_type` VARCHAR(100) DEFAULT NULL,
    `problem_description` TEXT DEFAULT NULL,
    `device_password` VARCHAR(100) DEFAULT NULL,
    `device_condition` VARCHAR(255) DEFAULT NULL,
    `accessories` TEXT DEFAULT NULL,
    `diagnosis` TEXT NOT NULL,
    `recommendations` TEXT DEFAULT NULL,
    `repair_cost` DECIMAL(10,2) DEFAULT NULL,
    `repair_time` VARCHAR(100) DEFAULT NULL,
    `warranty` VARCHAR(100) DEFAULT NULL,
    `report_type` VARCHAR(50) DEFAULT 'general',
    `priority` ENUM('low','normal','high','urgent') DEFAULT 'normal',
    `status` ENUM('pending','in_progress','completed','cancelled') DEFAULT 'completed',
    `deleted` TINYINT(1) DEFAULT 0,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 4. ТАБЛИЦА СКЛАДА (INVENTORY) - ПОЛНАЯ СТРУКТУРА
CREATE TABLE `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `min_stock` INT NOT NULL DEFAULT 5,
    `category` VARCHAR(100) DEFAULT 'other',
    `sku` VARCHAR(100) UNIQUE,
    `compatibility` TEXT DEFAULT NULL,
    `cost_price` DECIMAL(10,2) DEFAULT 0.00,
    `sell_price` DECIMAL(10,2) DEFAULT 0.00,
    `location` VARCHAR(255) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 5. ТАБЛИЦА ИСТОРИИ ДОКУМЕНТОВ (DOCUMENT_HISTORY)
CREATE TABLE `document_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` VARCHAR(255) NOT NULL,
    `document_type` VARCHAR(50) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `changes` JSON DEFAULT NULL,
    `user_id` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. ИНДЕКСЫ ДЛЯ БЫСТРОГО ПОИСКА
-- Orders indexes
CREATE INDEX idx_orders_document_id ON orders(document_id);
CREATE INDEX idx_orders_client_name ON orders(client_name);
CREATE INDEX idx_orders_client_phone ON orders(client_phone);
CREATE INDEX idx_orders_client_email ON orders(client_email);
CREATE INDEX idx_orders_device_model ON orders(device_model);
CREATE INDEX idx_orders_device_type ON orders(device_type);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_priority ON orders(priority);
CREATE INDEX idx_orders_deleted ON orders(deleted);
CREATE INDEX idx_orders_is_deleted ON orders(is_deleted);
CREATE INDEX idx_orders_date_created ON orders(date_created);

-- Receipts indexes
CREATE INDEX idx_receipts_document_id ON receipts(document_id);
CREATE INDEX idx_receipts_client_name ON receipts(client_name);
CREATE INDEX idx_receipts_client_phone ON receipts(client_phone);
CREATE INDEX idx_receipts_client_email ON receipts(client_email);
CREATE INDEX idx_receipts_status ON receipts(status);
CREATE INDEX idx_receipts_deleted ON receipts(deleted);
CREATE INDEX idx_receipts_is_deleted ON receipts(is_deleted);
CREATE INDEX idx_receipts_date_created ON receipts(date_created);

-- Reports indexes
CREATE INDEX idx_reports_document_id ON reports(document_id);
CREATE INDEX idx_reports_client_name ON reports(client_name);
CREATE INDEX idx_reports_client_phone ON reports(client_phone);
CREATE INDEX idx_reports_client_email ON reports(client_email);
CREATE INDEX idx_reports_device_model ON reports(device_model);
CREATE INDEX idx_reports_device_type ON reports(device_type);
CREATE INDEX idx_reports_status ON reports(status);
CREATE INDEX idx_reports_priority ON reports(priority);
CREATE INDEX idx_reports_deleted ON reports(deleted);
CREATE INDEX idx_reports_is_deleted ON reports(is_deleted);
CREATE INDEX idx_reports_date_created ON reports(date_created);

-- Inventory indexes
CREATE INDEX idx_inventory_name ON inventory(name);
CREATE INDEX idx_inventory_category ON inventory(category);
CREATE INDEX idx_inventory_sku ON inventory(sku);
CREATE INDEX idx_inventory_quantity ON inventory(quantity);
CREATE INDEX idx_inventory_min_stock ON inventory(min_stock);

-- Document history indexes
CREATE INDEX idx_document_history_document_id ON document_history(document_id);
CREATE INDEX idx_document_history_document_type ON document_history(document_type);
CREATE INDEX idx_document_history_action ON document_history(action);
CREATE INDEX idx_document_history_created_at ON document_history(created_at);

-- 7. ТРИГГЕРЫ ДЛЯ АВТОМАТИЧЕСКОГО ЛОГИРОВАНИЯ
DELIMITER $$

CREATE TRIGGER orders_after_insert
AFTER INSERT ON orders
FOR EACH ROW
BEGIN
    INSERT INTO document_history (document_id, document_type, action, changes, user_id)
    VALUES (NEW.document_id, 'order', 'create', JSON_OBJECT('status', NEW.status), 'system');
END$$

CREATE TRIGGER orders_after_update
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    INSERT INTO document_history (document_id, document_type, action, changes, user_id)
    VALUES (NEW.document_id, 'order', 'update', JSON_OBJECT('old_status', OLD.status, 'new_status', NEW.status), 'system');
END$$

CREATE TRIGGER receipts_after_insert
AFTER INSERT ON receipts
FOR EACH ROW
BEGIN
    INSERT INTO document_history (document_id, document_type, action, changes, user_id)
    VALUES (NEW.document_id, 'receipt', 'create', JSON_OBJECT('status', NEW.status), 'system');
END$$

CREATE TRIGGER reports_after_insert
AFTER INSERT ON reports
FOR EACH ROW
BEGIN
    INSERT INTO document_history (document_id, document_type, action, changes, user_id)
    VALUES (NEW.document_id, 'report', 'create', JSON_OBJECT('status', NEW.status), 'system');
END$$

DELIMITER ;

-- 8. ВСТАВКА ТЕСТОВЫХ ДАННЫХ
INSERT INTO orders (document_id, client_name, client_phone, client_email, device_model, problem_description, status) 
VALUES ('TEST-001', 'Тестовый Клиент', '+358401234567', 'test@example.com', 'iPhone 13', 'Не включается', 'pending');

INSERT INTO receipts (document_id, client_name, client_phone, total_amount, payment_method, status)
VALUES ('RECEIPT-001', 'Тестовый Клиент', '+358401234567', 150.00, 'Наличные', 'completed');

INSERT INTO reports (document_id, client_name, device_model, diagnosis, status)
VALUES ('REPORT-001', 'Тестовый Клиент', 'iPhone 13', 'Заменить аккумулятор', 'completed');

-- 9. СОЗДАНИЕ ПОЛЬЗОВАТЕЛЕЙ ДЛЯ АВТОРИЗАЦИИ
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','master','user') DEFAULT 'user',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_login` TIMESTAMP NULL
);

-- Вставка администратора по умолчанию
INSERT INTO users (username, password, email, role) 
VALUES ('admin', MD5('admin123'), 'admin@fixarivan.space', 'admin')
ON DUPLICATE KEY UPDATE password = MD5('admin123');

-- 10. НАСТРОЙКИ СИСТЕМЫ
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Вставка настроек по умолчанию
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('company_name', 'FixariVan', 'Название компании'),
('company_address', 'Ул. Примерная, 123, Город, Страна', 'Адрес компании'),
('company_phone', '+358 40 123 4567', 'Телефон компании'),
('company_email', 'info@fixarivan.space', 'Email компании'),
('company_website', 'fixarivan.space', 'Сайт компании'),
('company_y_tunnus', '1234567-8', 'Y-tunnus компании'),
('default_currency', 'EUR', 'Валюта по умолчанию'),
('pdf_template', 'universal', 'Шаблон PDF'),
('backup_enabled', '1', 'Включить автоматические бэкапы')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ФИНАЛЬНАЯ ПРОВЕРКА
SELECT 'Database structure created successfully!' as status;
SELECT COUNT(*) as orders_count FROM orders;
SELECT COUNT(*) as receipts_count FROM receipts;
SELECT COUNT(*) as reports_count FROM reports;
SELECT COUNT(*) as inventory_count FROM inventory;
SELECT COUNT(*) as users_count FROM users;
