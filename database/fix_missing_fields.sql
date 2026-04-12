-- ИСПРАВЛЕНИЕ НЕДОСТАЮЩИХ ПОЛЕЙ В БД
-- Выполнить этот SQL в phpMyAdmin для исправления структуры БД

-- 1. ДОБАВЛЯЕМ НЕДОСТАЮЩИЕ ПОЛЯ В ТАБЛИЦУ orders
ALTER TABLE orders ADD COLUMN IF NOT EXISTS client_signature LONGTEXT COMMENT 'Подпись клиента';

-- 2. ДОБАВЛЯЕМ НЕДОСТАЮЩИЕ ПОЛЯ В ТАБЛИЦУ receipts  
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS notes TEXT COMMENT 'Дополнительные заметки';
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS client_signature LONGTEXT COMMENT 'Подпись клиента';

-- 3. ДОБАВЛЯЕМ НЕДОСТАЮЩИЕ ПОЛЯ В ТАБЛИЦУ reports
ALTER TABLE reports ADD COLUMN IF NOT EXISTS repair_cost DECIMAL(10,2) DEFAULT 0 COMMENT 'Стоимость ремонта';
ALTER TABLE reports ADD COLUMN IF NOT EXISTS repair_time INT DEFAULT 0 COMMENT 'Время ремонта в часах';
ALTER TABLE reports ADD COLUMN IF NOT EXISTS warranty INT DEFAULT 0 COMMENT 'Гарантия в днях';

-- 4. УДАЛЯЕМ ТЕСТОВЫЕ ДАННЫЕ (если они есть)
DELETE FROM orders WHERE client_name IN ('Иван Петров', 'Мария Сидорова', 'Алексей Козлов');
DELETE FROM receipts WHERE client_name IN ('Иван Петров', 'Мария Сидорова', 'Алексей Козлов');
DELETE FROM reports WHERE client_name IN ('Иван Петров', 'Мария Сидорова', 'Алексей Козлов');

-- 5. ПРОВЕРЯЕМ СТРУКТУРУ ТАБЛИЦ
DESCRIBE orders;
DESCRIBE receipts; 
DESCRIBE reports;

-- 6. ПРОВЕРЯЕМ ЧТО ТЕСТОВЫЕ ДАННЫЕ УДАЛЕНЫ
SELECT COUNT(*) as total_orders FROM orders;
SELECT COUNT(*) as total_receipts FROM receipts;
SELECT COUNT(*) as total_reports FROM reports;