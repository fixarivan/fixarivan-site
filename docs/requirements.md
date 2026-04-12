# FixariVan — server requirements (SQLite flow)

## PHP

- PHP 8.1+ recommended (project uses `declare(strict_types=1)` widely).
- Required extensions:
  - **pdo_sqlite** — primary datastore (`storage/fixarivan.sqlite`).
  - **dom** (php-xml) — Dompdf HTML rendering; without it, PDF generation falls back to HTML snapshot where implemented.
  - **mbstring** — recommended; polyfills exist in `api/lib/dompdf_mb_polyfills.php` but native mbstring is preferable.
- Optional:
  - **gd** — image rendering in PDFs if templates use images.
  - **zip** — for `tools/backup.php` archives.

## Data

- **SQLite** is the single source of truth; JSON under `storage/` is backup / fallback.
- Web server must allow **write** access to `storage/` (and execute read for SQLite WAL).

## Process

- Set timezone to **Europe/Helsinki** in PHP (already set in `config.php` entry points).

## Notes

- MySQL-related files in this repo are **legacy / deprecated**; production UI uses SQLite only.
