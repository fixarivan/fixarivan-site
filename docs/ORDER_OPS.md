# Order operations — удаление заявок и завершение по счёту

## Почему пропали документы (инцидент 2026-09-16)

**Данные не удалялись из SQLite.** Пропали из интерфейса из‑за ошибки API:

1. В `documents_query.php` добавили SQL `WHERE deleted_at IS NULL ...`
2. При деплое возможна гонка: новый PHP уже с фильтром, колонка `deleted_at` ещё не создана (opcache / порядок файлов)
3. SQLite: `no such column: deleted_at` → исключение в `get_all_documents.php`
4. Ответ: `{ "success": false, "message": "..." }` или битый JSON (PHP warning)
5. Track/дашборд: `pickRows()` → пустой список, в консоли «JSON» / ошибка парсинга

**Исправление v2:**
- `schema_archive.php` — проверка колонки через `PRAGMA table_info` перед фильтром
- Fallback: при ошибке запроса — повтор без `WHERE deleted_at`
- PHP-фильтр `fixarivan_order_row_is_archived()` как второй барьер
- Архивация **только** ставит `deleted_at`, не меняет `public_status` / `order_status`

## API

`POST /api/order_ops.php` (admin session)

| action | Описание |
|---|---|
| `preview` | Проверка удаления |
| `delete` | Soft-delete (deleted_at) |
| `bulk_delete` | Массовое архивирование |
| `complete_paid` | Счёт paid + delivered |
| `reopen` | Вернуть in_progress |

## Откат

Git revert + `[deploy]`. Данные в `storage/fixarivan.sqlite` не трогаются.

Восстановить одну заявку:
```sql
UPDATE orders SET deleted_at=NULL, deleted_by=NULL, archive_reason=NULL WHERE document_id='ORD-...';
```
