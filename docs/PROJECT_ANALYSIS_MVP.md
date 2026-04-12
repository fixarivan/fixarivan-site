# FixariVan — аналитика (Блок A) перед унификацией MVP

Дата: ориентир 2026. Цель: зафиксировать **как есть**, связи, разрывы и план без слепой реализации.

---

## 1. Как сейчас реализовано

### 1.1 Clients (`clients`)

- Таблица **`clients`**: `id`, `client_id` (строковый публичный ID), `full_name`, `phone`, `email`, `notes`, `created_at`, `updated_at`.
- API: **`api/clients.php`** — список, поиск, CRUD, удаление с обнулением `orders.client_id` / `invoices.client_id`.
- UI: **`clients.html`**.

### 1.2 Orders (`orders`)

- Таблица **`orders`**: акт приёма как центральная сущность: `document_id` (UNIQUE), **`order_id`** (UNIQUE, человекочитаемый), **`client_id`** (FK логический на `clients.id`), контакты **снимоком** (`client_name`, `client_phone`, `client_email`), устройство, статус, подпись, **`client_token`** для `order_view.php`.
- Дополнительно: **`parts_purchase_total`**, **`parts_sale_total`** — ручные суммы для финансов (не строки запчастей).
- Сохранение: **`api/save_order_fixed.php`**, JSON в `storage/orders/` как fallback.
- Публичный просмотр: **`order_view.php?token=`** (+ rate limit `viewer_token_guard`).

**Нет в схеме:** поля `type` (repair/sale/custom), `public_status`, `public_comment`, `public_expected_date` — из ТЗ блоков D/E/F.

### 1.3 Documents

| Тип | Таблица | Связь с заказом | Токен клиента |
|-----|---------|-----------------|---------------|
| Акт | = строка `orders` | сам заказ | `client_token` |
| Квитанция | `receipts` | `order_id` (опционально) | `client_token` |
| Счёт | `invoices` | `order_id`, `client_id` | `client_token` |
| Отчёт диагностики | `mobile_reports` | `order_id` | `token` |

Viewer-страницы: `receipt_view.php`, `invoice_view.php`, `report_view.php` — по токену; акт — `order_view.php`.

### 1.4 Parts / «order_parts»

- **Таблицы `order_parts` в проекте нет** (подтверждено в **`api/lib/finance_lib.php`** / заметках к отчётам).
- Запчасти под заказ:
  - текст **`supply_request`** / доп. поля в заказе (трек **`track.html`** → `save_order_supply.php`);
  - парсинг строк **`api/lib/order_supply.php`** → при синхронизации создаёт/обновляет **`inventory_items`** (по имени + категории), тег `[REQ order_id]` в `notes`, **не** ведёт отдельную таблицу строк заказа.
- Финансы по запчастям заказа: **вручную** `parts_purchase_total` / `parts_sale_total` на заказе.

### 1.5 Inventory («Космический склад»)

- **`inventory_items`**: `sku`, `name`, **`category`** (свободный текст), `compatibility`, `unit`, `min_stock`, **`default_cost`**, `notes`, даты.
- **`inventory_movements`**: движения, `unit_cost`, `ref_kind` / `ref_id`.
- **`inventory_balances`**: кэш остатков (триггер).
- API: **`api/inventory_list.php`** и др.; ожидает в JSON в основном **`default_cost`**, не `purchase_price`/`sale_price` как отдельные имена колонок.
- UI **`inventory.html`**: тяжёлый клиентский слой (в т.ч. **`costPrice`**, **`sellPrice`**, **`quantity`** в объекте позиции), **QR** (библиотека + отрисовка canvas), синхронизация через **`sync_inventory.php`** / local данные — **риск рассинхрона имён полей** с API (`default_cost` vs `costPrice`).

### 1.6 Track

- **`api/get_all_documents.php`** + **`api/lib/documents_query.php`**: плоский список документов из SQLite, группировка на клиенте по телефону/email/имени (`track.html`).
- Показ оплаты для квитанций/счетов — уже частично добавлен.

### 1.7 Token viewer

- Общие принципы: **`docs/ACCESS_MODEL.md`**.
- Проверка PDF: **`api/lib/pdf_request_auth.php`** (`hash_equals` с токеном в БД).
- Rate limit: **`api/lib/viewer_token_guard.php`**.

---

## 2. Где связи уже «правильные»

| Связь | Реализация |
|-------|------------|
| Client → Order | `orders.client_id` → `clients.id`; дублирование ФИО/телефона в заказе как снимок. |
| Order → Documents | `receipts.order_id`, `invoices.order_id`, `mobile_reports.order_id`. |
| Order → Parts (логика) | Только через текст заявки + sync в `inventory_items`, не через FK строк. |
| Parts → Inventory | `fixarivan_sync_supply_to_inventory` создаёт/обновляет позиции склада по строкам заявки. |
| Client → viewer | Разные токены на документ; **одной ссылки на всё** пока нет. |

---

## 3. Проблемы и разрывы

