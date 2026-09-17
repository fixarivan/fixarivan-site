<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/bot_lead.php';

$bad = fixarivan_bot_validate_lead_payload([
    'phone' => '+358401234567',
    'lead_state' => 'ready_for_review',
    'problem_description' => "{{ \$('Parse Response') }}",
]);
if ($bad[0] !== false) {
    fwrite(STDERR, "expected template rejection\n");
    exit(1);
}

$ok = fixarivan_bot_validate_lead_payload([
    'phone' => '+358401234567',
    'lead_state' => 'ready_for_review',
    'problem_description' => 'Нужен ремонт экрана',
]);
if ($ok[0] !== true) {
    fwrite(STDERR, "expected valid payload\n");
    exit(1);
}

echo "bot_template_validate_test: OK\n";
