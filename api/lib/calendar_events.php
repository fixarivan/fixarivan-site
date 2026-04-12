<?php
declare(strict_types=1);

/**
 * События календаря — только SQLite, таблица calendar_events.
 */

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
    return $row === false ? null : $row;
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
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    return [
        'today' => array_slice($today, 0, 10),
        'upcoming' => array_slice($upcoming, 0, 10),
        'overdue' => array_slice($overdue, 0, 10),
        'now' => $nowIso,
    ];
}
