<?php
declare(strict_types=1);

/**
 * События календаря — только SQLite, таблица calendar_events.
 */

/**
 * Короткий хвост номера заказа для подписи (например US7K), без полного FIX-…
 */
function fixarivan_short_order_ref(string $orderId): string
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return '';
    }
    if (preg_match('/-([A-Z0-9]{4,12})$/i', $orderId, $m)) {
        return $m[1];
    }

    return strlen($orderId) <= 8 ? $orderId : substr($orderId, -6);
}

/**
 * Заголовок напоминания AUTO_SUPPLY: устройство / первая позиция / клиент + короткий ref заказа.
 *
 * @param list<array{name?:string,qty?:float,type?:string,note?:string}> $items
 */
function fixarivan_build_supply_calendar_title(string $orderId, array $items, string $clientName, string $deviceModel): string
{
    $deviceModel = trim($deviceModel);
    $clientName = trim($clientName);
    $subject = '';
    if ($deviceModel !== '') {
        $subject = $deviceModel;
    } elseif ($items !== []) {
        $subject = trim((string)($items[0]['name'] ?? ''));
    }
    if ($subject === '') {
        $subject = $clientName !== '' ? $clientName : '';
    }
    if ($subject === '') {
        $subject = 'Заказ';
    }
    $maxLen = 52;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($subject) > $maxLen) {
            $subject = mb_substr($subject, 0, $maxLen - 1) . '…';
        }
    } elseif (strlen($subject) > $maxLen) {
        $subject = substr($subject, 0, $maxLen - 1) . '…';
    }
    $ref = fixarivan_short_order_ref($orderId);
    if ($ref !== '') {
        return 'Закупка: ' . $subject . ' · ' . $ref;
    }

    return 'Закупка: ' . $subject;
}

/**
 * Парсит строки позиций из блока notes AUTO_SUPPLY («- наименование x2»).
 *
 * @return list<array{name:string,qty:float,type:string,note:string}>
 */
function fixarivan_parse_supply_line_items_for_title(string $notes): array
{
    $items = [];
    foreach (explode("\n", $notes) as $line) {
        $line = trim($line);
        if ($line === '' || ($line[0] ?? '') !== '-') {
            continue;
        }
        if (preg_match('/^-\s*(.+?)\s+x([\d.,]+)/u', $line, $m)) {
            $items[] = [
                'name' => trim($m[1]),
                'qty' => (float)str_replace(',', '.', $m[2]),
                'type' => '',
                'note' => '',
            ];
        }
    }

    return $items;
}

function fixarivan_first_order_line_name_from_json(?string $json): string
{
    $json = trim((string)$json);
    if ($json === '' || $json === '[]') {
        return '';
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return '';
    }
    foreach ($d as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $n = trim((string)($ln['name'] ?? $ln['title'] ?? ''));

        if ($n !== '') {
            return $n;
        }
    }

    return '';
}

/**
 * Подмена title для отображения: старые события «Закупка по заказу FIX-…» → человекочитаемо из orders.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function fixarivan_calendar_enrich_event_row_for_display(PDO $pdo, array $row): array
{
    $notes = (string)($row['notes'] ?? '');
    if (strpos($notes, 'AUTO_SUPPLY') === false) {
        return $row;
    }
    $linkId = trim((string)($row['link_id'] ?? ''));
    if ($linkId === '' || trim((string)($row['link_type'] ?? '')) !== 'order') {
        return $row;
    }

    static $cache = [];

    if (!isset($cache[$linkId])) {
        $stmt = $pdo->prepare(
            'SELECT device_model, client_name, order_lines_json FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1'
        );
        $stmt->execute([':o' => $linkId, ':d' => $linkId]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$linkId] = is_array($o) ? $o : null;
    }

    $o = $cache[$linkId];
    if ($o === null) {
        return $row;
    }

    $device = trim((string)($o['device_model'] ?? ''));
    $client = trim((string)($o['client_name'] ?? ''));
    $items = fixarivan_parse_supply_line_items_for_title($notes);
    if ($items === []) {
        $firstName = fixarivan_first_order_line_name_from_json((string)($o['order_lines_json'] ?? ''));
        if ($firstName !== '') {
            $items = [['name' => $firstName, 'qty' => 1.0, 'type' => '', 'note' => '']];
        }
    }

    $row['title'] = fixarivan_build_supply_calendar_title($linkId, $items, $client, $device);

    return $row;
}

function fixarivan_calendar_generate_event_id(): string {
    return 'EVT-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Одно событие по event_id (для диплинков календаря).
 *
 * @return array<string,mixed>|null
 */
