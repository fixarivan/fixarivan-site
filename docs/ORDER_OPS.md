# Order operations — удаление заявок и завершение по счёту

## API

`POST /api/order_ops.php` (admin session)

| action | Описание |
|---|---|
| `preview` | Проверка возможности удаления |
| `delete` / `archive` | Soft-delete одной заявки |
| `bulk_delete` | Массовое архивирование |
| `complete_paid` | Отметить счёт оплаченным + статус delivered |
| `reopen` | Вернуть завершённый заказ в in_progress |

Параметры: `document_id`, `document_ids[]`, `delete_password`, `confirm_unpaid`, `request_id` (идемпотентность).

## Правила удаления

**Разрешено без пароля:** `pending_review`, `lead_collecting` без связанных документов.

**Требует пароль удаления:** прочие заказы без блокирующих связей.

**Заблокировано:** счета, квитанции, отчёты, подписанный акт, выданный заказ с финансами.

Soft-delete: `deleted_at`, `order_status=archived`, скрыт из Track.

## Миграция БД

`orders`: `deleted_at`, `deleted_by`, `archive_reason`  
Таблица: `crm_audit_log`

## Откат

1. Восстановить файлы из git до деплоя  
2. SQL (если нужно): `UPDATE orders SET deleted_at=NULL, deleted_by=NULL, order_status='pending_review' WHERE archive_reason='lead_removed'`  
3. `DROP TABLE crm_audit_log` — только если таблица мешает (данные аудита будут потеряны)

## Изменённые файлы

- `api/sqlite.php` — миграция
- `api/lib/crm_audit.php`, `api/lib/order_ops.php`
- `api/order_ops.php`
- `api/lib/documents_query.php` — фильтр archived
- `js/track_order_ops.js`
- `track.html` — UI

## Тесты (ручные)

1. Удалить пустую заявку pending_review  
2. Массово удалить 2–3 тестовых  
3. Клиент с 2 заказами — после удаления одного клиент остаётся  
4. Заказ со счётом — delete blocked  
5. complete_paid — счёт paid, заказ в «Завершённые»  
6. reopen — снова активный  
7. Повторный request_id — без дублей  
