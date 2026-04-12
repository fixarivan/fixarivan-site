-- ИСПРАВЛЕНИЕ ОТСУТСТВУЮЩИХ КОЛОНОК В БД
-- Добавляем все недостающие колонки

-- Таблица orders
ALTER TABLE orders 
ADD COLUMN IF NOT EXISTS `device_type` varchar(100) DEFAULT NULL AFTER `device_model`,
ADD COLUMN IF NOT EXISTS `priority` enum('low','normal','high','urgent') DEFAULT 'normal' AFTER `status`,
ADD COLUMN IF NOT EXISTS `deleted` tinyint(1) DEFAULT 0 AFTER `date_created`,
ADD COLUMN IF NOT EXISTS `is_deleted` tinyint(1) DEFAULT 0 AFTER `deleted`;

-- Таблица receipts  
ALTER TABLE receipts
ADD COLUMN IF NOT EXISTS `deleted` tinyint(1) DEFAULT 0 AFTER `date_created`,
ADD COLUMN IF NOT EXISTS `is_deleted` tinyint(1) DEFAULT 0 AFTER `deleted`;

-- Таблица reports
ALTER TABLE reports
ADD COLUMN IF NOT EXISTS `device_type` varchar(100) DEFAULT NULL AFTER `device_model`,
ADD COLUMN IF NOT EXISTS `priority` enum('low','normal','high','urgent') DEFAULT 'normal' AFTER `report_type`,
ADD COLUMN IF NOT EXISTS `deleted` tinyint(1) DEFAULT 0 AFTER `date_created`,
ADD COLUMN IF NOT EXISTS `is_deleted` tinyint(1) DEFAULT 0 AFTER `deleted`;

-- Обновляем индексы
CREATE INDEX IF NOT EXISTS idx_orders_deleted ON orders(deleted);
CREATE INDEX IF NOT EXISTS idx_orders_is_deleted ON orders(is_deleted);
CREATE INDEX IF NOT EXISTS idx_orders_priority ON orders(priority);

CREATE INDEX IF NOT EXISTS idx_receipts_deleted ON receipts(deleted);
CREATE INDEX IF NOT EXISTS idx_receipts_is_deleted ON receipts(is_deleted);

CREATE INDEX IF NOT EXISTS idx_reports_deleted ON reports(deleted);
CREATE INDEX IF NOT EXISTS idx_reports_is_deleted ON reports(is_deleted);
CREATE INDEX IF NOT EXISTS idx_reports_priority ON reports(priority);
