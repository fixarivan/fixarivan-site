-- Создание таблицы document_history
CREATE TABLE IF NOT EXISTS `document_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` varchar(50) NOT NULL,
  `document_type` enum('order','receipt','report') NOT NULL,
  `action` varchar(50) NOT NULL,
  `changes` text,
  `user_id` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `document_id` (`document_id`),
  KEY `document_type` (`document_type`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
