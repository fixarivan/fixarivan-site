<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/bot_lead.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

// JID parsing
assert_true(
    fixarivan_bot_extract_phone_from_whatsapp_jid('358401234567@s.whatsapp.net') === '358401234567',
    'extract phone from s.whatsapp.net'
);
assert_true(
    fixarivan_bot_extract_phone_from_whatsapp_jid('123456789012345@lid') === '',
    'reject @lid for phone extraction'
);
assert_true(
    fixarivan_bot_is_whatsapp_phone_jid('358401234567@s.whatsapp.net'),
    'valid whatsapp phone jid'
);
assert_true(
    !fixarivan_bot_is_whatsapp_phone_jid('123456789012345@lid'),
    'lid is not phone jid'
);

// Template guard
[$okTpl] = fixarivan_bot_validate_lead_payload([
    'phone' => '+358401234567',
    'lead_state' => 'ready_for_review',
    'problem_description' => "{{ \$('Parse Response') }}",
]);
assert_true($okTpl === false, 'reject n8n template in problem');

// WhatsApp contract
$waBase = [
    'lead_source' => 'whatsapp',
    'chat_id' => '358999000001@s.whatsapp.net',
    'language' => 'fi',
    'summary' => 'Test summary for contract',
    'lead_state' => 'received',
    'trigger_message_id' => 'BOT-TEST-CONTRACT-1',
];

[$okWa] = fixarivan_bot_validate_lead_payload($waBase);
assert_true($okWa === true, 'accept valid whatsapp payload');

[$okNoTrigger] = fixarivan_bot_validate_lead_payload(array_merge($waBase, ['trigger_message_id' => '']));
assert_true($okNoTrigger === false, 'reject empty trigger_message_id');

[$okLid] = fixarivan_bot_validate_lead_payload([
    'lead_source' => 'whatsapp',
    'chat_id' => '123456789012345@lid',
    'language' => 'fi',
    'summary' => 'LID only chat',
    'trigger_message_id' => 'BOT-TEST-LID',
]);
assert_true($okLid === false, 'reject @lid without phone');

[$okBadJid] = fixarivan_bot_validate_lead_payload([
    'lead_source' => 'whatsapp',
    'phone' => '+358999000002',
    'chat_id' => 'not-a-jid',
    'language' => 'ru',
    'summary' => 'Bad jid',
    'trigger_message_id' => 'BOT-TEST-JID',
]);
assert_true($okBadJid === false, 'reject invalid chat_id when whatsapp');

$key = fixarivan_bot_derive_idempotency_key(['trigger_message_id' => 'MSG-123']);
assert_true($key === 'wa:msg:MSG-123', 'derive idempotency from trigger_message_id');

// Website lead: name + area → place_of_acceptance
$expanded = fixarivan_bot_expand_payload([
    'lead_source' => 'website',
    'name' => 'Mika',
    'phone' => '+358889095301',
    'area' => 'Turku',
    'problem_description' => 'Laptop does not start',
    'lead_state' => 'ready_for_review',
]);
assert_true(($expanded['client_name'] ?? '') === 'Mika', 'website name maps to client_name');
assert_true(
    ($expanded['place_of_acceptance'] ?? '') === 'Turku',
    'area maps to place_of_acceptance'
);

$norm = fixarivan_bot_normalize_lead_payload($expanded);
assert_true($norm['client_name'] === 'Mika', 'website client_name in CRM');
assert_true($norm['place_of_acceptance'] === 'Turku', 'website place_of_acceptance in CRM');

echo "bot_lead_contract_test: OK\n";
