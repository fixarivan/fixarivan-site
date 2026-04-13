<?php
declare(strict_types=1);

require_once __DIR__ . '/order_center.php';
require_once __DIR__ . '/order_json_storage.php';
require_once __DIR__ . '/calendar_events.php';
require_once __DIR__ . '/../inventory_sqlite_helpers.php';

/**
 * Единственный источник даты напоминания о поставке — поле заказа public_expected_date (YYYY-MM-DD).
 * Пустая строка → напоминание AUTO_SUPPLY не создаётся (без fallback по срочности).
 */
function fixarivan_normalize_public_expected_date(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $raw, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if ($y >= 2000 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    return '';
}

/**
 * Извлекает закуп/продажа из примечания строки заявки (например «закуп €15 / продажа €30»).
 *
 * @return array{purchase:float, sale:float}
 */
function fixarivan_parse_supply_note_prices(string $note): array {
    $note = trim($note);
    $pp = 0.0;
    $sp = 0.0;
    if ($note !== '') {
        if (preg_match('/закуп\s*€?\s*([\d\s.,]+)/iu', $note, $m)) {
            $pp = (float)str_replace([' ', ','], ['', '.'], $m[1]);
        }
        if (preg_match('/продажа\s*€?\s*([\d\s.,]+)/iu', $note, $m)) {
            $sp = (float)str_replace([' ', ','], ['', '.'], $m[1]);
        }
        if ($pp === 0.0 && $sp === 0.0 && preg_match('/([\d\s.,]+)\s*\/\s*([\d\s.,]+)/u', $note, $m)) {
            $pp = (float)str_replace([' ', ','], ['', '.'], $m[1]);
            $sp = (float)str_replace([' ', ','], ['', '.'], $m[2]);
        }
    }

    return ['purchase' => $pp, 'sale' => $sp];
}

function fixarivan_part_names_fuzzy_match(string $a, string $b): bool {
    $enc = 'UTF-8';
    $a = mb_strtolower(trim($a), $enc);
    $b = mb_strtolower(trim($b), $enc);
    if ($a === $b) {
        return true;
    }
    if ($a === '' || $b === '') {
        return false;
    }
    if (mb_strpos($a, $b, 0, $enc) !== false || mb_strpos($b, $a, 0, $enc) !== false) {
        return true;
    }
    similar_text($a, $b, $pct);

    return $pct >= 88.0;
}

/**
 * Имя из заявки → имя строки из order_warehouse_lines (та же позиция, что в акте), чтобы приход и статус совпадали.
 */
function fixarivan_resolve_canonical_part_name(PDO $pdo, string $orderId, string $supplyName): string {
    $supplyName = trim($supplyName);
    $orderId = trim($orderId);
    if ($supplyName === '' || $orderId === '') {
        return $supplyName;
    }

    $st = $pdo->prepare(
        'SELECT name FROM order_warehouse_lines WHERE order_id = :o AND lower(trim(name)) = lower(trim(:n)) LIMIT 1'
    );
    $st->execute([':o' => $orderId, ':n' => $supplyName]);
    $hit = $st->fetchColumn();
    if (is_string($hit) && trim($hit) !== '') {
        return trim($hit);
    }

    $st2 = $pdo->prepare(
        "SELECT name FROM order_warehouse_lines WHERE order_id = :o
         AND lower(IFNULL(status,'')) NOT IN ('arrived','installed','ready') ORDER BY id ASC"
    );
    $st2->execute([':o' => $orderId]);
    $rows = $st2->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) === 1) {
        return trim((string)$rows[0]);
    }
    foreach ($rows as $rname) {
        $rname = (string)$rname;
        if (fixarivan_part_names_fuzzy_match($supplyName, $rname)) {
            return trim($rname);
        }
    }

    return $supplyName;
}

/**
 * Поиск строки склада заказа при приходе (точное имя → одна ожидающая строка → нечёткое совпадение).
 *
 * @deprecated Используйте явный order_warehouse_line_id в теле прихода (TZ v2-add B).
 */
function fixarivan_find_order_warehouse_line_for_arrival(PDO $pdo, string $orderId, string $inventoryItemName): int {
    $orderId = trim($orderId);
    $inventoryItemName = trim($inventoryItemName);
    if ($orderId === '' || $inventoryItemName === '') {
        return 0;
    }

    $findOwl = $pdo->prepare(
        "SELECT id FROM order_warehouse_lines WHERE order_id = :o AND lower(trim(name)) = lower(trim(:n))
         AND lower(IFNULL(status,'')) NOT IN ('arrived','installed','ready') ORDER BY id ASC LIMIT 1"
    );
    $findOwl->execute([':o' => $orderId, ':n' => $inventoryItemName]);
    $id = (int)$findOwl->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $st = $pdo->prepare(
        "SELECT id, name FROM order_warehouse_lines WHERE order_id = :o
         AND lower(IFNULL(status,'')) NOT IN ('arrived','installed','ready') ORDER BY id ASC"
    );
    $st->execute([':o' => $orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) === 1) {
        return (int)$rows[0]['id'];
    }
    foreach ($rows as $r) {
        if (fixarivan_part_names_fuzzy_match($inventoryItemName, (string)($r['name'] ?? ''))) {
            return (int)$r['id'];
        }
    }

    return 0;
}

/**
 * Подставляет id карточки склада для строки OWL: только order_line_key ↔ line_id и inventory_item_id в order_lines_json.
 */
