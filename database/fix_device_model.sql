-- ИСПРАВЛЕНИЕ SQL СХЕМЫ: device_model DEFAULT значение
-- Выполнить в phpMyAdmin для исправления ошибки device_model cannot be null

-- 1. Добавляем DEFAULT значение для device_model в таблице reports
ALTER TABLE reports MODIFY COLUMN device_model VARCHAR(255) NOT NULL DEFAULT 'Не указано';

-- 2. Обновляем существующие NULL значения
UPDATE reports SET device_model = 'Не указано' WHERE device_model IS NULL OR device_model = '';

-- 3. Проверяем результат
SELECT COUNT(*) as total_reports, 
       COUNT(CASE WHEN device_model IS NULL THEN 1 END) as null_device_model
FROM reports;

-- 4. Аналогично для таблицы orders (если есть поле device_model)
-- ALTER TABLE orders MODIFY COLUMN device_model VARCHAR(255) NOT NULL DEFAULT 'Не указано';
-- UPDATE orders SET device_model = 'Не указано' WHERE device_model IS NULL OR device_model = '';

-- 5. Создаем индекс для оптимизации
CREATE INDEX idx_device_model ON reports(device_model);

-- 6. Проверяем структуру таблицы
DESCRIBE reports;
