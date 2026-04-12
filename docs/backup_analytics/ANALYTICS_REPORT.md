# FixariVan — аналитический отчёт по доработкам (для ИИ)

**Дата отчёта:** 2026-03-27  
**Проект:** fixarivan.space (PHP + SQLite, HTML/JS, Dompdf)  
**Назначение документа:** контекст для восстановления понимания кода из архива бэкапа без доступа к git.

---

## 1. Счета (invoice pipeline)

### 1.1 Нумерация

- Новые номера: **`FV-YYYY-XXXX`** (автоинкремент), реализация: `api/lib/invoice_center.php` → `fixarivan_next_invoice_id()`.
- Старые записи **`INV-*`** в БД не переписываются; счётчик FV независим от префикса INV.

### 1.2 Нормализация и валидация до сохранения

- Модуль: **`api/lib/invoice_validation.php`** (подключает `invoice_center.php`).
- Десятичные строки: поддержка **`25,5`** и **`25.5`** → нормализация до float; коэрция ставки НДС около **0** и **25.5** (допуск ±0.05 для 25.5).
- **`fixarivan_invoice_normalize_input()`** — нормализует массив `items` перед записью.
- **`fixarivan_invoice_validate()`** — клиент, контакт (email или телефон), минимум одна строка с непустым именем; по строкам: qty > 0, price ≥ 0, НДС только **0** или **25.5**; проверка итогов через **`fixarivan_invoice_totals_from_items`** (не доверять суммам с фронта); дата оплаты не раньше даты счёта.
- Сохранение: **`api/save_invoice.php`** — сначала normalize + validate; при ошибке **HTTP 422** и массив `errors` с `code` / `field` / `row`.

### 1.3 Смешанный НДС (важно)

- **Не** вводить усреднённый % НДС по документу; **не** считать НДС одной глобальной суммой для строк.
- Итоги по строкам: **`fixarivan_invoice_totals_from_items`**, группы: **`fixarivan_invoice_vat_groups_by_rate`** в `invoice_center.php`.
- При наличии строк итоги в **`fixarivan_normalize_invoice_record()`** пересчитываются из позиций, поля `subtotal`/`tax_amount`/`total_amount` с фронта для этого случая не принимаются как источник истины.

### 1.4 PDF и печать

- HTML: **`api/lib/document_templates.php`** — `dt_render_document_html('invoice', ...)`, стили **`dt_css()`** (компактная вёрстка под A4: `@page`, уменьшенные отступы, таблица позиций, блок итогов **`.dt-inv-totals`** с выравниванием сумм вправо).
- Генерация PDF: **`api/generate_dompdf_fixed.php`** — язык: `dt_normalize_language($input['language'] ?? $payload['language'] ?? merged['language'])`.
- Юридическая строка: label **`invoice_legal_note`** в **`api/lib/invoice_i18n.php`** (ru/fi/en), вывод в секции итогов.

### 1.5 Язык документа (RU / FI / EN)

- В БД: колонка **`invoices.language`**; в форме: **`invoice.html`** — селектор `doc_language`, в payload **`language`**.
- Нормализация при записи: **`fixarivan_normalize_invoice_language()`** в `invoice_center.php` (только ru/fi/en).
- Viewer: **`invoice_view.php`** — по умолчанию язык из счёта; **`?lang=`** переопределяет отображение; селектор + подпись «сохранено в счёте»; PDF из viewer передаёт текущий язык.
- Словари: **`fixarivan_invoice_i18n_merge()`** дополняет `dt_translations()`; форма: **`fixarivan_invoice_i18n_for_form()`** / `invoice_i18n.php` API.

---

## 2. Форматирование валюты (EUR)

- PHP: **`api/lib/format_money.php`** → **`fixarivan_format_money()`**; **`dt_format_currency()`** в `document_templates.php` делегирует туда.
- Правила: FI/RU — `300,00 €`; EN — `€300.00` (тысячи через пробел, как `number_format`).
- JS: **`js/format_money.js`** — **`formatMoney(amount, lang)`** с теми же правилами; используется в `invoice.html`, `receipt.html`, `inventory.html` и др.

---

## 3. Клиентская форма счета

- Файл: **`invoice.html`** — нормализация чисел в строках, клиентская валидация, показ ошибок 422, скрытое поле даты счёта для проверки срока оплаты, интеграция с **`js/format_money.js`**.

---

## 4. Ограничения (не ломать)

- Не возвращать «средний» НДС % как основной показатель по документу.
- Не доверять итогам с фронта при сохранении (сервер пересчитывает из строк).
- Не ломать логику смешанного НДС в `invoice_center.php`.

---

## 5. Ключевые файлы (указатели)

| Область | Файлы |
|--------|--------|
| Валидация / нормализация счёта | `api/lib/invoice_validation.php` |
| Итоги, номер FV, normalize record | `api/lib/invoice_center.php` |
| Сохранение | `api/save_invoice.php` |
| Шаблоны PDF/HTML | `api/lib/document_templates.php` |
| i18n счёта | `api/lib/invoice_i18n.php` |
| Валюта PHP | `api/lib/format_money.php` |
| Валюта JS | `js/format_money.js` |
| Форма | `invoice.html` |
| Просмотр | `invoice_view.php` |
| PDF API | `api/generate_dompdf_fixed.php` |
| Квитанции PDF (отдельный пайп) | `api/lib/pdf_receipt_pipeline.php` |
| Бэкап | `tools/backup.php` |

---

## 6. Состав архива бэкапа (ожидаемый)

См. комментарии в **`tools/backup.php`**: SQLite, профиль компании, каталоги заказов/квитанций/отчётов/токенов, **JSON счетов `storage/invoices/`**, медиа счетов при наличии, **папка `docs/backup_analytics/`** с этим отчётом.

---

## 7. Как обновлять отчёт для следующего бэкапа

1. Внести изменения в **`docs/backup_analytics/ANALYTICS_REPORT.md`** (дата, новые модули, отменённые решения).
2. Запустить: `php tools/backup.php` (опционально каталог вывода первым аргументом).

Конец отчёта.
