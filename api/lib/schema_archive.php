<?php
declare(strict_types=1);

/** Проверка наличия колонки (без падения запросов до миграции). */
function fixarivan_sqlite_column_exists(PDO $pdo, string $table, string $column): bool
{
    $table = trim($table);
    $column = trim($column);
    if ($table === '' || $column === '') {
        return false;
    }
    $stmt = $pdo->query("PRAGMA table_info('" . str_replace("'", "''", $table) . "')");
    if ($stmt === false) {
        return false;
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

/** Идемпотентная миграция archive + audit. Вызывать из ensureSqliteSchema. */
function fixarivan_schema_ensure_archive(PDO $pdo): void
{
    if (!fixarivan_sqlite_column_exists($pdo, 'orders', 'deleted_at')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN deleted_at TEXT');
    }
    if (!fixarivan_sqlite_column_exists($pdo, 'orders', 'deleted_by')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN deleted_by TEXT');
    }
    if (!fixarivan_sqlite_column_exists($pdo, 'orders', 'archive_reason')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN archive_reason TEXT');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_deleted_at ON orders(deleted_at)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT UNIQUE,
            action TEXT NOT NULL,
            actor TEXT,
            entity_type TEXT,
            entity_id TEXT,
            payload_json TEXT,
            result_json TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_crm_audit_created ON crm_audit_log(created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_crm_audit_entity ON crm_audit_log(entity_type, entity_id)');
}

/** SQL-фрагмент WHERE для списков заказов. Пустая строка, если колонки ещё нет. */
function fixarivan_orders_archive_sql_filter(PDO $pdo): string
{
    if (!fixarivan_sqlite_column_exists($pdo, 'orders', 'deleted_at')) {
        return '';
    }

    return " WHERE (deleted_at IS NULL OR TRIM(deleted_at) = '')";
}

function fixarivan_order_row_is_archived(?array $row): bool
{
    if (!is_array($row)) {
        return false;
    }

    return trim((string) ($row['deleted_at'] ?? '')) !== '';
}
