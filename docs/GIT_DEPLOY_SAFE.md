# Git, GitHub и деплой без потери данных (FixariVan)

## Где лежат данные

| Что | Где |
|-----|-----|
| Основная БД | `storage/fixarivan.sqlite` (или `FIXARIVAN_SQLITE_STORAGE_DIR` в `config.local.php`) |
| Токены viewer, JSON заказов/квитанций | `storage/**` (`.json`, каталоги `orders_tokens`, и т.д.) |
| Секреты, пароли MySQL (legacy), админ | `config.local.php`, при необходимости `storage/admin_auth.json` |
| Защита каталога storage от прямого доступа | `storage/.htaccess` — **должен оставаться в репозитории** |

## Что в Git, что нет

- **В репозитории:** код PHP/HTML/JS/CSS, `config.example.php`, `storage/.htaccess`, документация.
- **Не в репозитории** (см. `.gitignore`): `config.local.php`, `*.sqlite`, пользовательские `storage/**/*.json`, архивы `.zip`, локальный `/vendor/` при Composer.

## Безопасно обновлять через Git / pull

- Исходники приложения: `api/*.php` (кроме локальных оверрайдов), `*.html`, `js/`, `css/`, `client_portal.php`, `index.php`, и т.д.
- Новые миграции схемы SQLite выполняются кодом при первом подключении (`ensureSqliteSchema`) — **существующие данные не удаляются**, добавляются только таблицы/колонки по логике в `api/sqlite.php`.

## На проде не затирать и не коммитить

- **`storage/fixarivan.sqlite`** и весь **user-generated** контент в `storage/`.
- **`config.local.php`** — свой на каждом окружении; бэкап перед правками.
- Не выкладывать в публичный репозиторий **пароли**, **API-ключи**, **дампы БД**.

## Первый push при уже существующем репозитории

Если `config.local.php` или `storage/*.sqlite` уже попали в индекс Git:

```bash
git rm --cached config.local.php
git rm -r --cached storage/   # осторожно: сначала убедитесь, что .gitignore настроен
```

Дальше закоммить только код; на сервере после `git pull` файлы данных остаются на диске, если их **не удаляете** вручную и **не** делаете деплой «чистой копией» поверх без бэкапа.

## Деплой на cPanel

1. Полный бэкап: `storage/` + `config.local.php`.
2. `git pull` в каталог сайта **или** CI → SFTP только отслеживаемых путей (без `storage/` и без `config.local.php`).
3. Проверка прав на каталог SQLite (запись веб-сервером).

## Токены клиентов

Токены хранятся в БД и в JSON в `storage/`; обновление **кода** из Git их не аннулирует. Не перезаписывайте файлы БД и токенов из архива разработки поверх прода.