| # | Проблема | Следствие |
|---|----------|-----------|
| P1 | Нет таблицы строк запчастей заказа | Нельзя без доработок считать закупку/продажу по строкам; финансы опираются на **две ручные суммы** на заказе. |
| P2 | Дублирование данных клиента | `clients` + снимок в `orders` / документах — осознанный снимок, но при правке клиента легко расхождение. |
| P3 | Склад vs UI | В БД одна «себестоимость» (`default_cost`); в UI фигурируют `costPrice`/`sellPrice` — нужна явная карта полей при сохранении в SQLite (**возможный источник «цены = 0»**). |
| P4 | Нет `orders.type` | Все заказы ведут себя как «ремонтный» контур в UI (акт и т.д.). |
| P5 | Нет единого клиентского портала | Клиент получает **разные ссылки** на акт / квитанцию / счёт / отчёт. |
| P6 | Публичные поля статуса для клиента | Нет `public_status` / ожидаемой даты в одном месте для сценария «одна ссылка». |
| P7 | QR на складе | Занимает UI и зависимости; по ТЗ B2 — к удалению из интерфейса. |
| P8 | Категории склада | Свободный ввод; категория «Техника» — добавить в справочник/фильтр без ломки схемы. |

---

## 4. Что можно улучшить **без** ломки архитектуры

- Убрать QR из **`inventory.html`** (скрипт, canvas, кнопки) — backend можно не трогать.
- Добавить категорию **«Техника»** в выпадающий список / подсказки UI.
- Свести имена цен: при отправке в **`inventory_list.php`** маппить `costPrice` → `default_cost`; при необходимости колонка **`sale_price`** или хранение продажи в `notes`/отдельное поле — **малое** ALTER.
- Показ **purchase_total / sale_total / profit** по складу: агрегаты из `inventory_movements.unit_cost` и продажных цен (после определения модели цены продажи).
- Улучшить **track** / **clients** (фильтры, вёрстка) — только фронт + узкие API.

---

## 5. Что потребует **изменений** схемы / API

| Изменение | Минимально нужно |
|-----------|------------------|
| Единый **client_portal.php?token=** | Новый viewer + **единый токен заказа** или страница, собирающая документы по `order_id` при секретном токене (решение: один `portal_token` на заказ или повторное использование `client_token` заказа с осторожностью). |
| **orders.type** + сценарии sale/custom | `ALTER TABLE orders ADD COLUMN type TEXT`, валидация в `save_order_fixed.php`, условные шаги в UI. |
| Строки позиций заказа (название, qty, purchase, sale) | Новая таблица, например **`order_line_items`**, или JSON-поле на заказе — иначе «не двойной ввод» не закрыть формально. |
| **public_*** поля | `public_status`, `public_comment`, `public_expected_date` на `orders`. |
| Разделение склад/под заказ | Флаги на движениях или на позициях (`is_customer_order`), отчётный запрос — без обязательного ERP. |

---

## 6. План внедрения (предлагаемые фазы)

**Фаза 0 (завершить анализ на практике)**  
- Воспроизвести баг цен: сохранение позиции склада через UI → смотреть Network payload и строку в `inventory_items`.  
- Зафиксировать одну карту полей UI ↔ API.

**Фаза 1 — Склад MVP (B)**  
- Убрать QR из UI.  
- Категория «Техника».  
- Исправить сохранение цен (имена полей + при необходимости колонка продажи).  
- Дашборд сумм закупка/продажа/прибыль (формулы от текущих данных).

**Фаза 2 — Заказ как центр (E + F частично)**  
- `orders.type` + мастер-форма «Новый заказ» с выбором типа.  
- Позиции заказа (таблица или JSON) + участие в `finance`.

**Фаза 3 — Клиентский портал (D)**  
- `client_portal.php` + поля `public_*` + список документов по `order_id` без внутренних сумм.

**Фаза 4 — Track / Clients (G)**  
- Полировка списков и фильтров.

Безопасность (H) — на каждом этапе: не отдавать `purchase_price`/маржу в JSON портала; токены только `hash_equals`.

---

## 7. Ключевые файлы (для дальнейшего diff)

| Область | Файлы |
|---------|--------|
| Схема БД | `api/sqlite.php` |
| Заказ | `api/save_order_fixed.php`, `master_form.html`, `order_view.php` |
| Запчасти/заявка | `api/save_order_supply.php`, `api/lib/order_supply.php`, `track.html` |
| Финансы | `api/lib/finance_lib.php`, `api/finance.php`, `finance.html` |
| Склад | `api/inventory_list.php`, `inventory.html`, `api/sync_inventory.php` |
| Клиенты | `api/clients.php`, `clients.html` |
| Трек | `api/lib/documents_query.php`, `api/get_all_documents.php`, `track.html` |
| Токены / PDF | `api/lib/pdf_request_auth.php`, `api/lib/viewer_token_guard.php` |
| Документация | `docs/ACCESS_MODEL.md`, `docs/STATUS.md`, `docs/PROJECT_GUIDE.md` |

---

## 8. Diff

На этапе **только анализа** кодовый diff не формируется. После утверждения фазы 1 — отдельные MR/патчи по файлам из §7.

---

## 9. Краткий вывод

Архитектура **client → order → documents** уже есть; **строковых запчастей заказа в БД нет** — финансы и «не двойной ввод» для запчастей упираются в **ручные суммы** и текст заявки. Склад — отдельный контур с **`default_cost`** и риском путаницы имён с UI. Единый клиентский портал и типы заказов **требуют целенаправленных полей в `orders` и нового viewer**, без переписывания всей системы.

*Документ обновлять по мере принятия решений по токену портала и модели строк позиций.*