function fixarivan_resolve_inventory_item_id_for_order_line(PDO $pdo, string $orderId, string $lineName, ?int $existingId, ?int $owlId = null): int
{
    $existingId = (int)($existingId ?? 0);
    if ($existingId > 0) {
        return $existingId;
    }
    $orderId = trim($orderId);
    if ($orderId === '') {
        return 0;
    }
    $owlId = (int)($owlId ?? 0);
    if ($owlId <= 0) {
        return 0;
    }

    $kSt = $pdo->prepare('SELECT order_line_key FROM order_warehouse_lines WHERE id = ? LIMIT 1');
    $kSt->execute([$owlId]);
    $lineKey = trim((string) $kSt->fetchColumn());
    if ($lineKey === '') {
        return 0;
    }

    $st = $pdo->prepare('SELECT order_lines_json FROM orders WHERE order_id = ? OR document_id = ? LIMIT 1');
    $st->execute([$orderId, $orderId]);
    $jsonRaw = $st->fetchColumn();
    $lines = json_decode(is_string($jsonRaw) ? $jsonRaw : '[]', true);
    if (!is_array($lines)) {
        $lines = [];
    }

    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $kid = trim((string) ($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
        if ($kid === '' || $kid !== $lineKey) {
            continue;
        }
        $iid = (int) ($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
        if ($iid > 0) {
            return $iid;
        }

        return 0;
    }

    return 0;
}

/**
 * Карточка склада с тегом [REQ …] по имени строки заказа (связь заказ → каталог).
 */
function fixarivan_resolve_inventory_item_id_by_req_tag(PDO $pdo, string $orderId, string $lineName): int
{
    $lineName = trim($lineName);
    if ($lineName === '') {
        return 0;
    }
    $orderId = trim($orderId);
    if ($orderId === '') {
        return 0;
    }
    $variants = fixarivan_order_id_variants_for_pdo($pdo, '', $orderId);
    if ($variants === []) {
        $variants = [$orderId];
    }
    foreach ($variants as $vid) {
        $vid = trim((string) $vid);
        if ($vid === '') {
            continue;
        }
        $st = $pdo->prepare('SELECT id FROM inventory_items WHERE IFNULL(notes,\'\') LIKE ? AND TRIM(LOWER(name)) = TRIM(LOWER(?)) LIMIT 1');
        $st->execute(['%[REQ ' . $vid . ']%', $lineName]);
        $rid = (int) $st->fetchColumn();
        if ($rid > 0) {
            return $rid;
        }
    }

    return 0;
}

/**
 * @return list<array{name:string,qty:float,type:string,note:string,purchase:float,sale:float}>
 */
function fixarivan_parse_supply_request(string $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $name = (string)($parts[0] ?? '');
        if ($name === '') {
            continue;
        }
        $qty = (float)($parts[1] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $type = strtolower((string)($parts[2] ?? 'part'));
        if (!in_array($type, ['part', 'accessory', 'device'], true)) {
            $type = 'part';
        }
        $note = (string)($parts[3] ?? '');
        $pr = fixarivan_parse_supply_note_prices($note);
        $out[] = [
            'name' => $name,
            'qty' => $qty,
            'type' => $type,
            'note' => $note,
            'purchase' => $pr['purchase'],
            'sale' => $pr['sale'],
        ];
    }

    return $out;
}

function fixarivan_supply_type_to_category(string $type): string {
    if ($type === 'accessory') {
        return 'accessories';
    }
    if ($type === 'device') {
        return 'other';
    }
    return 'parts';
}

/**
 * Поиск карточки склада по однозначным тегам [REQ orderId] и [LINE lineKey] в notes.
 * lineKey — внутренний ключ без скобок (например OL-… или SUPPLY-0).
 */
function fixarivan_find_inventory_item_id_by_order_line_tags(PDO $pdo, string $orderId, string $lineKey): int
{
    $orderId = trim($orderId);
    $lineKey = trim($lineKey);
    if ($orderId === '' || $lineKey === '') {
        return 0;
    }
    $reqTag = '[REQ ' . $orderId . ']';
    $lineTag = '[LINE ' . $lineKey . ']';
    $st = $pdo->prepare("SELECT id, notes FROM inventory_items WHERE IFNULL(notes,'') LIKE ?");
    $st->execute(['%' . $reqTag . '%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        if (strpos((string) ($r['notes'] ?? ''), $lineTag) !== false) {
            return (int) ($r['id'] ?? 0);
        }
    }

    return 0;
}

/**
 * Строки заказа (под закупку) → карточки склада: только привязка [REQ orderId] + [LINE line_id].
 * Обновляет order_warehouse_lines.inventory_item_id по order_line_key.
 *
 * @param list<array<string,mixed>> $lines
 */
function fixarivan_sync_order_purchase_lines_to_inventory(PDO $pdo, string $orderId, array $lines, string $deviceModel): void
{
    $orderId = trim($orderId);
    if ($orderId === '' || $lines === []) {
        return;
    }
    $docRow = $pdo->prepare('SELECT document_id, order_id FROM orders WHERE order_id = ? OR document_id = ? LIMIT 1');
    $docRow->execute([$orderId, $orderId]);
    $or = $docRow->fetch(PDO::FETCH_ASSOC);
    $documentId = is_array($or) ? trim((string) ($or['document_id'] ?? '')) : '';
    $variants = fixarivan_order_id_variants_for_pdo($pdo, $documentId, $orderId);
    if ($variants === []) {
        $variants = [$orderId];
    }
    $variantPh = implode(',', array_fill(0, count($variants), '?'));
    $now = date('c');
    $reqTag = '[REQ ' . $orderId . ']';

    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if (fixarivan_order_line_is_from_warehouse_stock($ln)) {
            continue;
        }
        $lineKey = trim((string) ($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
        if ($lineKey === '') {
            continue;
        }
        $name = trim((string) ($ln['name'] ?? $ln['title'] ?? ''));
        if ($name === '') {
            continue;
        }
        $lineTag = '[LINE ' . $lineKey . ']';
        $purchase = isset($ln['purchase']) ? (float) $ln['purchase'] : 0.0;
        $sale = isset($ln['sale']) ? (float) $ln['sale'] : 0.0;
        if ($purchase === 0.0 && $sale === 0.0) {
            $pp = (float) ($ln['purchase_price'] ?? $ln['cost'] ?? 0);
            $sp = (float) ($ln['sale_price'] ?? $ln['price'] ?? 0);
            if ($pp > 0.0 || $sp > 0.0) {
                $purchase = $pp;
                $sale = $sp;
            } else {
                $pr = fixarivan_parse_supply_note_prices((string) ($ln['note'] ?? ''));
                $purchase = $pr['purchase'];
                $sale = $pr['sale'];
            }
        }
        $type = strtolower((string) ($ln['type'] ?? 'part'));
        if (!in_array($type, ['part', 'accessory', 'device'], true)) {
            $type = 'part';
        }
        $category = fixarivan_supply_type_to_category($type);
        $qty = max(1.0, (float) ($ln['qty'] ?? $ln['quantity'] ?? 1));
        $extraNote = trim((string) ($ln['note'] ?? ''));

        $existingId = fixarivan_find_inventory_item_id_by_order_line_tags($pdo, $orderId, $lineKey);

        $jsonIid = (int) ($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
        if ($existingId <= 0 && $jsonIid > 0) {
            $chk = $pdo->prepare('SELECT id, notes FROM inventory_items WHERE id = ? LIMIT 1');
            $chk->execute([$jsonIid]);
            $cr = $chk->fetch(PDO::FETCH_ASSOC);
            if (is_array($cr) && strpos((string) ($cr['notes'] ?? ''), $lineTag) !== false && strpos((string) ($cr['notes'] ?? ''), $reqTag) !== false) {
                $existingId = $jsonIid;
            }
        }

        if ($existingId > 0) {
            $st = $pdo->prepare('SELECT id, min_stock, notes, default_cost, sale_price, name FROM inventory_items WHERE id = ? LIMIT 1');
            $st->execute([$existingId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                continue;
            }
            $notes = trim((string) ($row['notes'] ?? ''));
            if (strpos($notes, $reqTag) === false) {
                $notes = trim($notes . "\n" . $reqTag . ' ' . $lineTag);
            } elseif (strpos($notes, $lineTag) === false) {
                $notes = trim($notes . "\n" . $lineTag);
            }
            $minStock = max((float) ($row['min_stock'] ?? 0), $qty);
            $dc = (float) ($row['default_cost'] ?? 0);
            $spRow = (float) ($row['sale_price'] ?? 0);
            if ($purchase > 0.0) {
                $dc = $purchase;
            }
            if ($sale > 0.0) {
                $spRow = $sale;
            }
            $pdo->prepare(
                'UPDATE inventory_items SET name = :n, min_stock = :m, notes = :nt, default_cost = :dc, sale_price = :sp, category = :cat, updated_at = :u WHERE id = :id'
            )->execute([
                ':n' => $name,
                ':m' => $minStock,
                ':nt' => $notes !== '' ? $notes : null,
                ':dc' => $dc,
                ':sp' => $spRow,
                ':cat' => $category,
                ':u' => $now,
                ':id' => $existingId,
            ]);
            $newId = $existingId;
        } else {
            $notesLine = $reqTag . ' ' . $lineTag . ($extraNote !== '' ? ' ' . $extraNote : '');
            if ($deviceModel !== '') {
                $notesLine .= "\n" . $deviceModel;
            }
            $ins = $pdo->prepare(
                'INSERT INTO inventory_items (sku, name, category, compatibility, unit, min_stock, default_cost, sale_price, notes, created_at, updated_at)
                 VALUES (:sku, :name, :category, :compatibility, :unit, :min_stock, :default_cost, :sale_price, :notes, :created_at, :updated_at)'
            );
            $ins->execute([
                ':sku' => null,
                ':name' => $name,
                ':category' => $category,
                ':compatibility' => $deviceModel !== '' ? $deviceModel : null,
                ':unit' => 'pcs',
                ':min_stock' => $qty,
                ':default_cost' => $purchase > 0.0 ? $purchase : 0,
                ':sale_price' => $sale > 0.0 ? $sale : 0,
                ':notes' => $notesLine,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $newId = (int) $pdo->lastInsertId();
        }

        if ($newId <= 0) {
            continue;
        }
        $updOwl = $pdo->prepare(
            'UPDATE order_warehouse_lines SET inventory_item_id = ?, updated_at = ? WHERE TRIM(COALESCE(order_line_key, \'\')) = ? AND order_id IN (' . $variantPh . ')'
        );
        $updOwl->execute(array_merge([$newId, $now, $lineKey], array_values($variants)));
    }
    fixarivan_inventory_ensure_missing_skus($pdo);
}

/**
 * Текстовая заявка supply (без order_lines_json): стабильная строка [LINE SUPPLY-n], без сопоставления только name+category.
 *
 * @param list<array{name:string,qty:float,type:string,note:string,purchase?:float,sale?:float}> $items
 */
function fixarivan_sync_supply_to_inventory(PDO $pdo, string $orderId, array $items, string $deviceModel): void {
    if ($orderId === '' || $items === []) {
        return;
    }
    $supplyIdx = 0;
    foreach ($items as $i) {
        $rawName = trim((string)$i['name']);
        if ($rawName === '') {
            continue;
        }
        $name = $rawName;
        $purchase = isset($i['purchase']) ? (float)$i['purchase'] : 0.0;
        $sale = isset($i['sale']) ? (float)$i['sale'] : 0.0;
        if ($purchase === 0.0 && $sale === 0.0) {
            $pr = fixarivan_parse_supply_note_prices((string)($i['note'] ?? ''));
            $purchase = $pr['purchase'];
            $sale = $pr['sale'];
        }
        $category = fixarivan_supply_type_to_category((string)$i['type']);
        $qty = max(1.0, (float)$i['qty']);
        $reqTag = '[REQ ' . $orderId . ']';
        $lineKey = 'SUPPLY-' . $supplyIdx;
        $supplyIdx++;
        $lineTag = '[LINE ' . $lineKey . ']';
        $existingId = fixarivan_find_inventory_item_id_by_order_line_tags($pdo, $orderId, $lineKey);
        $now = date('c');
        if ($existingId > 0) {
            $st = $pdo->prepare('SELECT id, min_stock, notes, default_cost, sale_price, name FROM inventory_items WHERE id = ? LIMIT 1');
            $st->execute([$existingId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                continue;
            }
            $minStock = max((float)($row['min_stock'] ?? 0), $qty);
            $notes = trim((string)($row['notes'] ?? ''));
            if (strpos($notes, $reqTag) === false) {
                $notes = trim($notes . "\n" . $reqTag . ' ' . $lineTag);
            } elseif (strpos($notes, $lineTag) === false) {
                $notes = trim($notes . "\n" . $lineTag);
            }
            if ($i['note'] !== '' && strpos($notes, (string)$i['note']) === false) {
                $notes = trim($notes . "\n" . $i['note']);
            }
            $dc = (float)($row['default_cost'] ?? 0);
            $sp = (float)($row['sale_price'] ?? 0);
            if ($purchase > 0.0) {
                $dc = $purchase;
            }
            if ($sale > 0.0) {
                $sp = $sale;
            }
            $upd = $pdo->prepare('UPDATE inventory_items SET name = :nm, min_stock = :m, notes = :nt, default_cost = :dc, sale_price = :sp, category = :cat, updated_at = :u WHERE id = :id');
            $upd->execute([
                ':nm' => $name,
                ':m' => $minStock,
                ':nt' => $notes !== '' ? $notes : null,
                ':dc' => $dc,
                ':sp' => $sp,
                ':cat' => $category,
                ':u' => $now,
                ':id' => $existingId,
            ]);

            continue;
        }

        $ins = $pdo->prepare(
            'INSERT INTO inventory_items (sku, name, category, compatibility, unit, min_stock, default_cost, sale_price, notes, created_at, updated_at)
             VALUES (:sku, :name, :category, :compatibility, :unit, :min_stock, :default_cost, :sale_price, :notes, :created_at, :updated_at)'
        );
        $ins->execute([
            ':sku' => null,
            ':name' => $name,
            ':category' => $category,
            ':compatibility' => $deviceModel !== '' ? $deviceModel : null,
            ':unit' => 'pcs',
            ':min_stock' => $qty,
            ':default_cost' => $purchase > 0.0 ? $purchase : 0,
            ':sale_price' => $sale > 0.0 ? $sale : 0,
            ':notes' => $reqTag . ' ' . $lineTag . ' ' . ($i['note'] !== '' ? $i['note'] : ($deviceModel !== '' ? $deviceModel : '')),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
    fixarivan_inventory_ensure_missing_skus($pdo);
}

/**
 * @param list<array{name:string,qty:float,type:string,note:string}> $items
 */
function fixarivan_create_supply_reminder(PDO $pdo, string $orderId, array $items, string $urgency, string $publicExpectedYmd, string $clientName, string $deviceModel = ''): void {
    if ($orderId === '' || $items === []) {
        return;
    }
    $ymd = fixarivan_normalize_public_expected_date($publicExpectedYmd);
    if ($ymd === '') {
        $pdo->prepare("DELETE FROM calendar_events WHERE link_type='order' AND link_id=:oid AND IFNULL(notes,'') LIKE '%AUTO_SUPPLY%'")
            ->execute([':oid' => $orderId]);
        return;
    }
    $urgency = in_array($urgency, ['low', 'medium', 'high', 'urgent'], true) ? $urgency : 'medium';
    $tz = new DateTimeZone('Europe/Helsinki');
    // Полдень по месту — при отображении календарь берёт дату из префикса YYYY-MM-DD (см. calendar.html).
    $dt = new DateTimeImmutable($ymd . ' 12:00:00', $tz);

    $notesLines = [];
    foreach ($items as $i) {
        $notesLines[] = '- ' . $i['name'] . ' x' . rtrim(rtrim((string)$i['qty'], '0'), '.') . ($i['note'] !== '' ? (' (' . $i['note'] . ')') : '');
    }
    $notes = "AUTO_SUPPLY\nЗаказ: {$orderId}\nКлиент: {$clientName}\nСрочность: {$urgency}\n" . implode("\n", $notesLines);

    $pdo->prepare("DELETE FROM calendar_events WHERE link_type='order' AND link_id=:oid AND IFNULL(notes,'') LIKE '%AUTO_SUPPLY%'")
        ->execute([':oid' => $orderId]);

    $eventId = 'EVT-SUP-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $now = date('c');
    $stmt = $pdo->prepare(
        'INSERT INTO calendar_events (event_id, title, starts_at, ends_at, all_day, status, notes, link_type, link_id, created_at, updated_at)
         VALUES (:event_id, :title, :starts_at, :ends_at, :all_day, :status, :notes, :link_type, :link_id, :created_at, :updated_at)'
    );
    $title = fixarivan_build_supply_calendar_title($orderId, $items, $clientName, $deviceModel);
    $stmt->execute([
        ':event_id' => $eventId,
        ':title' => $title,
        ':starts_at' => $dt->format('c'),
        ':ends_at' => null,
        ':all_day' => 1,
        ':status' => 'planned',
        ':notes' => $notes,
        ':link_type' => 'order',
        ':link_id' => $orderId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

/**
 * Строка заказа берётся со склада (не в заявку на закупку).
 */
function fixarivan_order_line_is_from_warehouse_stock(array $ln): bool
{
    $v = $ln['from_stock'] ?? $ln['fromStock'] ?? false;
    if ($v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower(trim($v)) === 'true')) {
        return true;
    }

    return false;
}

/**
 * TZ P2 блок 7: есть закупка/строки не «со склада», но нет public_expected_date — напоминание в календаре не создаётся.
 */
function fixarivan_supply_missing_expected_date_warning(?string $supplyRaw, ?string $publicExpectedYmd, ?string $orderLinesJson = null): ?string
{
    if (fixarivan_normalize_public_expected_date((string)$publicExpectedYmd) !== '') {
        return null;
    }
    $hasSupply = trim((string)$supplyRaw) !== '';
    if (!$hasSupply && $orderLinesJson !== null && $orderLinesJson !== '' && trim($orderLinesJson) !== '[]') {
        $lines = json_decode($orderLinesJson, true);
        if (is_array($lines)) {
            foreach ($lines as $ln) {
                if (!is_array($ln)) {
                    continue;
                }
                if (fixarivan_order_line_is_from_warehouse_stock($ln)) {
                    continue;
                }
                if (trim((string)($ln['name'] ?? $ln['title'] ?? '')) !== '') {
                    $hasSupply = true;
                    break;
                }
            }
        }
    }
    if (!$hasSupply) {
        return null;
    }

    return 'Ожидаемая дата для клиента не задана: напоминание о закупке в календаре не создаётся (укажите public_expected_date).';
}

/**
 * Строки заказа (order_lines_json) → те же элементы, что и после parse_supply_request (склад/напоминания).
 *
 * @param list<array> $lines
 * @return list<array{name:string,qty:float,type:string,note:string}>
 */
function fixarivan_order_lines_to_supply_items(array $lines): array {
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if (fixarivan_order_line_is_from_warehouse_stock($ln)) {
            continue;
        }
        $name = trim((string)($ln['name'] ?? $ln['title'] ?? ''));
        if ($name === '') {
            continue;
        }
        $qty = (float)($ln['qty'] ?? $ln['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $pp = (float)($ln['purchase'] ?? $ln['purchase_price'] ?? $ln['cost'] ?? 0);
        $sp = (float)($ln['sale'] ?? $ln['sale_price'] ?? $ln['price'] ?? 0);
        $note = 'закуп €' . $pp . ' / продажа €' . $sp;
        $out[] = ['name' => $name, 'qty' => $qty, 'type' => 'part', 'note' => $note, 'purchase' => $pp, 'sale' => $sp];
    }

    return $out;
}

/** Текст заявки в том же формате, что и в Track (name | qty | part|… | note). */
function fixarivan_supply_request_from_order_lines(array $lines): string {
    $items = fixarivan_order_lines_to_supply_items($lines);
    $linesOut = [];
    foreach ($items as $i) {
        $q = rtrim(rtrim((string)$i['qty'], '0'), '.');
        if ($q === '') {
            $q = '1';
        }
        $linesOut[] = $i['name'] . ' | ' . $q . ' | ' . $i['type'] . ' | ' . $i['note'];
    }

    return implode("\n", $linesOut);
}

function fixarivan_normalize_supply_urgency(string $urgency, string $priorityFallback = 'normal'): string {
    $u = strtolower(trim($urgency));
    if (in_array($u, ['low', 'medium', 'high', 'urgent'], true)) {
        return $u;
    }
    $p = strtolower(trim($priorityFallback));
    if (in_array($p, ['low', 'medium', 'high', 'urgent'], true)) {
        return $p;
    }

    return 'medium';
}

/**
 * Все варианты order_id для строк склада заказа (order_id в БД, document_id, legacy).
 *
 * @return list<string>
 */
function fixarivan_order_id_variants_for_pdo(PDO $pdo, string $documentId, string $orderIdHint = ''): array
{
    $set = [];
    $add = static function (string $s) use (&$set): void {
        $s = trim($s);
        if ($s !== '') {
            $set[$s] = true;
        }
    };
    $add($orderIdHint);
    $documentId = trim($documentId);
    $add($documentId);
    if ($documentId !== '') {
        $st = $pdo->prepare('SELECT order_id, document_id FROM orders WHERE document_id = :d LIMIT 1');
        $st->execute([':d' => $documentId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($r)) {
            $add((string) ($r['order_id'] ?? ''));
            $add((string) ($r['document_id'] ?? ''));
        }
    }
    $oid = trim($orderIdHint);
    if ($oid !== '' && $documentId === '') {
        $st = $pdo->prepare('SELECT order_id, document_id FROM orders WHERE order_id = :o OR document_id = :o LIMIT 1');
        $st->execute([':o' => $oid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($r)) {
            $add((string) ($r['order_id'] ?? ''));
            $add((string) ($r['document_id'] ?? ''));
        }
    }

    return array_keys($set);
}

/**
 * Поиск позиции каталога для списания: точное имя → тег [REQ order] → нечёткое имя.
 *
 * @param list<string> $orderIds
 */
function fixarivan_resolve_inventory_item_id_for_deduction(PDO $pdo, string $lineName, array $orderIds): int
{
    $lineName = trim($lineName);
    if ($lineName === '') {
        return 0;
    }
    $find = $pdo->prepare('SELECT id FROM inventory_items WHERE lower(trim(name)) = lower(trim(:n)) LIMIT 1');
    $find->execute([':n' => $lineName]);
    $itemId = (int) $find->fetchColumn();
    if ($itemId > 0) {
        return $itemId;
    }
    foreach ($orderIds as $oid) {
        $oid = trim((string) $oid);
        if ($oid === '') {
            continue;
        }
        $find2 = $pdo->prepare('SELECT id FROM inventory_items WHERE IFNULL(notes,\'\') LIKE :tag LIMIT 1');
        $find2->execute([':tag' => '%[REQ ' . $oid . ']%']);
        $itemId = (int) $find2->fetchColumn();
        if ($itemId > 0) {
            return $itemId;
        }
    }
    try {
        $all = $pdo->query('SELECT id, name FROM inventory_items')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($all as $row) {
            $nm = (string) ($row['name'] ?? '');
            if ($nm !== '' && fixarivan_part_names_fuzzy_match($lineName, $nm)) {
                return (int) ($row['id'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        error_log('fixarivan_resolve_inventory_item_id_for_deduction: ' . $e->getMessage());
    }

    return 0;
}

/**
 * Удалить напоминания AUTO_SUPPLY по заказу (при приходе запчасти или полном закрытии).
 */
function fixarivan_clear_supply_calendar_for_order(PDO $pdo, string $orderId): void
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return;
    }
    $pdo->prepare("DELETE FROM calendar_events WHERE link_type='order' AND link_id=:oid AND IFNULL(notes,'') LIKE '%AUTO_SUPPLY%'")
        ->execute([':oid' => $orderId]);
}

/**
 * Убрать календарь AUTO_SUPPLY по всем идентификаторам заказа.
 *
 * @param list<string> $orderIds
 */
function fixarivan_clear_supply_calendar_for_order_ids(PDO $pdo, array $orderIds): void
{
    foreach ($orderIds as $oid) {
        fixarivan_clear_supply_calendar_for_order($pdo, (string) $oid);
    }
}

/**
 * Убрать из примечаний складских позиций теги [REQ orderId] для удалённого заказа.
 *
 * @param list<string> $orderIds
 */
function fixarivan_inventory_strip_req_tags_for_order_ids(PDO $pdo, array $orderIds): void
{
    $now = date('c');
    foreach ($orderIds as $oid) {
        $oid = trim((string) $oid);
        if ($oid === '') {
            continue;
        }
        $tag = '[REQ ' . $oid . ']';
        $st = $pdo->prepare('SELECT id, notes FROM inventory_items WHERE IFNULL(notes,\'\') LIKE :x');
        $st->execute([':x' => '%' . $tag . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $n = trim(str_replace($tag, '', (string) ($r['notes'] ?? '')));
            $n = preg_replace("/\n{3,}/u", "\n\n", $n) ?? $n;
            $n = trim($n);
            $upd = $pdo->prepare('UPDATE inventory_items SET notes = :n, updated_at = :u WHERE id = :id');
            $upd->execute([
                ':n' => $n !== '' ? $n : null,
                ':u' => $now,
                ':id' => (int) ($r['id'] ?? 0),
            ]);
        }
    }
}

/**
 * Полная очистка складских следов заказа при удалении документа.
 *
 * @param list<string> $orderIds все варианты order_id / document_id
 */
function fixarivan_cleanup_order_supply_on_order_delete(PDO $pdo, array $orderIds): void
{
    if ($orderIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $pdo->prepare("DELETE FROM order_warehouse_lines WHERE order_id IN ($ph)")->execute(array_values($orderIds));
    fixarivan_clear_supply_calendar_for_order_ids($pdo, $orderIds);
    fixarivan_inventory_strip_req_tags_for_order_ids($pdo, $orderIds);
}

/**
 * Единая цепочка: парсинг supply-текста → inventory_items + календарь (AUTO_SUPPLY).
 * Дата в календаре только из $publicExpectedDate (= orders.public_expected_date), без supply_due_date.
 */
function fixarivan_apply_supply_effects(
    PDO $pdo,
    string $orderId,
    string $supplyRaw,
    string $deviceModel,
    string $urgency,
    string $publicExpectedDate,
    string $clientName
): void {
    $supplyRaw = trim($supplyRaw);
    if ($orderId === '' || $supplyRaw === '') {
        return;
    }
    $items = fixarivan_parse_supply_request($supplyRaw);
    if ($items === []) {
        return;
    }
    $urgency = fixarivan_normalize_supply_urgency($urgency, 'normal');
    fixarivan_sync_supply_to_inventory($pdo, $orderId, $items, $deviceModel);
    fixarivan_create_supply_reminder($pdo, $orderId, $items, $urgency, $publicExpectedDate, $clientName, $deviceModel);
}

/**
 * Сумма количества уже списанного по строке заказа (OUT: ORDER_LINE_OUT из Track + ORDER_LINE_ARR_OUT после прихода).
 */
function fixarivan_sum_order_line_out_quantity(PDO $pdo, int $itemId, string $refOrderId, string $lineKey): float
{
    if ($itemId <= 0 || $refOrderId === '' || $lineKey === '') {
        return 0.0;
    }
    $exact = 'ORDER_LINE_OUT:' . $refOrderId . ':' . $lineKey;
    $like = 'ORDER_LINE_ARR_OUT:' . $refOrderId . ':' . $lineKey . ':%';
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN quantity_delta < 0 THEN -quantity_delta ELSE 0 END), 0) AS s
         FROM inventory_movements
         WHERE item_id = ?
           AND (note = ? OR note LIKE ?)'
    );
    $st->execute([$itemId, $exact, $like]);

    return (float) ($st->fetchColumn() ?: 0);
}

/**
 * Списание со склада при закрытии заказа: по строкам order_warehouse_lines, не чаще одного раза на строку.
 * Идемпотентность: note ORDER_CLOSE:{order}:{owlId}; не списывать снова, если строка уже со склада (from_stock);
 * если Track уже создал движение с note ORDER_LINE_OUT:{order}:{line_key} — тоже пропуск.
 */
function fixarivan_deduct_inventory_for_completed_order(PDO $pdo, string $orderId): void
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return;
    }

    $docRow = $pdo->prepare('SELECT document_id, order_id FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1');
    $docRow->execute([':o' => $orderId, ':d' => $orderId]);
    $or = $docRow->fetch(PDO::FETCH_ASSOC);
    $documentId = is_array($or) ? trim((string) ($or['document_id'] ?? '')) : '';
    $variants = fixarivan_order_id_variants_for_pdo($pdo, $documentId, $orderId);
    if ($variants === []) {
        $variants = [$orderId];
    }
    $refId = is_array($or) ? trim((string) ($or['order_id'] ?? '')) : '';
    if ($refId === '') {
        $refId = $orderId;
    }

    $byOwl = [];
    foreach ($variants as $vid) {
        $st = $pdo->prepare(
            'SELECT id, name, qty, IFNULL(inventory_item_id, 0) AS inventory_item_id, IFNULL(from_stock, 0) AS from_stock, order_line_key FROM order_warehouse_lines WHERE order_id = :o'
        );
        $st->execute([':o' => $vid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ln) {
            $owlId = (int) ($ln['id'] ?? 0);
            if ($owlId > 0 && !isset($byOwl[$owlId])) {
                $byOwl[$owlId] = $ln;
            }
        }
    }
    $lines = array_values($byOwl);
    if ($lines === []) {
        return;
    }

    $now = date('c');
    foreach ($lines as $ln) {
        $owlId = (int) ($ln['id'] ?? 0);
        $name = trim((string) ($ln['name'] ?? ''));
        $qty = (float) ($ln['qty'] ?? 0);
        if ($owlId <= 0 || $name === '' || $qty <= 0) {
            continue;
        }

        $noteTag = 'ORDER_CLOSE:' . $refId . ':' . $owlId;
        $chk = $pdo->prepare('SELECT id FROM inventory_movements WHERE note = :n LIMIT 1');
        $chk->execute([':n' => $noteTag]);
        if ($chk->fetchColumn()) {
            continue;
        }

        $owlFromStock = (int) ($ln['from_stock'] ?? 0) === 1;
        if ($owlFromStock) {
            continue;
        }

        $lineKey = trim((string) ($ln['order_line_key'] ?? ''));

        $owlInvId = (int) ($ln['inventory_item_id'] ?? 0);
        if ($owlInvId > 0) {
            $exist = $pdo->prepare('SELECT id FROM inventory_items WHERE id = ? LIMIT 1');
            $exist->execute([$owlInvId]);
            $itemId = (int) $exist->fetchColumn();
            if ($itemId <= 0) {
                continue;
            }
        } else {
            $itemId = fixarivan_resolve_inventory_item_id_for_deduction($pdo, $name, $variants);
            if ($itemId <= 0) {
                continue;
            }
        }

        if ($lineKey !== '') {
            $sumOut = fixarivan_sum_order_line_out_quantity($pdo, $itemId, $refId, $lineKey);
            if ($sumOut + 1e-6 >= $qty) {
                continue;
            }
        }

        $balStmt = $pdo->prepare('SELECT quantity FROM inventory_balances WHERE item_id = ?');
        $balStmt->execute([$itemId]);
        $bal = (float) ($balStmt->fetchColumn() ?: 0);
        $outQty = min($qty, $bal);
        if ($outQty <= 0) {
            continue;
        }

        $costStmt = $pdo->prepare('SELECT default_cost FROM inventory_items WHERE id = ?');
        $costStmt->execute([$itemId]);
        $uc = (float) ($costStmt->fetchColumn() ?: 0);

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $itemId,
                'out',
                -$outQty,
                $uc > 0 ? $uc : null,
                'order_close',
                $refId,
                $noteTag,
                $now,
                null,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('fixarivan_deduct_inventory_for_completed_order: ' . $e->getMessage());
        }
    }
}

/**
 * Публичный статус «готово» / «выдано»: убрать напоминание AUTO_SUPPLY из календаря и списать учтённые позиции со склада.
 */
/**
 * Фиксирует дату завершения для портала (один раз, при первом переходе в done/delivered).
 */
function fixarivan_set_public_completed_at_if_needed(PDO $pdo, string $orderOrDocumentId, string $publicNorm): void
{
    $orderOrDocumentId = trim($orderOrDocumentId);
    $publicNorm = fixarivan_normalize_public_status($publicNorm);
    if ($orderOrDocumentId === '' || !in_array($publicNorm, ['done', 'delivered'], true)) {
        return;
    }
    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        'UPDATE orders SET public_completed_at = :d WHERE (order_id = :oid OR document_id = :did) AND (public_completed_at IS NULL OR TRIM(public_completed_at) = \'\')'
    );
    $stmt->execute([':d' => $today, ':oid' => $orderOrDocumentId, ':did' => $orderOrDocumentId]);
}

function fixarivan_on_order_terminal_public_status(PDO $pdo, string $orderId, string $publicNorm): void
{
    $orderId = trim($orderId);
    $publicNorm = fixarivan_normalize_public_status($publicNorm);
    if ($orderId === '' || !in_array($publicNorm, ['done', 'delivered'], true)) {
        return;
    }

    $docRow = $pdo->prepare('SELECT document_id, order_id FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1');
    $docRow->execute([':o' => $orderId, ':d' => $orderId]);
    $or = $docRow->fetch(PDO::FETCH_ASSOC);
    $documentId = is_array($or) ? trim((string) ($or['document_id'] ?? '')) : '';
    $variants = fixarivan_order_id_variants_for_pdo($pdo, $documentId, $orderId);
    if ($variants === []) {
        $variants = [$orderId];
    }
    fixarivan_clear_supply_calendar_for_order_ids($pdo, $variants);
    fixarivan_deduct_inventory_for_completed_order($pdo, $orderId);
    fixarivan_set_public_completed_at_if_needed($pdo, $orderId, $publicNorm);
}

/**
 * Поддержка JSON заказа в storage в соответствии со SQLite (после смены статуса).
 */
function fixarivan_patch_order_json_public_status_if_exists(string $documentId, string $publicStatus): void
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        return;
    }
    $publicStatus = fixarivan_normalize_public_status($publicStatus);
    $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'orders';
    $path = $base . DIRECTORY_SEPARATOR . $documentId . '.json';
    if (!is_file($path)) {
        return;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return;
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return;
    }
    $now = date('c');
    $j['public_status'] = $publicStatus;
    $j['order_status'] = $publicStatus;
    $j['date_updated'] = $now;
    $enc = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($enc === false) {
        return;
    }
    @file_put_contents($path, $enc, LOCK_EX);
    $tok = trim((string)($j['client_token'] ?? ''));
    if ($tok !== '') {
        $tp = fixarivan_orders_tokens_storage_dir() . DIRECTORY_SEPARATOR . $tok . '.json';
        if (is_file($tp)) {
            @file_put_contents($tp, $enc, LOCK_EX);
        }
    }
}

/**
 * Квитанция с оплатой «Оплачено» и подписью мастера: заказ → delivered (выдан / завершён по оплате).
 */
function fixarivan_sync_order_status_after_paid_receipt(PDO $pdo, string $orderIdHint): void
{
    $orderIdHint = trim($orderIdHint);
    if ($orderIdHint === '') {
        return;
    }
    $q = $pdo->prepare('SELECT order_id, document_id, public_status, order_status FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1');
    $q->execute([':o' => $orderIdHint, ':d' => $orderIdHint]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($r)) {
        return;
    }
    $pub = fixarivan_normalize_public_status($r['public_status'] ?? $r['order_status'] ?? null);
    if ($pub === 'delivered') {
        fixarivan_set_public_completed_at_if_needed($pdo, $orderIdHint, 'delivered');

        return;
    }
    $now = date('c');
    $pdo->prepare('UPDATE orders SET public_status = :p, order_status = :p2, date_updated = :u WHERE order_id = :oid OR document_id = :did')
        ->execute([':p' => 'delivered', ':p2' => 'delivered', ':u' => $now, ':oid' => $orderIdHint, ':did' => $orderIdHint]);
    $oidHook = trim((string)($r['order_id'] ?? ''));
    if ($oidHook === '') {
        $oidHook = trim((string)($r['document_id'] ?? ''));
    }
    fixarivan_on_order_terminal_public_status($pdo, $oidHook, 'delivered');
    $docId = trim((string)($r['document_id'] ?? ''));
    if ($docId !== '') {
        fixarivan_patch_order_json_public_status_if_exists($docId, 'delivered');
    }
}
