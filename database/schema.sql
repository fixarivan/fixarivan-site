-- FixariVan Database Schema
-- Complete database structure for device repair management system

-- Note: Database already exists (fixawcab_spacefix)
-- Using existing database instead of creating new one

-- Orders table - main orders/acceptance acts
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id VARCHAR(50) UNIQUE NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    client_phone VARCHAR(50),
    client_email VARCHAR(255),
    client_address TEXT,
    device_model VARCHAR(255) NOT NULL,
    device_serial VARCHAR(100),
    device_imei VARCHAR(50),
    problem_description TEXT NOT NULL,
    device_password VARCHAR(255),
    device_pattern VARCHAR(255),
    device_condition TEXT,
    accessories TEXT,
    estimated_cost DECIMAL(10,2),
    estimated_time VARCHAR(50),
    warranty_period VARCHAR(50),
    client_signature LONGTEXT,
    master_signature LONGTEXT,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT 'Sergeev Viacheslav'
);

-- Receipts table - repair receipts
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id VARCHAR(50) UNIQUE NOT NULL,
    order_id INT,
    client_name VARCHAR(255) NOT NULL,
    client_phone VARCHAR(50),
    client_email VARCHAR(255),
    device_model VARCHAR(255) NOT NULL,
    services JSON,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    client_signature LONGTEXT,
    master_signature LONGTEXT,
    receipt_data JSON,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT 'Sergeev Viacheslav',
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Reports table - diagnostic reports
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id VARCHAR(50) UNIQUE NOT NULL,
    order_id INT,
    client_name VARCHAR(255) NOT NULL,
    device_model VARCHAR(255) NOT NULL,
    serial_number VARCHAR(100),
    problem_description TEXT NOT NULL,
    device_type VARCHAR(50),
    selected_tests JSON,
    test_results TEXT,
    diagnosis TEXT NOT NULL,
    recommendations TEXT NOT NULL,
    repair_cost DECIMAL(10,2),
    repair_time INT,
    warranty INT,
    technician VARCHAR(100) DEFAULT 'Sergeev Viacheslav',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Referral links table - for client pre-filling
CREATE TABLE IF NOT EXISTS referral_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(32) UNIQUE NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    client_phone VARCHAR(50),
    client_email VARCHAR(255),
    client_address TEXT,
    device_info JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    is_used BOOLEAN DEFAULT FALSE,
    created_by VARCHAR(100) DEFAULT 'Sergeev Viacheslav'
);

-- Indexes for better performance
CREATE INDEX idx_orders_document_id ON orders(document_id);
CREATE INDEX idx_orders_client_phone ON orders(client_phone);
CREATE INDEX idx_orders_date_created ON orders(date_created);
CREATE INDEX idx_orders_status ON orders(status);

CREATE INDEX idx_receipts_document_id ON receipts(document_id);
CREATE INDEX idx_receipts_order_id ON receipts(order_id);
CREATE INDEX idx_receipts_date_created ON receipts(date_created);

CREATE INDEX idx_reports_document_id ON reports(document_id);
CREATE INDEX idx_reports_order_id ON reports(order_id);
CREATE INDEX idx_reports_type ON reports(device_type);
CREATE INDEX idx_reports_date_created ON reports(date_created);

CREATE INDEX idx_referral_links_token ON referral_links(token);
CREATE INDEX idx_referral_links_expires ON referral_links(expires_at);
CREATE INDEX idx_referral_links_used ON referral_links(is_used);

-- Sample data for testing
INSERT INTO orders (
    document_id, client_name, client_phone, device_model, 
    problem_description, status
) VALUES (
    'ORD-2024-0001', 'Иван Петров', '+358401234567', 'iPhone 13',
    'Не включается, не реагирует на кнопки', 'pending'
);

INSERT INTO orders (
    document_id, client_name, client_phone, device_model,
    problem_description, status
) VALUES (
    'ORD-2024-0002', 'Мария Сидорова', '+358409876543', 'Samsung Galaxy S21',
    'Разбит экран, не работает тач', 'in_progress'
);

-- Create views for easier data access
CREATE VIEW order_summary AS
SELECT 
    o.id,
    o.document_id,
    o.client_name,
    o.client_phone,
    o.device_model,
    o.problem_description,
    o.status,
    o.date_created,
    r.total_amount,
    rep.diagnosis
FROM orders o
LEFT JOIN receipts r ON o.document_id = r.document_id
LEFT JOIN reports rep ON o.document_id = rep.document_id;

-- Create stored procedures for common operations
DELIMITER //

CREATE PROCEDURE GetOrderStatistics()
BEGIN
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM orders;
END //

CREATE PROCEDURE GetRecentOrders(IN limit_count INT)
BEGIN
    SELECT * FROM order_summary 
    ORDER BY date_created DESC 
    LIMIT limit_count;
END //

DELIMITER ;

-- Grant permissions (adjust as needed for your setup)
-- GRANT ALL PRIVILEGES ON fixarivan.* TO 'fixarivan_user'@'localhost' IDENTIFIED BY 'secure_password';
-- FLUSH PRIVILEGES;