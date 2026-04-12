<?php
declare(strict_types=1);

/**
 * PDF generation: ADMIN (PHP session) or TOKEN-ONLY viewer (clientToken matches document).
 */
function fixarivan_pdf_generation_allowed(array $input): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_path' => '/',
            'cookie_samesite' => 'Lax',
            'cookie_httponly' => true,
        ]);
    }
    if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return true;
    }

    $documentType = $input['documentType'] ?? $input['type'] ?? null;
    $documentId = $input['documentId'] ?? ($input['data']['documentId'] ?? null);
    $token = trim((string)(
        $input['clientToken'] ?? $input['client_token']
        ?? ($input['data']['clientToken'] ?? $input['data']['client_token'] ?? '')
    ));
    if ($token === '' || !$documentType || !$documentId) {
        return false;
    }

    require_once __DIR__ . '/../sqlite.php';
    try {
        $pdo = getSqliteConnection();
        if ($documentType === 'order') {
            $stmt = $pdo->prepare('SELECT client_token FROM orders WHERE document_id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            return is_array($row) && isset($row['client_token']) && hash_equals((string)$row['client_token'], $token);
        }
        if ($documentType === 'receipt') {
            $stmt = $pdo->prepare('SELECT client_token FROM receipts WHERE document_id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            return is_array($row) && isset($row['client_token']) && hash_equals((string)$row['client_token'], $token);
        }
        if ($documentType === 'report') {
            $stmt = $pdo->prepare('SELECT token FROM mobile_reports WHERE report_id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            return is_array($row) && isset($row['token']) && hash_equals((string)$row['token'], $token);
        }
        if ($documentType === 'invoice') {
            $stmt = $pdo->prepare('SELECT client_token FROM invoices WHERE document_id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            return is_array($row) && isset($row['client_token']) && hash_equals((string)$row['client_token'], $token);
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}
