<?php
declare(strict_types=1);

putenv('FIXARIVAN_BOT_API_KEY=bot-integration-test-key');
$_ENV['FIXARIVAN_BOT_API_KEY'] = 'bot-integration-test-key';

require_once __DIR__ . '/../api/sqlite.php';
require_once __DIR__ . '/../api/lib/bot_lead.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

assert_true(
    fixarivan_bot_verify_api_key('bot-integration-test-key'),
    'valid api key accepted'
);
assert_true(
    !fixarivan_bot_verify_api_key('wrong-key'),
    'invalid api key rejected'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureSqliteSchema($pdo);

$triggerId = 'BOT-TEST-' . bin2hex(random_bytes(8));
$payload = [
    'lead_source' => 'whatsapp',
    'chat_id' => '358999000001@s.whatsapp.net',
    'language' => 'fi',
    'summary' => '[BOT-TEST] integration diagnostic lead — archive after review',
    'client_name' => '[BOT-TEST] Integration',
    'lead_state' => 'received',
    'trigger_message_id' => $triggerId,
    'priority' => 'normal',
];

[$ok1, $data1, , $code1] = fixarivan_bot_upsert_lead($pdo, $payload);
assert_true($ok1 === true, 'first upsert succeeds');
assert_true($code1 === 201, 'first upsert returns 201');
assert_true(($data1['created'] ?? false) === true, 'first upsert created=true');
assert_true(($data1['idempotent_replay'] ?? true) === false, 'first upsert not replay');

$countAfterFirst = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE lead_idempotency_key = 'wa:msg:{$triggerId}'")->fetchColumn();
$clientsAfterFirst = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE phone = '358999000001'")->fetchColumn();
assert_true($countAfterFirst === 1, 'exactly one order after first upsert');
assert_true($clientsAfterFirst === 1, 'exactly one client after first upsert');

[$ok2, $data2, , $code2] = fixarivan_bot_upsert_lead($pdo, $payload);
assert_true($ok2 === true, 'second upsert succeeds');
assert_true($code2 === 200, 'second upsert returns 200');
assert_true(($data2['idempotent_replay'] ?? false) === true, 'second upsert is idempotent replay');
assert_true(($data2['document_id'] ?? '') === ($data1['document_id'] ?? ''), 'same document_id on replay');

$countAfterSecond = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE lead_idempotency_key = 'wa:msg:{$triggerId}'")->fetchColumn();
assert_true($countAfterSecond === 1, 'no duplicate order after replay');

[$okBad] = fixarivan_bot_validate_lead_payload(array_merge($payload, [
    'trigger_message_id' => '',
    'idempotency_key' => '',
]));
assert_true($okBad === false, 'empty trigger rejected on validate');

echo "bot_lead_integration_test: OK\n";
echo "TEST_RECORD document_id=" . ($data1['document_id'] ?? '') . " trigger_message_id={$triggerId}\n";
echo "NOTE: test used in-memory SQLite only; no production data touched.\n";
