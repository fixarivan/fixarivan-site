<?php
declare(strict_types=1);

require_once __DIR__ . '/order_center.php';
require_once __DIR__ . '/client_order_i18n.php';
require_once __DIR__ . '/security_settings.php';

function fixarivan_bot_api_key(): string
{
    $env = getenv('FIXARIVAN_BOT_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $settings = fixarivan_security_settings_load();

    return trim((string) ($settings['bot_api_key'] ?? ''));
}

function fixarivan_bot_verify_api_key(?string $provided): bool
{
    $expected = fixarivan_bot_api_key();
    if ($expected === '') {
        return false;
    }
    $given = trim((string) ($provided ?? ''));

    return $given !== '' && hash_equals($expected, $given);
}

function fixarivan_bot_read_api_key_from_request(): string
{
    $hdr = trim((string) ($_SERVER['HTTP_X_FIXARIVAN_API_KEY'] ?? ''));
    if ($hdr !== '') {
        return $hdr;
    }
    $auth = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

/** @return list<string> */
function fixarivan_bot_allowed_lead_sources(): array
{
    return ['whatsapp', 'telegram', 'website', 'phone', 'manual', 'import'];
}

/** @return list<string> */
function fixarivan_bot_allowed_service_types(): array
{
    return ['repair', 'service', 'installation', 'sale', 'hybrid', 'consultation'];
}

function fixarivan_bot_normalize_lead_source(?string $raw): string
{
    $v = strtolower(trim((string) ($raw ?? '')));
    if ($v === '') {
        return 'manual';
    }

    return in_array($v, fixarivan_bot_allowed_lead_sources(), true) ? $v : 'manual';
}

function fixarivan_bot_normalize_service_type(?string $raw): string
{
    $v = strtolower(trim((string) ($raw ?? '')));
    if ($v === '') {
        return 'repair';
    }

    return in_array($v, fixarivan_bot_allowed_service_types(), true) ? $v : 'repair';
}

function fixarivan_bot_service_type_to_order_type(string $serviceType): string
{
    return match ($serviceType) {
        'sale' => 'sale',
        'hybrid', 'consultation', 'installation', 'service' => 'custom',
        default => 'repair',
    };
}

function fixarivan_bot_normalize_priority(?string $raw): string
{
    $v = strtolower(trim((string) ($raw ?? '')));
    $map = [
        'low' => 'normal',
        'normal' => 'normal',
        'medium' => 'normal',
        'high' => 'high',
        'urgent' => 'urgent',
        'срочно' => 'urgent',
        'высокий' => 'high',
    ];
    if ($v === '') {
        return 'normal';
    }

    return $map[$v] ?? (in_array($v, ['normal', 'high', 'urgent'], true) ? $v : 'normal');
}

/** @return list<string> */
function fixarivan_bot_allowed_lead_states(): array
{
    return ['received', 'analyzing', 'ready_for_review'];
}

function fixarivan_bot_normalize_lead_state(?string $raw): string
{
    $v = strtolower(trim(str_replace(['-', ' '], '_', (string) ($raw ?? ''))));
    $aliases = [
        'received' => 'received',
        'получено' => 'received',
        'new' => 'received',
        'analyzing' => 'analyzing',
        'analysis' => 'analyzing',
        'collecting' => 'analyzing',
        'collecting_info' => 'analyzing',
        'анализируется' => 'analyzing',
        'ready_for_review' => 'ready_for_review',
        'ready' => 'ready_for_review',
        'review' => 'ready_for_review',
        'complete' => 'ready_for_review',
        'готово_к_проверке' => 'ready_for_review',
    ];
    if ($v === '') {
        return 'ready_for_review';
    }

    return $aliases[$v] ?? (in_array($v, fixarivan_bot_allowed_lead_states(), true) ? $v : 'ready_for_review');
}

function fixarivan_bot_lead_state_label_ru(string $state): string
{
    return match ($state) {
        'received' => 'Получено',
        'analyzing' => 'Анализируется',
        'ready_for_review' => 'Готово к проверке',
        default => $state,
    };
}

function fixarivan_bot_order_status_for_lead_state(string $leadState): string
{
    return $leadState === 'ready_for_review' ? 'pending_review' : 'lead_collecting';
}

function fixarivan_bot_priority_rank(string $priority): int
{
    return match (fixarivan_bot_normalize_priority($priority)) {
        'urgent' => 3,
        'high' => 2,
        default => 1,
    };
}

function fixarivan_bot_pick_priority(string $incoming, ?string $existing = null): string
{
    $in = fixarivan_bot_normalize_priority($incoming);
    if ($existing === null || trim($existing) === '') {
        return $in;
    }
    $cur = fixarivan_bot_normalize_priority($existing);

    return fixarivan_bot_priority_rank($in) >= fixarivan_bot_priority_rank($cur) ? $in : $cur;
}

function fixarivan_bot_priority_label_en(string $priority): string
{
    return match (fixarivan_bot_normalize_priority($priority)) {
        'urgent' => 'Urgent',
        'high' => 'High',
        default => 'Normal',
    };
}

/** Скрытые от Track фазы сбора (ещё не готово к проверке инженером). */
function fixarivan_bot_order_hidden_from_track(?array $row): bool
{
    if (!is_array($row)) {
        return false;
    }
    $pipe = strtolower(trim((string) ($row['lead_pipeline_status'] ?? '')));
    if ($pipe === 'received' || $pipe === 'analyzing') {
        return true;
    }
    $st = strtolower(trim((string) ($row['order_status'] ?? $row['public_status'] ?? '')));

    return $st === 'lead_collecting';
}

function fixarivan_bot_order_visible_in_track(?array $row): bool
{
    return !fixarivan_bot_order_hidden_from_track($row);
}

function fixarivan_bot_clamp_completion_score(mixed $raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        return null;
    }
    $n = (int) round((float) $raw);
    if ($n < 0) {
        return 0;
    }
    if ($n > 100) {
        return 100;
    }

    return $n;
}

function fixarivan_bot_display_name(string $name, string $phone): string
{
    $name = trim($name);
    if ($name !== '') {
        return $name;
    }
    $norm = fixarivan_normalize_phone($phone);
    if ($norm !== '') {
        return 'Клиент ' . fixarivan_format_phone_fi_display($norm);
    }

    return 'Клиент';
}

function fixarivan_bot_lead_source_label_ru(string $source): string
{
    $map = [
        'whatsapp' => 'WhatsApp',
        'telegram' => 'Telegram',
        'website' => 'Сайт',
        'phone' => 'Телефон',
        'manual' => 'Вручную',
        'import' => 'Импорт',
    ];

    return $map[$source] ?? $source;
}

function fixarivan_bot_text_looks_like_template(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    if (str_contains($text, '{{') || str_contains($text, '}}')) {
        return true;
    }

    return (bool) preg_match('/\$\(\s*[\'"]/u', $text);
}

function fixarivan_bot_is_whatsapp_lid_jid(string $jid): bool
{
    return str_ends_with(strtolower(trim($jid)), '@lid');
}

function fixarivan_bot_is_whatsapp_phone_jid(string $jid): bool
{
    $jid = strtolower(trim($jid));
    if ($jid === '' || fixarivan_bot_is_whatsapp_lid_jid($jid)) {
        return false;
    }
    if (!str_contains($jid, '@')) {
        return false;
    }
    [$user, $host] = explode('@', $jid, 2);
    if (!preg_match('/^\d{7,15}$/', $user)) {
        return false;
    }

    return in_array($host, ['s.whatsapp.net', 'c.us'], true);
}

function fixarivan_bot_extract_phone_from_whatsapp_jid(string $jid): string
{
    if (!fixarivan_bot_is_whatsapp_phone_jid($jid)) {
        return '';
    }
    [$user] = explode('@', strtolower(trim($jid)), 2);

    return fixarivan_normalize_phone($user);
}

function fixarivan_bot_canonical_whatsapp_jid(string $rawJid, string $phoneNorm): string
{
    if (fixarivan_bot_is_whatsapp_phone_jid($rawJid)) {
        return strtolower(trim($rawJid));
    }
    if ($phoneNorm !== '') {
        return $phoneNorm . '@s.whatsapp.net';
    }

    return trim($rawJid);
}

/** @return array{0:string,1:string,2:?string} phone_norm, chat_jid, error_code */
function fixarivan_bot_resolve_phone_from_payload(array $payload): array
{
    $explicit = trim((string) ($payload['phone'] ?? $payload['client_phone'] ?? ''));
    $chatId = trim((string) ($payload['chat_id'] ?? $payload['chatId'] ?? ''));
    $waChat = trim((string) ($payload['whatsapp_chat_id'] ?? $payload['whatsappChatId'] ?? ''));
    $candidates = array_values(array_unique(array_filter([$chatId, $waChat, $explicit])));

    $sawLid = false;
    foreach ($candidates as $candidate) {
        if (fixarivan_bot_is_whatsapp_lid_jid($candidate)) {
            $sawLid = true;
            continue;
        }
        $fromJid = fixarivan_bot_extract_phone_from_whatsapp_jid($candidate);
        if ($fromJid !== '') {
            return [$fromJid, fixarivan_bot_canonical_whatsapp_jid($candidate, $fromJid), null];
        }
    }

    $norm = fixarivan_normalize_phone($explicit);
    if ($norm !== '') {
        $jid = fixarivan_bot_canonical_whatsapp_jid($chatId !== '' ? $chatId : $waChat, $norm);

        return [$norm, $jid, null];
    }

    if ($sawLid) {
        return ['', '', 'lid_without_phone'];
    }

    return ['', '', 'phone_required'];
}

function fixarivan_bot_payload_is_whatsapp(array $payload): bool
{
    $source = fixarivan_bot_normalize_lead_source($payload['lead_source'] ?? $payload['leadSource'] ?? $payload['source'] ?? null);
    if ($source === 'whatsapp') {
        return true;
    }
    foreach (['chat_id', 'chatId', 'whatsapp_chat_id', 'whatsappChatId', 'phone', 'client_phone'] as $key) {
        $raw = strtolower(trim((string) ($payload[$key] ?? '')));
        if ($raw === '') {
            continue;
        }
        if (str_contains($raw, '@s.whatsapp.net') || str_contains($raw, '@c.us') || fixarivan_bot_is_whatsapp_lid_jid($raw)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function fixarivan_bot_expand_payload(array $payload): array
{
    if (isset($payload['source']) && is_array($payload['source'])) {
        $source = $payload['source'];
        if (trim((string) ($payload['lead_source'] ?? '')) === '') {
            $payload['lead_source'] = $source['channel'] ?? $source['lead_source'] ?? '';
        }
        if (trim((string) ($payload['chat_id'] ?? '')) === '') {
            $payload['chat_id'] = $source['whatsapp_chat_id'] ?? $source['chat_id'] ?? '';
        }
        if (trim((string) ($payload['trigger_message_id'] ?? '')) === '') {
            $payload['trigger_message_id'] = $source['trigger_message_id']
                ?? $source['evolution_message_id']
                ?? $source['message_id']
                ?? '';
        }
    }
    if (isset($payload['client']) && is_array($payload['client'])) {
        $client = $payload['client'];
        foreach ([
            'client_name' => 'name',
            'client_phone' => 'phone',
            'client_email' => 'email',
            'language' => 'language',
        ] as $flat => $nested) {
            if (trim((string) ($payload[$flat] ?? '')) === '' && isset($client[$nested])) {
                $payload[$flat] = $client[$nested];
            }
        }
        if (trim((string) ($payload['phone'] ?? '')) === '' && isset($client['phone'])) {
            $payload['phone'] = $client['phone'];
        }
    }
    if (isset($payload['request']) && is_array($payload['request'])) {
        $request = $payload['request'];
        foreach ([
            'problem_description' => 'problem_description',
            'summary' => 'summary',
            'service_type' => 'service_type',
        ] as $flat => $nested) {
            if (trim((string) ($payload[$flat] ?? '')) === '' && isset($request[$nested])) {
                $payload[$flat] = $request[$nested];
            }
        }
        if (trim((string) ($payload['problem'] ?? '')) === '' && isset($request['problem'])) {
            $payload['problem'] = $request['problem'];
        }
    }
    if (isset($payload['device']) && is_array($payload['device'])) {
        $device = $payload['device'];
        if (trim((string) ($payload['device_type'] ?? '')) === '' && isset($device['type'])) {
            $payload['device_type'] = $device['type'];
        }
        if (trim((string) ($payload['device_model'] ?? '')) === '' && isset($device['model'])) {
            $payload['device_model'] = $device['model'];
        }
    }
    if (trim((string) ($payload['client_name'] ?? '')) === '' && trim((string) ($payload['name'] ?? '')) !== '') {
        $payload['client_name'] = trim((string) $payload['name']);
    }
    if (trim((string) ($payload['client_name'] ?? '')) === '') {
        $displayName = trim((string) ($payload['display_name'] ?? $payload['displayName'] ?? ''));
        if ($displayName !== '') {
            $payload['client_name'] = $displayName;
        }
    }
    if (trim((string) ($payload['place_of_acceptance'] ?? '')) === '') {
        foreach (['area', 'location', 'municipality'] as $alt) {
            $altVal = trim((string) ($payload[$alt] ?? ''));
            if ($altVal !== '') {
                $payload['place_of_acceptance'] = $altVal;
                break;
            }
        }
    }

    return $payload;
}

function fixarivan_bot_derive_idempotency_key(array $payload): string
{
    $explicit = trim((string) ($payload['idempotency_key'] ?? $payload['idempotencyKey'] ?? ''));
    if ($explicit !== '') {
        return $explicit;
    }
    $trigger = trim((string) ($payload['trigger_message_id'] ?? $payload['triggerMessageId'] ?? ''));
    if ($trigger !== '') {
        return 'wa:msg:' . $trigger;
    }
    $external = trim((string) ($payload['external_ref'] ?? $payload['externalRef'] ?? ''));

    return $external;
}

function fixarivan_bot_text_min_length(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }

    return strlen($text);
}

function fixarivan_bot_is_unique_violation(PDOException $e): bool
{
    $msg = $e->getMessage();

    return str_contains($msg, 'UNIQUE constraint failed') || $e->getCode() === '23000';
}

/** @param array<string,mixed> $context */
function fixarivan_bot_log_event(string $event, array $context = []): void
{
    $safe = ['event' => $event];
    foreach ($context as $key => $value) {
        $k = strtolower((string) $key);
        if (in_array($k, ['api_key', 'key', 'token', 'authorization', 'password', 'secret'], true)) {
            continue;
        }
        if (in_array($k, ['phone', 'client_phone', 'client_name', 'client_email', 'summary', 'problem_description'], true)) {
            $safe[$key] = '[redacted]';
            continue;
        }
        if (is_scalar($value)) {
            $text = (string) $value;
            $safe[$key] = strlen($text) > 120 ? substr($text, 0, 80) . '…' : $text;
        } else {
            $safe[$key] = '[complex]';
        }
    }
    error_log('fixarivan_bot ' . json_encode($safe, JSON_UNESCAPED_UNICODE));
}

/**
 * @param array<string,mixed> $payload
 * @return array{0: bool, 1: ?array<string,mixed>, 2: ?string, 3: int, 4: ?string}
 */
function fixarivan_bot_validate_lead_payload(array $payload): array
{
    $payload = fixarivan_bot_expand_payload($payload);
    [$phoneNorm, $chatJid, $phoneErr] = fixarivan_bot_resolve_phone_from_payload($payload);
    $problem = trim((string) ($payload['problem_description'] ?? $payload['problem'] ?? ''));
    $summary = trim((string) ($payload['summary'] ?? ''));
    $notes = trim((string) ($payload['notes'] ?? ''));
    $nextAction = trim((string) ($payload['next_action'] ?? $payload['nextAction'] ?? ''));
    $leadState = fixarivan_bot_normalize_lead_state($payload['lead_state'] ?? $payload['leadState'] ?? null);
    $isWhatsApp = fixarivan_bot_payload_is_whatsapp($payload);
    $triggerMessageId = trim((string) ($payload['trigger_message_id'] ?? $payload['triggerMessageId'] ?? ''));
    $language = fixarivan_client_order_normalize_lang($payload['language'] ?? $payload['lang'] ?? '');

    if ($phoneErr === 'lid_without_phone') {
        return [false, null, 'WhatsApp @lid without a reliable phone number is not accepted', 400, 'lid_without_phone'];
    }
    if ($phoneNorm === '') {
        return [false, null, 'phone or valid WhatsApp JID @s.whatsapp.net is required', 400, 'phone_required'];
    }

    if ($isWhatsApp) {
        if ($triggerMessageId === '') {
            return [false, null, 'trigger_message_id is required for WhatsApp leads', 400, 'trigger_message_id_required'];
        }
        if (!in_array($language, ['ru', 'fi', 'en'], true)) {
            return [false, null, 'language is required for WhatsApp leads (ru, fi, en)', 400, 'language_required'];
        }
        $description = $problem !== '' ? $problem : $summary;
        if ($description === '' || fixarivan_bot_text_min_length($description) < 3) {
            return [false, null, 'summary or problem_description is required for WhatsApp leads', 400, 'description_required'];
        }
        $rawChatId = trim((string) ($payload['chat_id'] ?? $payload['chatId'] ?? ''));
        if ($rawChatId !== '' && !fixarivan_bot_is_whatsapp_phone_jid($rawChatId) && !fixarivan_bot_is_whatsapp_lid_jid($rawChatId)) {
            return [false, null, 'chat_id must be a WhatsApp phone JID @s.whatsapp.net when provided', 400, 'invalid_whatsapp_jid'];
        }
    }

    foreach ([
        'problem_description' => $problem,
        'summary' => $summary,
        'notes' => $notes,
        'next_action' => $nextAction,
    ] as $field => $value) {
        if ($value !== '' && fixarivan_bot_text_looks_like_template($value)) {
            return [false, null, $field . ' contains unresolved template placeholders', 400, 'template_placeholder'];
        }
    }

    if ($leadState === 'ready_for_review') {
        if ($problem === '') {
            return [false, null, 'problem_description is required when lead_state is ready_for_review', 400, 'problem_required'];
        }
        if (fixarivan_bot_text_min_length($problem) < 3) {
            return [false, null, 'problem_description is too short', 400, 'problem_too_short'];
        }
    }

    return [true, null, null, 200, null];
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function fixarivan_bot_normalize_lead_payload(array $payload): array
{
    $payload = fixarivan_bot_expand_payload($payload);
    [$phoneNorm, $chatJid] = fixarivan_bot_resolve_phone_from_payload($payload);
    $phone = $phoneNorm !== '' ? $phoneNorm : trim((string) ($payload['phone'] ?? $payload['client_phone'] ?? ''));
    $name = trim((string) ($payload['client_name'] ?? $payload['name'] ?? ''));
    $email = trim((string) ($payload['client_email'] ?? $payload['email'] ?? ''));
    $lang = fixarivan_client_order_normalize_lang($payload['language'] ?? $payload['lang'] ?? 'ru');
    $serviceType = fixarivan_bot_normalize_service_type($payload['service_type'] ?? $payload['serviceType'] ?? null);
    $partsRequired = !empty($payload['parts_required']) || !empty($payload['partsRequired']);
    $score = fixarivan_bot_clamp_completion_score($payload['completion_score'] ?? $payload['completionScore'] ?? null);
    $priority = fixarivan_bot_normalize_priority($payload['priority'] ?? $payload['computed_priority'] ?? null);
    $source = fixarivan_bot_normalize_lead_source($payload['lead_source'] ?? $payload['leadSource'] ?? $payload['source'] ?? null);
    $leadState = fixarivan_bot_normalize_lead_state($payload['lead_state'] ?? $payload['leadState'] ?? null);
    $triggerMessageId = trim((string) ($payload['trigger_message_id'] ?? $payload['triggerMessageId'] ?? ''));
    $idempotencyKey = fixarivan_bot_derive_idempotency_key($payload);
    $externalRef = trim((string) ($payload['external_ref'] ?? $payload['externalRef'] ?? ''));
    if ($externalRef === '' && $triggerMessageId !== '') {
        $externalRef = 'wa:msg:' . $triggerMessageId;
    }
    if ($name === '' && $triggerMessageId !== '' && str_starts_with($triggerMessageId, 'BOT-TEST-')) {
        $name = '[BOT-TEST] WhatsApp lead';
    }

    return [
        'phone' => $phone,
        'phone_norm' => $phoneNorm !== '' ? $phoneNorm : fixarivan_normalize_phone($phone),
        'client_name' => fixarivan_bot_display_name($name, $phone),
        'client_email' => $email,
        'language' => $lang,
        'device_type' => trim((string) ($payload['device_type'] ?? $payload['deviceType'] ?? '')),
        'device_model' => trim((string) ($payload['device_model'] ?? $payload['deviceModel'] ?? '')),
        'problem_description' => trim((string) ($payload['problem_description'] ?? $payload['problem'] ?? '')),
        'lead_source' => $source,
        'service_type' => $serviceType,
        'order_type' => fixarivan_bot_service_type_to_order_type($serviceType),
        'parts_required' => $partsRequired ? 1 : 0,
        'completion_score' => $score,
        'summary' => trim((string) ($payload['summary'] ?? '')),
        'notes' => trim((string) ($payload['notes'] ?? '')),
        'next_action' => trim((string) ($payload['next_action'] ?? $payload['nextAction'] ?? '')),
        'priority' => $priority,
        'lead_state' => $leadState,
        'chat_id' => $chatJid !== '' ? $chatJid : trim((string) ($payload['chat_id'] ?? $payload['chatId'] ?? '')),
        'external_ref' => $externalRef,
        'idempotency_key' => $idempotencyKey,
        'trigger_message_id' => $triggerMessageId,
        'place_of_acceptance' => trim((string) ($payload['place_of_acceptance'] ?? '')),
        'is_test' => str_contains($name, '[BOT-TEST]') || str_starts_with($triggerMessageId, 'BOT-TEST-'),
    ];
}

function fixarivan_bot_find_lead_by_idempotency(PDO $pdo, string $key): ?array
{
    $key = trim($key);
    if ($key === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE lead_idempotency_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function fixarivan_bot_find_open_lead(PDO $pdo, string $chatId, string $externalRef): ?array
{
    if ($chatId !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE lead_chat_id = :c AND order_status IN ('lead_collecting', 'pending_review') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':c' => $chatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }
    if ($externalRef !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE lead_external_ref = :r AND order_status IN ('lead_collecting', 'pending_review') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':r' => $externalRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    return null;
}

/** @deprecated use fixarivan_bot_find_open_lead */
function fixarivan_bot_find_pending_lead(PDO $pdo, string $chatId, string $externalRef): ?array
{
    return fixarivan_bot_find_open_lead($pdo, $chatId, $externalRef);
}

/**
 * @param array<string,mixed> $norm
 * @param array<string,mixed>|null $existing
 */
function fixarivan_bot_merge_lead_fields(array $norm, ?array $existing = null): array
{
    $merge = static function (string $key, string $fallback = '') use ($norm, $existing): string {
        $new = trim((string) ($norm[$key] ?? ''));
        if ($new !== '') {
            return $new;
        }
        if ($existing !== null) {
            return trim((string) ($existing[$key] ?? $fallback));
        }

        return $fallback;
    };

    $score = $norm['completion_score'];
    if ($score === null && $existing !== null && isset($existing['lead_completion_score'])) {
        $score = fixarivan_bot_clamp_completion_score($existing['lead_completion_score']);
    }

    return [
        'client_name' => $merge('client_name'),
        'client_phone' => $norm['phone_norm'] !== '' ? $norm['phone_norm'] : $merge('client_phone'),
        'client_email' => $merge('client_email'),
        'language' => $norm['language'] ?? 'ru',
        'device_type' => $merge('device_type'),
        'device_model' => $merge('device_model'),
        'problem_description' => $merge('problem_description'),
        'priority' => fixarivan_bot_pick_priority(
            (string) ($norm['priority'] ?? 'normal'),
            $existing !== null ? (string) ($existing['priority'] ?? '') : null
        ),
        'order_type' => $norm['order_type'] ?? 'repair',
        'lead_state' => $norm['lead_state'] ?? 'ready_for_review',
        'lead_pipeline_status' => $norm['lead_state'] ?? 'ready_for_review',
        'order_status' => fixarivan_bot_order_status_for_lead_state((string) ($norm['lead_state'] ?? 'ready_for_review')),
        'public_status' => fixarivan_bot_order_status_for_lead_state((string) ($norm['lead_state'] ?? 'ready_for_review')),
        'legacy_status' => fixarivan_bot_order_status_for_lead_state((string) ($norm['lead_state'] ?? 'ready_for_review')),
        'lead_source' => $norm['lead_source'] ?? 'manual',
        'lead_service_type' => $norm['service_type'] ?? 'repair',
        'lead_parts_required' => (int) ($norm['parts_required'] ?? 0),
        'lead_completion_score' => $score,
        'lead_summary' => $merge('summary'),
        'lead_notes' => $merge('notes'),
        'lead_next_action' => $merge('next_action'),
        'lead_chat_id' => $norm['chat_id'] !== '' ? $norm['chat_id'] : ($existing['lead_chat_id'] ?? null),
        'lead_external_ref' => $norm['external_ref'] !== '' ? $norm['external_ref'] : ($existing['lead_external_ref'] ?? null),
        'lead_idempotency_key' => $norm['idempotency_key'] !== '' ? $norm['idempotency_key'] : ($existing['lead_idempotency_key'] ?? null),
        'place_of_acceptance' => $merge('place_of_acceptance'),
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array{0: bool, 1: array<string,mixed>, 2: ?string, 3: int}
 */
function fixarivan_bot_upsert_lead(PDO $pdo, array $payload): array
{
    [$ok, , $err, $code] = fixarivan_bot_validate_lead_payload($payload);
    if (!$ok) {
        fixarivan_bot_log_event('lead_rejected', [
            'code' => $code,
            'http' => $code,
            'reason' => $err,
            'lead_source' => $payload['lead_source'] ?? null,
        ]);

        return [false, [], $err, $code];
    }

    $norm = fixarivan_bot_normalize_lead_payload($payload);

    if ($norm['idempotency_key'] !== '') {
        $byKey = fixarivan_bot_find_lead_by_idempotency($pdo, $norm['idempotency_key']);
        if ($byKey !== null) {
            return fixarivan_bot_update_lead_row($pdo, $byKey, $norm, true);
        }
    }

    $existing = fixarivan_bot_find_open_lead($pdo, $norm['chat_id'], $norm['external_ref']);
    if ($existing !== null) {
        return fixarivan_bot_update_lead_row($pdo, $existing, $norm, false);
    }

    return fixarivan_bot_insert_lead_row($pdo, $norm);
}

function fixarivan_bot_recover_existing_lead(PDO $pdo, array $norm): ?array
{
    if ($norm['idempotency_key'] !== '') {
        $byKey = fixarivan_bot_find_lead_by_idempotency($pdo, $norm['idempotency_key']);
        if ($byKey !== null) {
            return $byKey;
        }
    }

    return fixarivan_bot_find_open_lead($pdo, $norm['chat_id'], $norm['external_ref']);
}

/**
 * @param array<string,mixed> $norm
 * @return array{0: bool, 1: array<string,mixed>, 2: ?string, 3: int}
 */
function fixarivan_bot_insert_lead_row(PDO $pdo, array $norm): array
{
    $clientId = fixarivan_ensure_client(
        $pdo,
        $norm['client_name'],
        $norm['phone'],
        $norm['client_email'] ?? ''
    );
    if ($clientId === null) {
        fixarivan_bot_log_event('client_create_failed', ['lead_source' => $norm['lead_source'] ?? null]);

        return [false, [], 'cannot create client', 500];
    }

    $fields = fixarivan_bot_merge_lead_fields($norm, null);
    $documentId = fixarivan_generate_order_document_id();
    $orderId = $documentId;
    $token = fixarivan_generate_client_token();
    $now = date('c');
    $internalPrefix = !empty($norm['is_test']) ? '[BOT-TEST] ' : '';
    $internal = $fields['lead_summary'] !== ''
        ? $internalPrefix . '[Lead] ' . $fields['lead_summary']
        : $internalPrefix . '[Lead] Предварительное обращение';

    $stmt = $pdo->prepare(
        'INSERT INTO orders (
            document_id, date_created, date_updated, place_of_acceptance, date_of_acceptance, unique_code, language,
            client_name, client_phone, client_email,
            device_model, device_serial, device_type, device_condition, accessories, device_password,
            problem_description, priority, status,
            technician_name, work_date,
            pattern_data, client_signature,
            client_token, viewed_at, signed_at, order_id, client_id,
            parts_purchase_total, parts_sale_total,
            order_type, public_status, public_comment, public_expected_date, public_estimated_cost, estimated_labor_cost, internal_comment, order_lines_json,
            order_status, parts_status,
            lead_external_ref, lead_chat_id, lead_idempotency_key, lead_source, lead_service_type,
            lead_parts_required, lead_completion_score, lead_summary, lead_notes, lead_next_action,
            lead_pipeline_status
        ) VALUES (
            :document_id, :date_created, :date_updated, :place_of_acceptance, NULL, :unique_code, :language,
            :client_name, :client_phone, :client_email,
            :device_model, NULL, :device_type, NULL, NULL, NULL,
            :problem_description, :priority, :status,
            NULL, NULL,
            NULL, NULL,
            :client_token, NULL, NULL, :order_id, :client_id,
            NULL, NULL,
            :order_type, :public_status, NULL, NULL, NULL, NULL, :internal_comment, NULL,
            :order_status, NULL,
            :lead_external_ref, :lead_chat_id, :lead_idempotency_key, :lead_source, :lead_service_type,
            :lead_parts_required, :lead_completion_score, :lead_summary, :lead_notes, :lead_next_action,
            :lead_pipeline_status
        )'
    );
    try {
        $stmt->execute([
            ':document_id' => $documentId,
            ':date_created' => $now,
            ':date_updated' => $now,
            ':place_of_acceptance' => $fields['place_of_acceptance'] !== '' ? $fields['place_of_acceptance'] : null,
            ':unique_code' => $orderId,
            ':language' => $fields['language'],
            ':client_name' => $fields['client_name'],
            ':client_phone' => $fields['client_phone'],
            ':client_email' => $fields['client_email'],
            ':device_model' => $fields['device_model'],
            ':device_type' => $fields['device_type'],
            ':problem_description' => $fields['problem_description'],
            ':priority' => $fields['priority'],
            ':status' => $fields['legacy_status'],
            ':client_token' => $token,
            ':order_id' => $orderId,
            ':client_id' => $clientId,
            ':order_type' => $fields['order_type'],
            ':public_status' => $fields['public_status'],
            ':internal_comment' => $internal,
            ':order_status' => $fields['order_status'],
            ':lead_external_ref' => $fields['lead_external_ref'],
            ':lead_chat_id' => $fields['lead_chat_id'],
            ':lead_idempotency_key' => $fields['lead_idempotency_key'],
            ':lead_source' => $fields['lead_source'],
            ':lead_service_type' => $fields['lead_service_type'],
            ':lead_parts_required' => $fields['lead_parts_required'],
            ':lead_completion_score' => $fields['lead_completion_score'],
            ':lead_summary' => $fields['lead_summary'],
            ':lead_notes' => $fields['lead_notes'],
            ':lead_next_action' => $fields['lead_next_action'],
            ':lead_pipeline_status' => $fields['lead_pipeline_status'],
        ]);
    } catch (PDOException $e) {
        if (fixarivan_bot_is_unique_violation($e)) {
            $existing = fixarivan_bot_recover_existing_lead($pdo, $norm);
            if ($existing !== null) {
                fixarivan_bot_log_event('insert_conflict_recovered', [
                    'document_id' => $existing['document_id'] ?? null,
                    'lead_source' => $norm['lead_source'] ?? null,
                ]);

                return fixarivan_bot_update_lead_row($pdo, $existing, $norm, true);
            }
        }
        fixarivan_bot_log_event('insert_failed', ['type' => get_class($e)]);

        return [false, [], 'cannot create lead', 500];
    }

    $rowId = (int) $pdo->lastInsertId();
    fixarivan_bot_log_event('lead_created', [
        'document_id' => $documentId,
        'lead_source' => $fields['lead_source'],
        'is_test' => !empty($norm['is_test']),
    ]);

    return [true, fixarivan_bot_format_lead_response($pdo, $rowId, true, false), null, 201];
}

/**
 * @param array<string,mixed> $existing
 * @param array<string,mixed> $norm
 * @return array{0: bool, 1: array<string,mixed>, 2: ?string, 3: int}
 */
function fixarivan_bot_update_lead_row(PDO $pdo, array $existing, array $norm, bool $idempotentReplay = false): array
{
    $currentStatus = strtolower(trim((string) ($existing['order_status'] ?? $existing['public_status'] ?? '')));
    if (!in_array($currentStatus, ['lead_collecting', 'pending_review'], true)) {
        return [false, [], 'lead already confirmed — cannot overwrite', 409];
    }

    $fields = fixarivan_bot_merge_lead_fields($norm, $existing);
    $now = date('c');
    $internal = trim((string) ($existing['internal_comment'] ?? ''));
    if ($fields['lead_summary'] !== '' && !str_contains($internal, $fields['lead_summary'])) {
        $internal = trim($internal . "\n[Lead] " . $fields['lead_summary']);
    }

    $clientId = (int) ($existing['client_id'] ?? 0);
    if ($clientId <= 0) {
        $resolved = fixarivan_ensure_client($pdo, $fields['client_name'], $norm['phone'], $fields['client_email']);
        if ($resolved !== null) {
            $clientId = $resolved;
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE orders SET
            date_updated = :u,
            client_name = :client_name,
            client_phone = :client_phone,
            client_email = :client_email,
            language = :language,
            device_model = :device_model,
            device_type = :device_type,
            problem_description = :problem_description,
            priority = :priority,
            order_type = :order_type,
            status = :status,
            public_status = :public_status,
            order_status = :order_status,
            internal_comment = :internal_comment,
            client_id = :client_id,
            lead_external_ref = :lead_external_ref,
            lead_chat_id = :lead_chat_id,
            lead_idempotency_key = :lead_idempotency_key,
            lead_source = :lead_source,
            lead_service_type = :lead_service_type,
            lead_parts_required = :lead_parts_required,
            lead_completion_score = :lead_completion_score,
            lead_summary = :lead_summary,
            lead_notes = :lead_notes,
            lead_next_action = :lead_next_action,
            lead_pipeline_status = :lead_pipeline_status,
            place_of_acceptance = COALESCE(NULLIF(:place_of_acceptance, ''), place_of_acceptance)
         WHERE document_id = :document_id'
    );
    $stmt->execute([
        ':u' => $now,
        ':place_of_acceptance' => $fields['place_of_acceptance'],
        ':client_name' => $fields['client_name'],
        ':client_phone' => $fields['client_phone'],
        ':client_email' => $fields['client_email'],
        ':language' => $fields['language'],
        ':device_model' => $fields['device_model'],
        ':device_type' => $fields['device_type'],
        ':problem_description' => $fields['problem_description'],
        ':priority' => $fields['priority'],
        ':order_type' => $fields['order_type'],
        ':status' => $fields['legacy_status'],
        ':public_status' => $fields['public_status'],
        ':order_status' => $fields['order_status'],
        ':internal_comment' => $internal,
        ':client_id' => $clientId > 0 ? $clientId : null,
        ':lead_external_ref' => $fields['lead_external_ref'],
        ':lead_chat_id' => $fields['lead_chat_id'],
        ':lead_idempotency_key' => $fields['lead_idempotency_key'],
        ':lead_source' => $fields['lead_source'],
        ':lead_service_type' => $fields['lead_service_type'],
        ':lead_parts_required' => $fields['lead_parts_required'],
        ':lead_completion_score' => $fields['lead_completion_score'],
        ':lead_summary' => $fields['lead_summary'],
        ':lead_notes' => $fields['lead_notes'],
        ':lead_next_action' => $fields['lead_next_action'],
        ':lead_pipeline_status' => $fields['lead_pipeline_status'],
        ':document_id' => $existing['document_id'],
    ]);

    $rowId = (int) ($existing['id'] ?? 0);
    if ($idempotentReplay) {
        fixarivan_bot_log_event('lead_idempotent_replay', [
            'document_id' => $existing['document_id'] ?? null,
            'lead_source' => $norm['lead_source'] ?? null,
        ]);
    }

    return [true, fixarivan_bot_format_lead_response($pdo, $rowId, false, $idempotentReplay), null, 200];
}

/**
 * @return array{0: bool, 1: array<string,mixed>, 2: ?string, 3: int}
 */
function fixarivan_bot_confirm_lead(PDO $pdo, string $orderDocumentId): array
{
    $orderDocumentId = trim($orderDocumentId);
    if ($orderDocumentId === '') {
        return [false, [], 'order_id or document_id required', 400];
    }
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE document_id = :d OR order_id = :d LIMIT 1');
    $stmt->execute([':d' => $orderDocumentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [false, [], 'lead not found', 404];
    }
    $status = fixarivan_normalize_public_status($row['order_status'] ?? $row['public_status'] ?? null);
    if ($status !== 'pending_review') {
        return [false, [], 'order is not pending review', 409];
    }

    $now = date('c');
    $today = date('Y-m-d');
    $upd = $pdo->prepare(
        "UPDATE orders SET
            order_status = 'in_progress',
            public_status = 'in_progress',
            status = 'pending',
            date_updated = :u,
            date_of_acceptance = COALESCE(NULLIF(TRIM(date_of_acceptance), ''), :today),
            place_of_acceptance = COALESCE(NULLIF(TRIM(place_of_acceptance), ''), 'Turku, Finland'),
            lead_confirmed_at = :u
         WHERE document_id = :d"
    );
    $upd->execute([':u' => $now, ':today' => $today, ':d' => $row['document_id']]);

    return [true, fixarivan_bot_format_lead_response($pdo, (int) $row['id'], false), null, 200];
}

/** @return array<string,mixed> */
function fixarivan_bot_format_lead_response(PDO $pdo, int $rowId, bool $created, bool $idempotentReplay = false): array
{
    $stmt = $pdo->prepare('SELECT o.*, c.client_id AS client_public_id FROM orders o LEFT JOIN clients c ON c.id = o.client_id WHERE o.id = :id LIMIT 1');
    $stmt->execute([':id' => $rowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['success' => false];
    }
    require_once __DIR__ . '/site_url.php';
    $token = trim((string) ($row['client_token'] ?? ''));
    $portalUrl = $token !== ''
        ? fixarivan_absolute_url('client_portal.php?token=' . rawurlencode($token))
        : null;
    $pipe = strtolower(trim((string) ($row['lead_pipeline_status'] ?? '')));
    if ($pipe === '') {
        $pipe = fixarivan_normalize_public_status($row['order_status'] ?? null) === 'pending_review'
            ? 'ready_for_review'
            : 'analyzing';
    }
    $visible = fixarivan_bot_order_visible_in_track($row);
    $priority = fixarivan_bot_normalize_priority((string) ($row['priority'] ?? 'normal'));

    $externalRef = trim((string) ($row['lead_external_ref'] ?? ''));
    $triggerMessageId = '';
    if (str_starts_with($externalRef, 'wa:msg:')) {
        $triggerMessageId = substr($externalRef, 7);
    }

    return [
        'success' => true,
        'api_version' => 1,
        'created' => $created,
        'idempotent_replay' => $idempotentReplay,
        'pending_review' => fixarivan_normalize_public_status($row['order_status'] ?? null) === 'pending_review',
        'visible_in_track' => $visible,
        'lead_state' => $pipe,
        'lead_state_label' => fixarivan_bot_lead_state_label_ru($pipe),
        'priority' => $priority,
        'priority_label' => fixarivan_bot_priority_label_en($priority),
        'order_id' => (string) ($row['order_id'] ?? $row['document_id'] ?? ''),
        'order_row_id' => (int) ($row['id'] ?? 0),
        'document_id' => (string) ($row['document_id'] ?? ''),
        'client_name' => (string) ($row['client_name'] ?? ''),
        'client_phone' => (string) ($row['client_phone'] ?? ''),
        'place_of_acceptance' => trim((string) ($row['place_of_acceptance'] ?? '')) ?: null,
        'client_id' => (int) ($row['client_id'] ?? 0),
        'client_public_id' => (string) ($row['client_public_id'] ?? ''),
        'portal_token' => $token !== '' ? $token : null,
        'portal_url' => $portalUrl,
        'track_url' => fixarivan_absolute_url('track.html'),
        'review_url' => fixarivan_absolute_url(
            'order_new.html?from_lead=1&document_id=' . rawurlencode((string) $row['document_id'])
        ),
        'status' => (string) ($row['order_status'] ?? ''),
        'order_status' => (string) ($row['order_status'] ?? ''),
        'completion_score' => isset($row['lead_completion_score']) ? (int) $row['lead_completion_score'] : null,
        'lead_source' => (string) ($row['lead_source'] ?? ''),
        'trigger_message_id' => $triggerMessageId !== '' ? $triggerMessageId : null,
        'idempotency_key' => trim((string) ($row['lead_idempotency_key'] ?? '')) ?: null,
        'message' => $visible
            ? ($created ? 'Lead ready for review' : 'Lead updated — ready for review')
            : ($created ? 'Lead received — hidden until ready_for_review' : 'Lead updated — still collecting'),
    ];
}

function fixarivan_is_pending_review_order(?array $row): bool
{
    if (!is_array($row)) {
        return false;
    }

    return fixarivan_normalize_public_status($row['order_status'] ?? $row['public_status'] ?? null) === 'pending_review';
}
