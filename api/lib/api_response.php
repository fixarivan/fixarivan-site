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
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        error_log('api_json_send: json_encode failed — ' . json_last_error_msg());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo '{"success":false,"message":"JSON encode error","data":null,"errors":[]}';
        return;
    }
    echo $json;
}
