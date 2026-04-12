<?php
declare(strict_types=1);

/**
 * Единый JSON-ответ API: success, message, data, errors.
 * Старые клиенты могут читать верхнеуровневые поля из $legacy.
 */
function api_json_send(
    bool $success,
    $data = null,
    ?string $message = null,
    array $errors = [],
    array $legacy = []
): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = [
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ];
    if ($legacy !== []) {
        $payload = array_merge($payload, $legacy);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
