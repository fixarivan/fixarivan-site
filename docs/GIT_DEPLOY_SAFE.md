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

## GitHub Actions

- **Быстрый деплой (рекомендуется):** если на сервере есть каталог **`/home/fixawcab/fixarivan-site`** с **`git`** и настроенным доступом к GitHub (`git fetch` работает по SSH или HTTPS), CI делает только **`git fetch` / `reset` на `main`** и запускает **`deploy.sh`** — обычно **до минуты**.
- **Медленный fallback:** если клона нет — полная загрузка checkout в **`/home/fixawcab/tmp_deploy`** через **scp** (несколько минут).

**Один раз на сервере** (SSH под пользователем сайта), чтобы включить быстрый путь:

```bash
cd ~
git clone git@github.com:fixarivan/fixarivan-site.git fixarivan-site
# либо HTTPS: git clone https://github.com/fixarivan/fixarivan-site.git fixarivan-site
cd fixarivan-site && git checkout main
```

Для **`git@github.com:...`** добавьте на сервер **Deploy key** (read-only) в настройках репозитория GitHub → Settings → Deploy keys. После этого workflow сам выберет быстрый путь.

## Деплой на cPanel

1. Полный бэкап: `storage/` + `config.local.php`.
2. `git pull` в каталог сайта **или** CI → SFTP только отслеживаемых путей (без `storage/` и без `config.local.php`).
3. Проверка прав на каталог SQLite (запись веб-сервером).

## Токены клиентов

Токены хранятся в БД и в JSON в `storage/`; обновление **кода** из Git их не аннулирует. Не перезаписывайте файлы БД и токенов из архива разработки поверх прода.

## Если данные «пропали» после деплоя

**Почему так бывает:** раньше в CI делали `rm -rf …/*` в каталоге сайта — удалялась вся папка **`storage/`** вместе с **`fixarivan.sqlite`**. В репозитории БД нет (она в `.gitignore`). После этого PHP при первом запросе создаёт **новую пустую** SQLite — на дашборде нули, портал клиента показывает «документ недоступен».

**Текущий `deploy.sh` на сервере** не затирает `storage/` (синхронизация из git **исключает** `storage/` и `config.local.php`), и перед деплоем создаётся tar в `/home/fixawcab/backups/`.

### Восстановление `storage/` из бэкапа на сервере

1. По SSH зайти на сервер и посмотреть архивы:
   ```bash
   ls -lt /home/fixawcab/backups/fixarivan_*.tar.gz
   ```
2. Выберите файл **до** инцидента (по дате в имени).
3. Восстановите только каталог данных (пример путей):
   ```bash
   ARCH=/home/fixawcab/backups/fixarivan_YYYY-MM-DD_HH-MM-SS.tar.gz
   TMP=$(mktemp -d)
   tar -xzf "$ARCH" -C "$TMP"
   LIVE=/home/fixawcab/public_html/fixarivan.space
   mkdir -p "$LIVE/storage"
   cp -a "$TMP/fixarivan.space/storage/." "$LIVE/storage/"
   rm -rf "$TMP"
   ```
   Либо используйте скрипт: `tools/restore_storage_from_backup.sh` (см. файл в репозитории).

4. Проверьте права: каталог `storage/` должен быть доступен веб-серверу на запись.

**Дополнительно:** в `config.local.php` можно задать **`FIXARIVAN_SQLITE_STORAGE_DIR`** — вынести `fixarivan.sqlite` **вне** `public_html`, чтобы будущие ошибки деплоя не затрагивали файл БД.

### Скрипт

См. **`tools/restore_storage_from_backup.sh`** — безопасное копирование `storage/` из выбранного `fixarivan_*.tar.gz` в живой сайт.
