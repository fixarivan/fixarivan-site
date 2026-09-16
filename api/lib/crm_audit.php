<?php
declare(strict_types=1);

require_once __DIR__ . '/../sqlite.php';

function fixarivan_audit_actor(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_path' => '/',
            'cookie_samesite' => 'Lax',
            'cookie_httponly' => true,
        ]);
    }

    return trim((string) ($_SESSION['admin_username'] ?? 'admin'));
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $result
 */
function fixarivan_audit_log(
    PDO $pdo,
    string $action,
    string $entityType,
    string $entityId,
    array $payload = [],
    array $result = [],
    ?string $requestId = null
): void {
    $requestId = trim((string) ($requestId ?? ''));
    if ($requestId === '') {
        $requestId = null;
    }
    $now = date('c');
    try {
        if ($requestId !== null) {
            $dup = $pdo->prepare('SELECT result_json FROM crm_audit_log WHERE request_id = :r LIMIT 1');
            $dup->execute([':r' => $requestId]);
            $existing = $dup->fetchColumn();
            if (is_string($existing) && $existing !== '') {
                return;
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO crm_audit_log (request_id, action, actor, entity_type, entity_id, payload_json, result_json, created_at)
             VALUES (:request_id, :action, :actor, :entity_type, :entity_id, :payload_json, :result_json, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':action' => $action,
            ':actor' => fixarivan_audit_actor(),
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ':result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ':created_at' => $now,
        ]);
    } catch (Throwable $e) {
        error_log('fixarivan_audit_log: ' . $e->getMessage());
    }
}

/** @return array<string,mixed>|null */
function fixarivan_audit_find_by_request_id(PDO $pdo, string $requestId): ?array
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM crm_audit_log WHERE request_id = :r LIMIT 1');
    $stmt->execute([':r' => $requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}
