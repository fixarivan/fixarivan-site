# База данных FixariVan.space

## Информация о подключении

- **Хост:** localhost
- **База данных:** fixawcab_docs
- **Пользователь:** fixawcab_admin
- **Пароль:** FORD_HP_2025

## Структура базы данных

### Таблица `orders` (Акты приёма)
Хранит информацию о принятых устройствах на диагностику/ремонт.

**Основные поля:**
- `document_id` - Уникальный номер акта (ORD-XXXXXX)
- `client_name` - ФИО клиента
- `client_phone` - Телефон клиента
- `device_type` - Тип устройства
- `device_model` - Модель устройства
- `device_password` - Пароль устройства
- `pattern_data` - Графический ключ (JSON)
- `external_appearance` - Внешний вид и комплектация
- `confirmation` - Подтверждение клиента
- `signature_data` - Подпись клиента (base64)
- `status` - Статус (pending, in_progress, completed, cancelled)

### Таблица `receipts` (Квитанции)
Хранит информацию о платежах и выполненных услугах.

**Основные поля:**
- `document_id` - Уникальный номер квитанции (REC-XXXXXX)
- `client_name` - ФИО клиента
- `payment_amount` - Сумма платежа
- `payment_method` - Способ оплаты
- `services` - Список услуг (JSON)
- `technician_name` - ФИО мастера (по умолчанию: Sergeev Viacheslav)
- `signature_data` - Подпись мастера (base64)

### Таблица `reports` (Отчёты диагностики)
Хранит отчёты диагностики мобильных устройств и ПК/ноутбуков.

**Основные поля:**
- `document_id` - Уникальный номер отчёта (REP-XXXXXX)
- `report_type` - Тип отчёта (mobile/pc)
- `client_name` - ФИО клиента
- `device_rating` - Общая оценка устройства (1-10 звёзд)
- `condition_rating` - Оценка внешнего состояния (1-10 звёзд)
- `component_tests` - Результаты тестов компонентов (JSON)
- `battery_capacity` - Ёмкость аккумулятора (для мобильных)
- `battery_status` - Состояние аккумулятора
- `battery_replacement` - Требуется замена АКБ
- `cpu_temp`, `gpu_temp`, `disk_temp`, `ambient_temp` - Температуры (для ПК)
- `unique_code` - Уникальный код отчёта с датой и временем
- `technician_name` - ФИО мастера

## Статистика

База данных автоматически собирает статистику через представление `statistics_overview`:

- Общее количество актов, квитанций, отчётов
- Количество актов по статусам (pending, in_progress, completed)
- Общая выручка (сумма всех квитанций)
- Количество отчётов по типам (mobile/pc)
- Статистика за сегодня

## Использование

### Для создания базы данных:
1. Войдите в phpMyAdmin
2. Выберите базу данных `fixawcab_docs`
3. Импортируйте файл `schema.sql`

### Для получения статистики:
```sql
SELECT * FROM statistics_overview;
```

### Для получения последних документов:
```sql
-- Последние 10 актов
SELECT document_id, client_name, device_model, status, created_at 
FROM orders 
ORDER BY created_at DESC 
LIMIT 10;

-- Последние 10 квитанций
SELECT document_id, client_name, payment_amount, created_at 
FROM receipts 
ORDER BY created_at DESC 
LIMIT 10;

-- Последние 10 отчётов
SELECT document_id, client_name, device_model, report_type, created_at 
FROM reports 
ORDER BY created_at DESC 
LIMIT 10;
```

## Кодировка

Все таблицы используют кодировку `utf8mb4` с коллацией `utf8mb4_unicode_ci` для корректного отображения русского языка (кириллицы).

