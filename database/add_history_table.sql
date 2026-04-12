-- Добавление таблицы истории изменений документов
CREATE TABLE IF NOT EXISTS document_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id VARCHAR(50) NOT NULL,
    document_type ENUM('order', 'receipt', 'report') NOT NULL,
    action ENUM('create', 'update', 'delete', 'export') NOT NULL,
    changes JSON,
    user_id VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_id (document_id),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id)
);

-- Добавление поля deleted в существующие таблицы
ALTER TABLE orders ADD COLUMN IF NOT EXISTS deleted TINYINT(1) DEFAULT 0;
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS deleted TINYINT(1) DEFAULT 0;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS deleted TINYINT(1) DEFAULT 0;

-- Добавление поля date_updated в существующие таблицы
ALTER TABLE orders ADD COLUMN IF NOT EXISTS date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Обновление индексов для оптимизации
CREATE INDEX IF NOT EXISTS idx_orders_deleted ON orders(deleted);
CREATE INDEX IF NOT EXISTS idx_receipts_deleted ON receipts(deleted);
CREATE INDEX IF NOT EXISTS idx_reports_deleted ON reports(deleted);
