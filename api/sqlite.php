<?php
declare(strict_types=1);

/**
 * SQLite is the single source of truth for FixariVan operational data (storage/fixarivan.sqlite).
 * JSON under storage/ is backup / fallback only.
 *
 * Опционально: в config.local.php можно задать FIXARIVAN_SQLITE_STORAGE_DIR — абсолютный путь
 * к каталогу с fixarivan.sqlite (например вне public_html на хостинге). Логика токенов/авторизации не меняется.
 */
require_once __DIR__ . '/lib/data_policy.php';

$__fixarivanLocalCfg = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.php';
if (is_readable($__fixarivanLocalCfg)) {
    require_once $__fixarivanLocalCfg;
}

function sqliteStorageDir(): string {
    if (defined('FIXARIVAN_SQLITE_STORAGE_DIR') && is_string(FIXARIVAN_SQLITE_STORAGE_DIR)) {
        $d = trim(FIXARIVAN_SQLITE_STORAGE_DIR);
        if ($d !== '') {
            return rtrim($d, '/\\');
        }
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
}

function sqliteDbPath(): string {
    return sqliteStorageDir() . DIRECTORY_SEPARATOR . 'fixarivan.sqlite';
}

function getSqliteConnection(): PDO {
    $dir = sqliteStorageDir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage directory');
    }

    $pdo = new PDO('sqlite:' . sqliteDbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    ensureSqliteSchema($pdo);
    return $pdo;
}

function ensureSqliteSchema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id TEXT NOT NULL UNIQUE,
            full_name TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            notes TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clients_phone ON clients(phone)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clients_email ON clients(email)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS mobile_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id TEXT NOT NULL UNIQUE,
            token TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL,
            client_name TEXT NOT NULL,
            phone TEXT NOT NULL,
            device_type TEXT,
            model TEXT,
            serial_number TEXT,
            tests_json TEXT,
            battery_json TEXT,
            diagnosis TEXT,
            recommendations TEXT,
            device_rating INTEGER,
            appearance_rating INTEGER,
            master_name TEXT,
            work_date TEXT,
            order_id TEXT,
            verification_code TEXT,
            raw_json TEXT NOT NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mobile_reports_token ON mobile_reports(token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mobile_reports_created_at ON mobile_reports(created_at)');

    // Orders & receipts (acts / kvitanziya) are stored in the same SQLite file
    // so the site can work without MySQL/cPanel.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id TEXT NOT NULL UNIQUE,
            date_created TEXT,
            date_updated TEXT,
            place_of_acceptance TEXT,
            date_of_acceptance TEXT,
            unique_code TEXT,
            language TEXT,
            client_name TEXT,
            client_phone TEXT,
            client_email TEXT,
            device_model TEXT,
            device_serial TEXT,
            device_type TEXT,
            device_condition TEXT,
            accessories TEXT,
            device_password TEXT,
            problem_description TEXT,
            priority TEXT,
            status TEXT,
            technician_name TEXT,
            work_date TEXT,
            pattern_data TEXT,
            client_signature TEXT,
            client_token TEXT,
            viewed_at TEXT,
            signed_at TEXT
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_document_id ON orders(document_id)');
    // Ensure columns exist for already-created DBs.
    $cols = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function($c) { return $c['name'] ?? ''; }, $cols);
    if (!in_array('client_token', $colNames, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN client_token TEXT');
    }
    if (!in_array('viewed_at', $colNames, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN viewed_at TEXT');
    }
    if (!in_array('signed_at', $colNames, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN signed_at TEXT');
    }
    if (!in_array('order_id', $colNames, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN order_id TEXT');
    }
    if (!in_array('client_id', $colNames, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN client_id INTEGER');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_client_token ON orders(client_token)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_order_id ON orders(order_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_client_id ON orders(client_id)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id TEXT NOT NULL UNIQUE,
            date_created TEXT,
            date_updated TEXT,
            place_of_acceptance TEXT,
            date_of_acceptance TEXT,
            unique_code TEXT,
            language TEXT,
            client_name TEXT,
            client_phone TEXT,
            client_email TEXT,
            total_amount REAL,
            payment_method TEXT,
            payment_status TEXT,
            payment_date TEXT,
            payment_note TEXT,
            services_rendered TEXT,
            notes TEXT,
            client_signature TEXT,
            status TEXT,
            client_token TEXT,
            receipt_number TEXT
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipts_document_id ON receipts(document_id)');
    $cols = $pdo->query("PRAGMA table_info('receipts')")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function($c) { return $c['name'] ?? ''; }, $cols);
    if (!in_array('client_token', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN client_token TEXT');
    }
    if (!in_array('receipt_number', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN receipt_number TEXT');
    }
    $cols = $pdo->query("PRAGMA table_info('receipts')")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $cols);
    if (!in_array('device_model', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN device_model TEXT');
    }
    if (!in_array('payment_status', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN payment_status TEXT');
    }
    if (!in_array('payment_date', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN payment_date TEXT');
    }
    if (!in_array('payment_note', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN payment_note TEXT');
    }
    if (!in_array('order_id', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN order_id TEXT');
    }
    $cols = $pdo->query("PRAGMA table_info('receipts')")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $cols);
    if (!in_array('amount_paid', $colNames, true)) {
        $pdo->exec('ALTER TABLE receipts ADD COLUMN amount_paid REAL');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_receipts_client_token ON receipts(client_token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipts_order_id ON receipts(order_id)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id TEXT NOT NULL UNIQUE,
            invoice_id TEXT NOT NULL UNIQUE,
            order_id TEXT,
            client_id INTEGER,
            date_created TEXT,
            date_updated TEXT,
            due_date TEXT,
            status TEXT,
            language TEXT,
            client_name TEXT,
            client_phone TEXT,
            client_email TEXT,
            service_object TEXT,
            items_json TEXT,
            subtotal REAL,
            tax_rate REAL,
            tax_amount REAL,
            total_amount REAL,
            payment_terms TEXT,
            note TEXT,
            raw_json TEXT,
            client_token TEXT
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoices_invoice_id ON invoices(invoice_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoices_order_id ON invoices(order_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoices_client_id ON invoices(client_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoices_due_date ON invoices(due_date)');

    $invoiceCols = $pdo->query("PRAGMA table_info('invoices')")->fetchAll(PDO::FETCH_ASSOC);
    $invoiceColNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $invoiceCols);
    if (!in_array('client_token', $invoiceColNames, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN client_token TEXT');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_client_token ON invoices(client_token)');

    $invoiceColsLogo = $pdo->query("PRAGMA table_info('invoices')")->fetchAll(PDO::FETCH_ASSOC);
    $invoiceColNamesLogo = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $invoiceColsLogo);
    if (!in_array('invoice_logo', $invoiceColNamesLogo, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN invoice_logo TEXT');
    }
    $invoiceColsPay = $pdo->query("PRAGMA table_info('invoices')")->fetchAll(PDO::FETCH_ASSOC);
    $invoiceColNamesPay = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $invoiceColsPay);
    if (!in_array('payment_date', $invoiceColNamesPay, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN payment_date TEXT');
    }
    if (!in_array('payment_method', $invoiceColNamesPay, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN payment_method TEXT');
    }
    $invoiceColsAddr = $pdo->query("PRAGMA table_info('invoices')")->fetchAll(PDO::FETCH_ASSOC);
    $invoiceColNamesAddr = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $invoiceColsAddr);
    if (!in_array('service_address', $invoiceColNamesAddr, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN service_address TEXT');
    }
    $invoiceColsDm = $pdo->query("PRAGMA table_info('invoices')")->fetchAll(PDO::FETCH_ASSOC);
    $invoiceColNamesDm = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $invoiceColsDm);
    if (!in_array('display_mode', $invoiceColNamesDm, true)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN display_mode TEXT DEFAULT 'detailed'");
    }

    $orderCols = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderCols);
    if (!in_array('parts_purchase_total', $orderColNames, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN parts_purchase_total REAL');
    }
    if (!in_array('parts_sale_total', $orderColNames, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN parts_sale_total REAL');
    }

    $orderColsExt = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNamesExt = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderColsExt);
    if (!in_array('order_type', $orderColNamesExt, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN order_type TEXT DEFAULT 'repair'");
    }
    if (!in_array('public_status', $orderColNamesExt, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN public_status TEXT');
    }
    if (!in_array('public_comment', $orderColNamesExt, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN public_comment TEXT');
    }
    if (!in_array('public_expected_date', $orderColNamesExt, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN public_expected_date TEXT');
    }
    if (!in_array('order_lines_json', $orderColNamesExt, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN order_lines_json TEXT');
    }
    $orderColsV3 = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNamesV3 = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderColsV3);
    if (!in_array('order_status', $orderColNamesV3, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN order_status TEXT DEFAULT 'in_progress'");
    }
    if (!in_array('parts_status', $orderColNamesV3, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN parts_status TEXT');
    }
    $orderColsV41 = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNamesV41 = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderColsV41);
    if (!in_array('internal_comment', $orderColNamesV41, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN internal_comment TEXT');
    }
    $orderColsV42 = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNamesV42 = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderColsV42);
    if (!in_array('public_estimated_cost', $orderColNamesV42, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN public_estimated_cost TEXT');
    }
    $orderColsV43 = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNamesV43 = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderColsV43);
    if (!in_array('public_completed_at', $orderColNamesV43, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN public_completed_at TEXT');
        // Старые завершённые заказы: дата из date_updated (первые 10 символов ISO).
        $pdo->exec(
            "UPDATE orders SET public_completed_at = substr(date_updated, 1, 10) WHERE (public_status IN ('done','delivered') OR order_status IN ('done','delivered')) AND (public_completed_at IS NULL OR TRIM(public_completed_at) = '') AND date_updated IS NOT NULL AND length(trim(date_updated)) >= 10"
        );
    }

    $orderColsV44 = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNamesV44 = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderColsV44);
    if (!in_array('supply_request', $orderColNamesV44, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN supply_request TEXT');
    }
    if (!in_array('supply_urgency', $orderColNamesV44, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN supply_urgency TEXT DEFAULT 'medium'");
    }

    $orderColsElab = $pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC);
    $orderColNamesElab = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $orderColsElab);
    if (!in_array('estimated_labor_cost', $orderColNamesElab, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN estimated_labor_cost REAL');
    }

    $cols = $pdo->query("PRAGMA table_info('mobile_reports')")->fetchAll(PDO::FETCH_ASSOC);
    $mobileColNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $cols);
    if (!in_array('order_id', $mobileColNames, true)) {
        $pdo->exec('ALTER TABLE mobile_reports ADD COLUMN order_id TEXT');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mobile_reports_order_id ON mobile_reports(order_id)');

    // Warehouse: catalog + movement ledger + cached balance per item (updated by trigger)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            name TEXT NOT NULL,
            category TEXT,
            compatibility TEXT,
            unit TEXT DEFAULT \'pcs\',
            min_stock REAL DEFAULT 0,
            default_cost REAL DEFAULT 0,
            notes TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inventory_items_category ON inventory_items(category)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inventory_items_name ON inventory_items(name)');

    $cols = $pdo->query("PRAGMA table_info('inventory_items')")->fetchAll(PDO::FETCH_ASSOC);
    $invColNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $cols);
    if (!in_array('default_cost', $invColNames, true)) {
        $pdo->exec('ALTER TABLE inventory_items ADD COLUMN default_cost REAL DEFAULT 0');
    }
    $invCols2 = $pdo->query("PRAGMA table_info('inventory_items')")->fetchAll(PDO::FETCH_ASSOC);
    $invColNames2 = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $invCols2);
    if (!in_array('sale_price', $invColNames2, true)) {
        $pdo->exec('ALTER TABLE inventory_items ADD COLUMN sale_price REAL DEFAULT 0');
    }
    if (!in_array('location', $invColNames2, true)) {
        $pdo->exec('ALTER TABLE inventory_items ADD COLUMN location TEXT');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE RESTRICT,
            movement_type TEXT NOT NULL,
            quantity_delta REAL NOT NULL,
            unit_cost REAL,
            unit_sale_price REAL,
            ref_kind TEXT,
            ref_id TEXT,
            note TEXT,
            created_at TEXT NOT NULL,
            created_by TEXT
        )'
    );
    $movCols = $pdo->query("PRAGMA table_info('inventory_movements')")->fetchAll(PDO::FETCH_ASSOC);
    $movColNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $movCols);
    if (!in_array('unit_sale_price', $movColNames, true)) {
        $pdo->exec('ALTER TABLE inventory_movements ADD COLUMN unit_sale_price REAL');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_mov_item ON inventory_movements(item_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_mov_created ON inventory_movements(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_mov_ref ON inventory_movements(ref_kind, ref_id)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_balances (
            item_id INTEGER PRIMARY KEY REFERENCES inventory_items(id) ON DELETE CASCADE,
            quantity REAL NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec('DROP TRIGGER IF EXISTS trg_inventory_movement_after_insert');
    $pdo->exec(
        'CREATE TRIGGER trg_inventory_movement_after_insert
        AFTER INSERT ON inventory_movements
        FOR EACH ROW
        BEGIN
            INSERT INTO inventory_balances (item_id, quantity, updated_at)
            VALUES (NEW.item_id, NEW.quantity_delta, NEW.created_at)
            ON CONFLICT(item_id) DO UPDATE SET
                quantity = inventory_balances.quantity + NEW.quantity_delta,
                updated_at = NEW.created_at;
        END'
    );
    $pdo->exec('DROP TRIGGER IF EXISTS trg_inventory_movement_after_delete');
    $pdo->exec(
        'CREATE TRIGGER trg_inventory_movement_after_delete
        AFTER DELETE ON inventory_movements
        FOR EACH ROW
        BEGIN
            UPDATE inventory_balances
            SET quantity = quantity - OLD.quantity_delta,
                updated_at = CURRENT_TIMESTAMP
            WHERE item_id = OLD.item_id;
        END'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS calendar_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            starts_at TEXT NOT NULL,
            ends_at TEXT,
            all_day INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT \'planned\',
            notes TEXT,
            link_type TEXT,
            link_id TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_calendar_events_starts ON calendar_events(starts_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_calendar_events_status ON calendar_events(status)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_warehouse_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id TEXT NOT NULL,
            client_id INTEGER,
            name TEXT NOT NULL,
            qty REAL NOT NULL DEFAULT 1,
            purchase_price REAL DEFAULT 0,
            sale_price REAL DEFAULT 0,
            status TEXT,
            expected_date TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_wh_lines_order ON order_warehouse_lines(order_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_wh_lines_client ON order_warehouse_lines(client_id)');

    $owlCols = $pdo->query("PRAGMA table_info('order_warehouse_lines')")->fetchAll(PDO::FETCH_ASSOC);
    $owlColNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $owlCols);
    if (!in_array('inventory_item_id', $owlColNames, true)) {
        $pdo->exec('ALTER TABLE order_warehouse_lines ADD COLUMN inventory_item_id INTEGER');
    }
    if (!in_array('from_stock', $owlColNames, true)) {
        $pdo->exec('ALTER TABLE order_warehouse_lines ADD COLUMN from_stock INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('qty_received', $owlColNames, true)) {
        $pdo->exec('ALTER TABLE order_warehouse_lines ADD COLUMN qty_received REAL NOT NULL DEFAULT 0');
    }
    try {
        $pdo->exec(
            "UPDATE order_warehouse_lines SET qty_received = qty WHERE qty_received = 0
             AND lower(trim(coalesce(status,''))) IN ('arrived','installed','ready')"
        );
    } catch (Throwable $e) {
        // ignore if column missing on exotic DB
    }

    $owlColsKey = $pdo->query("PRAGMA table_info('order_warehouse_lines')")->fetchAll(PDO::FETCH_ASSOC);
    $owlColNamesKey = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $owlColsKey);
    if (!in_array('order_line_key', $owlColNamesKey, true)) {
        $pdo->exec('ALTER TABLE order_warehouse_lines ADD COLUMN order_line_key TEXT');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_wh_lines_line_key ON order_warehouse_lines(order_line_key)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_list_dismissals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id TEXT NOT NULL,
            name_norm TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(order_id, name_norm)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_purchase_dismiss_order ON purchase_list_dismissals(order_id)');

    $pldCols = $pdo->query("PRAGMA table_info('purchase_list_dismissals')")->fetchAll(PDO::FETCH_ASSOC);
    $pldColNames = array_map(static function ($c) {
        return $c['name'] ?? '';
    }, $pldCols);
    if (!in_array('owl_id', $pldColNames, true)) {
        $pdo->exec('ALTER TABLE purchase_list_dismissals ADD COLUMN owl_id INTEGER');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_purchase_dismiss_owl ON purchase_list_dismissals(owl_id) WHERE owl_id IS NOT NULL');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            note_id TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL DEFAULT \'\',
            body TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_updated ON notes(updated_at)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS portal_snake_global (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            best_score INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT ""
        )'
    );
    $pdo->exec(
        'INSERT OR IGNORE INTO portal_snake_global (id, best_score, updated_at) VALUES (1, 0, "")'
    );
}
