# FixariVan — состояние проекта и известные моменты

Актуально для ветки разработки; перед деплоем сверяйте с чеклистом на хостинге.

## Исправлено / зафиксировано в коде

| Проблема | Решение |
|----------|---------|
| Ссылки на удалённые `api/test_db.php`, `api/test_database_structure.php` | Страницы `admin_panel.html`, `admin_clear.html`, `admin_clear_link.html` переведены на `api/health.php` (без сессии) и `api/db_status.php` (нужна админ-сессия для подсчёта документов). |
| Каталог `api/index.php` указывал на несуществующие тестовые эндпоинты | Удалены записи `test_database` / `check_structure`; добавлена группа `notes` → `notes.php`. |
| Диагностические скрипты в корне | Не включать в прод: `php_probe.php`, `integrity_check.php`, `test_*.php` — удалены из репозитория; на сервере при необходимости удалить вручную. |

## Удалённые файлы (не восстанавливать в прод без необходимости)

- `php_probe.php`, `integrity_check.php`
- `test_system.php`, `test_simple.php`, `test_db_connection.php`
- `api/test_db.php`, `api/test_database_structure.php`

## Поведение legacy-страниц

- **`admin_panel.html`** — проверка БД через `health.php` (без входа в админку).
- **`admin_clear.html`** — статистика через `db_status.php` с `credentials: 'same-origin'`; при истекшей сессии покажет ошибку — войти через `admin/login.php`.
- **`admin_clear_link.html`** — «Проверка БД» требует сессии; «Тест системы» использует только `health.php`.

Основной рабочий интерфейс: **`index.php` → `dashboard_app.html`** (сессия обязательна).

## Рекомендации перед cPanel / продом

1. **`config.local.php`** из `config.example.php`, сильный пароль; после смены пароля в настройках — `storage/admin_auth.json` (bcrypt).
2. **HTTPS**, редирект HTTP→HTTPS; для сессий на HTTPS желательно `session.cookie_secure` (см. обсуждение в аудите).
3. **Права**: `storage/` доступен для записи веб-сервером; `config.local.php` и `storage/admin_auth.json` — по возможности `600`.
4. **PHP**: расширения `pdo_sqlite`, `mbstring`; лимиты через INI/`.user.ini`, не только `.htaccess` на PHP-FPM.
5. **Бэкапы**: cron на копию `storage/` (в т.ч. `fixarivan.sqlite`) вне сервера.

## Модель доступа

См. **[ACCESS_MODEL.md](ACCESS_MODEL.md)** — публичные viewer’ы по токену, админ по PHP-сессии, PDF по сессии или `clientToken`.

## Известные ограничения (не баги, а контекст)

- **SQLite** на shared-хостинге: при редких одновременных записях возможны кратковременные блокировки (WAL + `busy_timeout` уже в `sqlite.php`).
- **Старые страницы** `admin_*.html` не дублируют полную защиту дашборда; для критичных операций используйте вход в админку и основной UI.
- **MySQL** в `config.php` — legacy; рабочие данные в **SQLite** (`storage/fixarivan.sqlite`).

---

*Обновляйте этот файл при смене критичных эндпоинтов или политики деплоя.*