function fixarivan_calendar_get_event_by_id(PDO $pdo, string $eventId): ?array {
    if ($eventId === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, event_id, title, starts_at, ends_at, all_day, status, notes, link_type, link_id, created_at, updated_at
         FROM calendar_events WHERE event_id = :e LIMIT 1'
    );
    $stmt->execute([':e' => $eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    return fixarivan_calendar_enrich_event_row_for_display($pdo, $row);
}

/**
 * @return list<array<string,mixed>>
 */
function fixarivan_calendar_events_in_range(PDO $pdo, string $fromIso, string $toIso): array {
    $stmt = $pdo->prepare(
        'SELECT id, event_id, title, starts_at, ends_at, all_day, status, notes, link_type, link_id, created_at, updated_at
         FROM calendar_events
         WHERE starts_at >= :f AND starts_at < :t
         ORDER BY starts_at ASC'
    );
    $stmt->execute([':f' => $fromIso, ':t' => $toIso]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = fixarivan_calendar_enrich_event_row_for_display($pdo, $r);
    }

    return $out;
}

/**
 * Сводка для дашборда: сегодня / ближайшие / просроченные.
 *
 * @return array{today: list, upcoming: list, overdue: list, now: string}
 */
function fixarivan_calendar_summary(PDO $pdo): array {
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    $now = new DateTimeImmutable('now', $tz);
    $nowIso = $now->format('c');

    $startOfToday = $now->setTime(0, 0, 0);
    $startOfTomorrow = $startOfToday->modify('+1 day');
    $weekAheadExclusive = $startOfToday->modify('+8 days');

    $stmt = $pdo->prepare(
        'SELECT id, event_id, title, starts_at, ends_at, all_day, status, notes, link_type, link_id, created_at, updated_at
         FROM calendar_events
         WHERE status = :st
         ORDER BY starts_at ASC'
    );
    $stmt->execute([':st' => 'planned']);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = [];
    $upcoming = [];
    $overdue = [];

    foreach ($all as $row) {
        $start = $row['starts_at'] ?? '';
        if ($start === '') {
            continue;
        }
        try {
            $t = new DateTimeImmutable($start, $tz);
        } catch (Exception $e) {
            continue;
        }

        if ($t < $startOfToday) {
            $overdue[] = $row;
            continue;
        }
        if ($t >= $startOfToday && $t < $startOfTomorrow) {
            $today[] = $row;
            continue;
        }
        if ($t >= $startOfTomorrow && $t < $weekAheadExclusive) {
            $upcoming[] = $row;
        }
    }

    usort($overdue, static function ($a, $b) {
        return strcmp((string)($a['starts_at'] ?? ''), (string)($b['starts_at'] ?? ''));
    });
    usort($today, static function ($a, $b) {
        return strcmp((string)($a['starts_at'] ?? ''), (string)($b['starts_at'] ?? ''));
    });
    usort($upcoming, static function ($a, $b) {
        return strcmp((string)($a['starts_at'] ?? ''), (string)($b['starts_at'] ?? ''));
    });

    $mapEnrich = static function (array $rows) use ($pdo): array {
        $slice = array_slice($rows, 0, 10);
        $out = [];
        foreach ($slice as $r) {
            $out[] = fixarivan_calendar_enrich_event_row_for_display($pdo, $r);
        }

        return $out;
    };

    return [
        'today' => $mapEnrich($today),
        'upcoming' => $mapEnrich($upcoming),
        'overdue' => $mapEnrich($overdue),
        'now' => $nowIso,
    ];
}
