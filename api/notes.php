<?php
declare(strict_types=1);

/**
 * Заметки мастера (SQLite) — только админ-сессия.
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/api_response.php';

function notes_generate_id(): string {
    return bin2hex(random_bytes(16));
}

function notes_preview_title(string $title, string $body): string {
    $t = trim($title);
    if ($t !== '') {
        return mb_strlen($t) > 120 ? mb_substr($t, 0, 117) . '…' : $t;
    }
    $b = trim($body);
    if ($b === '') {
        return 'Новая заметка';
    }
    $line = preg_split('/\R/u', $b, 2)[0] ?? $b;

    return mb_strlen($line) > 120 ? mb_substr($line, 0, 117) . '…' : $line;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    api_json_send(false, null, 'SQLite: ' . $e->getMessage(), []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $nid = trim((string)($_GET['note_id'] ?? ''));
    if ($nid !== '') {
        $stmt = $pdo->prepare('SELECT note_id, title, body, created_at, updated_at FROM notes WHERE note_id = :id LIMIT 1');
        $stmt->execute([':id' => $nid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            api_json_send(false, null, 'Заметка не найдена', []);
            exit;
        }
        api_json_send(true, ['note' => $row], null, [], ['note' => $row]);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['', ''], $q) . '%';
        $stmt = $pdo->prepare(
            'SELECT note_id, title, body, created_at, updated_at FROM notes
             WHERE title LIKE :q OR body LIKE :q2
             ORDER BY updated_at DESC
             LIMIT 200'
        );
        $stmt->execute([':q' => $like, ':q2' => $like]);
    } else {
        $stmt = $pdo->query('SELECT note_id, title, body, created_at, updated_at FROM notes ORDER BY updated_at DESC LIMIT 500');
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    api_json_send(true, ['notes' => $rows], null, [], ['notes' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        api_json_send(false, null, 'Некорректный JSON', []);
        exit;
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));

    if ($action === 'delete') {
        $nid = trim((string)($input['note_id'] ?? ''));
        if ($nid === '') {
            api_json_send(false, null, 'Нужен note_id', []);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM notes WHERE note_id = :id');
        $stmt->execute([':id' => $nid]);
        api_json_send(true, ['deleted' => $stmt->rowCount() > 0], null, [], ['deleted' => $stmt->rowCount() > 0]);
        exit;
    }

    if ($action === 'save' || $action === '') {
        $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d H:i:s');
        $body = (string)($input['body'] ?? '');
        $titleIn = trim((string)($input['title'] ?? ''));
        $title = $titleIn !== '' ? $titleIn : notes_preview_title('', $body);

        $nid = trim((string)($input['note_id'] ?? ''));
        if ($nid === '') {
            $nid = notes_generate_id();
            $stmt = $pdo->prepare(
                'INSERT INTO notes (note_id, title, body, created_at, updated_at)
                 VALUES (:note_id, :title, :body, :c, :u)'
            );
            $stmt->execute([
                ':note_id' => $nid,
                ':title' => $title,
                ':body' => $body,
                ':c' => $now,
                ':u' => $now,
            ]);
            api_json_send(
                true,
                ['note_id' => $nid, 'updated_at' => $now],
                null,
                [],
                ['note_id' => $nid, 'updated_at' => $now]
            );
            exit;
        }

        $stmt = $pdo->prepare('UPDATE notes SET title = :title, body = :body, updated_at = :u WHERE note_id = :id');
        $stmt->execute([
            ':title' => $title,
            ':body' => $body,
            ':u' => $now,
            ':id' => $nid,
        ]);
        if ($stmt->rowCount() === 0) {
            api_json_send(false, null, 'Заметка не найдена', []);
            exit;
        }
        api_json_send(true, ['note_id' => $nid, 'updated_at' => $now], null, [], ['note_id' => $nid, 'updated_at' => $now]);
        exit;
    }

    api_json_send(false, null, 'Неизвестное действие', []);
    exit;
}

api_json_send(false, null, 'Метод не поддерживается', []);
