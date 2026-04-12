<?php
declare(strict_types=1);

/**
 * События календаря: GET summary|event_id|range|month, POST save|delete.
 * Только админ-сессия; данные в SQLite (calendar_events).
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, POST, OPTIONS', 'Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/calendar_events.php';
require_once __DIR__ . '/lib/api_response.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    api_json_send(false, null, 'SQLite: ' . $e->getMessage(), []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['summary']) && $_GET['summary'] === '1') {
        $sum = fixarivan_calendar_summary($pdo);
        api_json_send(true, $sum, null, [], $sum);
        exit;
    }

    $singleId = isset($_GET['event_id']) ? trim((string) $_GET['event_id']) : '';
    if ($singleId === '' && isset($_GET['event'])) {
        $singleId = trim((string) $_GET['event']);
    }
    if ($singleId !== '') {
        $row = fixarivan_calendar_get_event_by_id($pdo, $singleId);
        if ($row === null) {
            api_json_send(false, null, 'Событие не найдено', []);
            exit;
        }
        $starts = (string) ($row['starts_at'] ?? '');
        $monthStr = '';
        if ($starts !== '') {
            try {
                $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
                $t = new DateTimeImmutable($starts, $tz);
                $monthStr = $t->format('Y-m');
            } catch (Exception $e) {
                $monthStr = strlen($starts) >= 7 ? substr($starts, 0, 7) : '';
            }
        }
        $payload = ['event' => $row, 'month' => $monthStr];
        api_json_send(true, $payload, null, [], $payload);
        exit;
    }

    if (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month'])) {
        $m = (string) $_GET['month'];
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $from = new DateTimeImmutable($m . '-01 00:00:00', $tz);
        $to = $from->modify('first day of next month');
        $list = fixarivan_calendar_events_in_range($pdo, $from->format('c'), $to->format('c'));
        api_json_send(true, ['events' => $list, 'month' => $m], null, [], ['events' => $list]);
        exit;
    }

    $from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
    $to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
    if ($from !== '' && $to !== '') {
        $list = fixarivan_calendar_events_in_range($pdo, $from, $to);
        api_json_send(true, ['events' => $list], null, [], ['events' => $list]);
        exit;
    }

    api_json_send(false, null, 'Укажите summary=1, event_id=…, month=YYYY-MM или from/to (ISO).', []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        api_json_send(false, null, 'Некорректный JSON', []);
        exit;
    }

    $action = isset($input['action']) ? trim((string) $input['action']) : '';

    if ($action === 'delete') {
        $eid = trim((string) ($input['event_id'] ?? ''));
        if ($eid === '') {
            api_json_send(false, null, 'Нужен event_id', []);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM calendar_events WHERE event_id = :eid');
        $stmt->execute([':eid' => $eid]);
        api_json_send(true, ['deleted' => $eid], null, [], ['deleted' => $eid]);
        exit;
    }

    if ($action !== 'save') {
        api_json_send(false, null, 'Неизвестное action', []);
        exit;
    }

    $title = trim((string) ($input['title'] ?? ''));
    $startsAt = trim((string) ($input['starts_at'] ?? ''));
    if ($title === '' || $startsAt === '') {
        api_json_send(false, null, 'Нужны title и starts_at', []);
        exit;
    }

    $allDay = !empty($input['all_day']) ? 1 : 0;
    $endsAt = isset($input['ends_at']) ? trim((string) $input['ends_at']) : '';
    $notes = isset($input['notes']) ? trim((string) $input['notes']) : '';
    $status = trim((string) ($input['status'] ?? 'planned'));
    if (!in_array($status, ['planned', 'done', 'cancelled'], true)) {
        $status = 'planned';
    }
    $linkType = isset($input['link_type']) ? trim((string) $input['link_type']) : '';
    $linkId = isset($input['link_id']) ? trim((string) $input['link_id']) : '';
    if ($linkType !== '' && !in_array($linkType, ['order', 'receipt', 'report'], true)) {
        $linkType = '';
        $linkId = '';
    }
    if ($linkType === '') {
        $linkId = '';
    }

    $now = date('c');
    $existingId = trim((string) ($input['event_id'] ?? ''));

    if ($existingId !== '') {
        $stmt = $pdo->prepare('SELECT id FROM calendar_events WHERE event_id = :e LIMIT 1');
        $stmt->execute([':e' => $existingId]);
        if ($stmt->fetchColumn()) {
            $stmt = $pdo->prepare(
                'UPDATE calendar_events SET
                    title = :title,
                    starts_at = :starts_at,
                    ends_at = :ends_at,
                    all_day = :all_day,
                    status = :status,
                    notes = :notes,
                    link_type = :link_type,
                    link_id = :link_id,
                    updated_at = :updated_at
                 WHERE event_id = :event_id'
            );
            $stmt->execute([
                ':title' => $title,
                ':starts_at' => $startsAt,
                ':ends_at' => $endsAt === '' ? null : $endsAt,
                ':all_day' => $allDay,
                ':status' => $status,
                ':notes' => $notes === '' ? null : $notes,
                ':link_type' => $linkType === '' ? null : $linkType,
                ':link_id' => $linkId === '' ? null : $linkId,
                ':updated_at' => $now,
                ':event_id' => $existingId,
            ]);
            api_json_send(true, ['event_id' => $existingId, 'updated' => true], null, [], ['event_id' => $existingId]);
            exit;
        }
    }

    $newId = fixarivan_calendar_generate_event_id();
    $stmt = $pdo->prepare(
        'INSERT INTO calendar_events (
            event_id, title, starts_at, ends_at, all_day, status, notes, link_type, link_id, created_at, updated_at
        ) VALUES (
            :event_id, :title, :starts_at, :ends_at, :all_day, :status, :notes, :link_type, :link_id, :created_at, :updated_at
        )'
    );
    $stmt->execute([
        ':event_id' => $newId,
        ':title' => $title,
        ':starts_at' => $startsAt,
        ':ends_at' => $endsAt === '' ? null : $endsAt,
        ':all_day' => $allDay,
        ':status' => $status,
        ':notes' => $notes === '' ? null : $notes,
        ':link_type' => $linkType === '' ? null : $linkType,
        ':link_id' => $linkId === '' ? null : $linkId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    api_json_send(true, ['event_id' => $newId, 'created' => true], null, [], ['event_id' => $newId]);
    exit;
}

api_json_send(false, null, 'Метод не поддерживается', []);
