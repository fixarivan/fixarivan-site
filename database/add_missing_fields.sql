-- Добавление недостающих полей в базу данных
-- Выполнить этот SQL в phpMyAdmin для исправления структуры БД

-- Добавляем недостающие поля в таблицу orders
ALTER TABLE orders ADD COLUMN IF NOT EXISTS device_type VARCHAR(50);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS location VARCHAR(255);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS language VARCHAR(10) DEFAULT 'ru';
ALTER TABLE orders ADD COLUMN IF NOT EXISTS pattern_data LONGTEXT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS signature_data LONGTEXT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS priority VARCHAR(20) DEFAULT 'normal';
ALTER TABLE orders ADD COLUMN IF NOT EXISTS acceptance_date DATE;

-- Добавляем недостающие поля в таблицу receipts
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(10,2);
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS payment_date DATE;
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS receipt_date DATE;
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS notes TEXT;
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS signature_data LONGTEXT;
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS language VARCHAR(10) DEFAULT 'ru';
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS technician_name VARCHAR(255);

-- Добавляем недостающие поля в таблицу reports
ALTER TABLE reports ADD COLUMN IF NOT EXISTS serial_number VARCHAR(100);
ALTER TABLE reports ADD COLUMN IF NOT EXISTS selected_tests JSON;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS test_results TEXT;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS language VARCHAR(10) DEFAULT 'ru';
ALTER TABLE reports ADD COLUMN IF NOT EXISTS report_type VARCHAR(50) DEFAULT 'mobile';

-- Создаем индексы для оптимизации
CREATE INDEX IF NOT EXISTS idx_orders_document_id ON orders(document_id);
CREATE INDEX IF NOT EXISTS idx_orders_client_phone ON orders(client_phone);
CREATE INDEX IF NOT EXISTS idx_receipts_document_id ON receipts(document_id);
CREATE INDEX IF NOT EXISTS idx_reports_document_id ON reports(document_id);

-- Обновляем кодировку таблиц
ALTER TABLE orders CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE receipts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE reports CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
