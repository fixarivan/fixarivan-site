<?php
declare(strict_types=1);
require_once __DIR__ . '/api/sqlite.php';
require_once __DIR__ . '/api/lib/viewer_token_guard.php';

$viewerLang = strtolower(trim((string)($_GET['lang'] ?? 'ru')));
if (!in_array($viewerLang, ['ru', 'en', 'fi'], true)) {
    $viewerLang = 'ru';
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function humanizeKey(string $key): string {
    // Turn `cpuTemp` => `cpu Temp`, `services_list` => `services list`
    $s = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key) ?? $key;
    $s = str_replace('_', ' ', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    return $s !== '' ? $s : $key;
}

function formatValue($value): string {
    global $viewerLang;
    if (is_bool($value)) {
        $yes = ['ru' => 'Да', 'en' => 'Yes', 'fi' => 'Kyllä'][$viewerLang] ?? 'Yes';
        $no = ['ru' => 'Нет', 'en' => 'No', 'fi' => 'Ei'][$viewerLang] ?? 'No';

        return $value ? $yes : $no;
    }
    if (is_array($value)) {
        if ($value === []) {
            return '—';
        }
        return implode(', ', array_map(static function ($item): string {
            if (is_scalar($item) || $item === null) {
                return (string) $item;
            }
            return json_encode($item, JSON_UNESCAPED_UNICODE) ?: '';
        }, $value));
    }
    if (is_object($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $encoded !== false ? $encoded : '—';
    }
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : '—';
}

function batteryStatusLabel($value): string {
    global $viewerLang;
    $map = [
        'ru' => [
            'excellent' => 'Отличное (90-100%)',
            'good' => 'Хорошее (80-89%)',
            'satisfactory' => 'Удовлетворительное (70-79%)',
            'poor' => 'Плохое (60-69%)',
            'critical' => 'Критическое (<60%)',
        ],
        'en' => [
            'excellent' => 'Excellent (90-100%)',
            'good' => 'Good (80-89%)',
            'satisfactory' => 'Satisfactory (70-79%)',
            'poor' => 'Poor (60-69%)',
            'critical' => 'Critical (<60%)',
        ],
        'fi' => [
            'excellent' => 'Erinomainen (90-100%)',
            'good' => 'Hyvä (80-89%)',
            'satisfactory' => 'Tyydyttävä (70-79%)',
            'poor' => 'Huono (60-69%)',
            'critical' => 'Kriittinen (<60%)',
        ],
    ];
    $v = strtolower(trim((string)($value ?? '')));
    return $map[$viewerLang][$v] ?? ($v !== '' ? $v : '—');
}

function cleaningLabel(string $key): string {
    global $viewerLang;
    $map = [
        'ru' => [
            'external' => 'Внешняя очистка',
            'internal' => 'Внутренняя очистка',
            'software' => 'Очистка ПО',
            'virus' => 'Удаление вирусов',
        ],
        'en' => [
            'external' => 'External cleaning',
            'internal' => 'Internal cleaning',
            'software' => 'Software cleaning',
            'virus' => 'Virus removal',
        ],
        'fi' => [
            'external' => 'Ulkoinen puhdistus',
            'internal' => 'Sisäinen puhdistus',
            'software' => 'Ohjelmiston puhdistus',
            'virus' => 'Virusten poisto',
        ],
    ];
    return $map[$viewerLang][$key] ?? $key;
}

function softwareServiceLabel(string $key): string {
    global $viewerLang;
    $k = strtolower(trim($key));
    $map = [
        'ru' => [
            'os_install' => 'Установка ОС',
            'drivers' => 'Установка драйверов',
            'software' => 'Установка ПО',
            'virus' => 'Удаление вирусов',
            'recovery' => 'Восстановление данных',
            'optimization' => 'Оптимизация',
        ],
        'en' => [
            'os_install' => 'OS Installation',
            'drivers' => 'Driver Installation',
            'software' => 'Software Installation',
            'virus' => 'Virus Removal',
            'recovery' => 'Data Recovery',
            'optimization' => 'Optimization',
        ],
        'fi' => [
            'os_install' => 'Käyttöjärjestelmän asennus',
            'drivers' => 'Ohjainten asennus',
            'software' => 'Ohjelmiston asennus',
            'virus' => 'Virusten poisto',
            'recovery' => 'Tietojen palautus',
            'optimization' => 'Optimointi',
        ],
    ];
    return $map[$viewerLang][$k] ?? $k;
}

function componentTestLabel(string $key): string {
    global $viewerLang;
    $map = [
        'ru' => [
            'battery' => '🔋 Батарея',
            'cpu' => '🧠 CPU',
            'ram' => '💾 ОЗУ',
            'gpu' => '🎮 GPU',
            'motherboard' => '⚡ Мат. плата',
            'psu' => '🔌 БП',
            'storage' => '💽 Диск',
            'network' => '🌐 Сеть',
            'usb' => '🔌 USB',
            'screen' => '📱 Экран',
            'speaker' => '🔊 Динамик',
            'camera' => '📷 Камера',
            'charging' => '⚡ Зарядка',
            'wifi' => '📶 Wi-Fi',
            'bluetooth' => '🔵 Bluetooth',
            'sensors' => '🎯 Датчики',
            'microphone' => '🎤 Микрофон',
            'sim' => '📞 SIM',
            'buttons' => '🔘 Кнопки',
            'gps' => '🧭 GPS',
            'sound' => '🔊 Звук',
            'keyboard' => '⌨️ Клавиатура',
            'touchpad' => '👆 Тачпад',
            'display' => '🖥️ Дисплей',
            'cooling' => '❄️ Охлаждение',
        ],
        'en' => [
            'battery' => '🔋 Battery',
            'cpu' => '🧠 CPU',
            'ram' => '💾 RAM',
            'gpu' => '🎮 GPU',
            'motherboard' => '⚡ Motherboard',
            'psu' => '🔌 PSU',
            'storage' => '💽 Storage',
            'network' => '🌐 Network',
            'usb' => '🔌 USB',
            'screen' => '📱 Screen',
            'speaker' => '🔊 Speaker',
            'camera' => '📷 Camera',
            'charging' => '⚡ Charging',
            'wifi' => '📶 Wi-Fi',
            'bluetooth' => '🔵 Bluetooth',
            'sensors' => '🎯 Sensors',
            'microphone' => '🎤 Microphone',
            'sim' => '📞 SIM',
            'buttons' => '🔘 Buttons',
            'gps' => '🧭 GPS',
            'sound' => '🔊 Sound',
            'keyboard' => '⌨️ Keyboard',
            'touchpad' => '👆 Touchpad',
            'display' => '🖥️ Display',
            'cooling' => '❄️ Cooling',
        ],
        'fi' => [
            'battery' => '🔋 Akku',
            'cpu' => '🧠 CPU',
            'ram' => '💾 RAM',
            'gpu' => '🎮 GPU',
            'motherboard' => '⚡ Emolevy',
            'psu' => '🔌 Virtalähde',
            'storage' => '💽 Tallennus',
            'network' => '🌐 Verkko',
            'usb' => '🔌 USB',
            'screen' => '📱 Näyttö',
            'speaker' => '🔊 Kaiutin',
            'camera' => '📷 Kamera',
            'charging' => '⚡ Lataus',
            'wifi' => '📶 Wi-Fi',
            'bluetooth' => '🔵 Bluetooth',
            'sensors' => '🎯 Anturit',
            'microphone' => '🎤 Mikrofoni',
            'sim' => '📞 SIM',
            'buttons' => '🔘 Nappulat',
            'gps' => '🧭 GPS',
            'sound' => '🔊 Ääni',
            'keyboard' => '⌨️ Näppäimistö',
            'touchpad' => '👆 Kosketuslevy',
            'display' => '🖥️ Näyttö',
            'cooling' => '❄️ Jäähdytys',
        ],
    ];
    return $map[$viewerLang][$key] ?? $key;
}

function renderStars($rating, int $max = 10): string {
    $r = (int)$rating;
    if ($r < 0) $r = 0;
    if ($r > $max) $r = $max;

    $stars = '';
    for ($i = 1; $i <= $max; $i++) {
        $stars .= '<span class="star-view' . ($i <= $r ? ' active' : '') . '">⭐</span>';
    }

    return '<div class="stars-row">' . $stars . '</div><div class="rating-subtitle">' . h($r . '/' . $max) . '</div>';
}

function renderComponentTests($testsStr): string {
    $s = trim((string)($testsStr ?? ''));
    if ($s === '') return '—';

    // Формат: "battery:good,screen:bad,wifi:good"
    $parts = array_filter(array_map('trim', explode(',', $s)));
    if ($parts === []) return '—';

    $chips = [];
    foreach ($parts as $part) {
        $kv = explode(':', $part, 2);
        $key = strtolower(trim((string)($kv[0] ?? '')));
        $statusRaw = strtolower(trim((string)($kv[1] ?? '')));

        // Normalize chip statuses:
        // - ok/OK/good => good
        // - bad/problem => bad
        $status = match (true) {
            in_array($statusRaw, ['ok', 'good'], true) => 'good',
            $statusRaw === 'bad' || $statusRaw === 'problem' => 'bad',
            default => $statusRaw,
        };

        $label = componentTestLabel($key);
        global $viewerLang;
        $problemLabel = [
            'ru' => 'Проблема',
            'en' => 'Problem',
            'fi' => 'Ongelma',
        ][$viewerLang] ?? 'Problem';
        $okLabel = [
            'ru' => 'ОК',
            'en' => 'OK',
            'fi' => 'OK',
        ][$viewerLang] ?? 'OK';
        $statusLabel = $status === 'good' ? $okLabel : ($status === 'bad' ? $problemLabel : $statusRaw);

        $cls = 'chip-neutral';
        if ($status === 'good') $cls = 'chip-good';
        if ($status === 'bad') $cls = 'chip-bad';

        $chips[] = '<span class="chip-view ' . $cls . '">' . h($label . ': ' . $statusLabel) . '</span>';
    }

    return '<div class="chips-row">' . implode('', $chips) . '</div>';
}

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
$tokenNormalized = strtolower($token);
$report = null;

$viewerLangEarly = in_array($viewerLang, ['ru', 'en', 'fi'], true) ? $viewerLang : 'ru';

if ($token === '' && (!empty($_GET['id']) || !empty($_GET['document_id']))) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

if (!fixarivan_viewer_rate_allowed('report_view')) {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

$tokenLooksValid = $token !== '' && preg_match('/^[a-fA-F0-9]{16,128}$/', $token) === 1;

if ($token === '') {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

if (!$tokenLooksValid) {
    fixarivan_viewer_rate_failure('report_view');
    fixarivan_viewer_log_line('report_view', 'invalid_token_format', fixarivan_viewer_token_fingerprint($token), '');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

try {
    $pdo = getSqliteConnection();
    $stmt = $pdo->prepare(
        'SELECT report_id, token, created_at, client_name, phone, device_type, model, serial_number,
                tests_json, battery_json, diagnosis, recommendations, device_rating, appearance_rating,
                master_name, work_date, verification_code, raw_json
         FROM mobile_reports
         WHERE token = :token
         LIMIT 1'
    );
    $stmt->execute([':token' => $tokenNormalized]);
    $row = $stmt->fetch();

    if (!$row) {
        fixarivan_viewer_rate_failure('report_view');
        fixarivan_viewer_log_line('report_view', 'not_found', fixarivan_viewer_token_fingerprint($token), 'sqlite');
        fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
        exit;
    }

    $rawData = json_decode((string)($row['raw_json'] ?? ''), true);
    $report = [
        'report_id' => $row['report_id'] ?? '',
        'token' => $row['token'] ?? '',
        'created_at' => $row['created_at'] ?? '',
        'data' => is_array($rawData) ? $rawData : [
            'clientName' => $row['client_name'] ?? '',
            'clientPhone' => $row['phone'] ?? '',
            'deviceType' => $row['device_type'] ?? '',
            'deviceModel' => $row['model'] ?? '',
            'deviceSerial' => $row['serial_number'] ?? '',
            'diagnosis' => $row['diagnosis'] ?? '',
            'recommendations' => $row['recommendations'] ?? '',
            'deviceRating' => $row['device_rating'] ?? '',
            'conditionRating' => $row['appearance_rating'] ?? '',
            'technicianName' => $row['master_name'] ?? '',
            'workDate' => $row['work_date'] ?? '',
            'uniqueCode' => $row['verification_code'] ?? '',
        ],
    ];
} catch (Throwable $e) {
    $jsonPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reports'
        . DIRECTORY_SEPARATOR . $tokenNormalized . '.json';

    if (is_file($jsonPath)) {
        $raw = file_get_contents($jsonPath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $report = $decoded;
            } else {
                fixarivan_viewer_rate_failure('report_view');
                fixarivan_viewer_log_line('report_view', 'invalid_json', fixarivan_viewer_token_fingerprint($token), 'backup');
                fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
                exit;
            }
        } else {
            fixarivan_viewer_rate_failure('report_view');
            fixarivan_viewer_log_line('report_view', 'not_found', fixarivan_viewer_token_fingerprint($token), 'unreadable');
            fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
            exit;
        }
    } else {
        fixarivan_viewer_rate_failure('report_view');
        fixarivan_viewer_log_line('report_view', 'not_found', fixarivan_viewer_token_fingerprint($token), 'no_backup');
        fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
        exit;
    }
}

if (!is_array($report)) {
    fixarivan_viewer_rate_failure('report_view');
    fixarivan_viewer_log_line('report_view', 'not_found', fixarivan_viewer_token_fingerprint($token), 'empty_report');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

fixarivan_viewer_rate_success('report_view');

$reportData = is_array($report['data'] ?? null) ? $report['data'] : [];

// Determine viewer language from saved report
if (isset($reportData['language'])) {
    $reportLang = strtolower(trim((string)$reportData['language']));
    if (in_array($reportLang, ['ru', 'en', 'fi'], true)) {
        $viewerLang = $reportLang;
    }
}

$T = [
    'ru' => [
        'copyLink' => 'Скопировать ссылку',
        'printPage' => 'Печать (или «Сохранить как PDF» в диалоге)',
        'copySuccess' => 'Ссылка скопирована',
        'copyFailed' => 'Не удалось скопировать',
            'backToDashboard' => 'Главное меню',
        'pageTitle' => 'Отчёт диагностики (read-only)',
        'pageSubtitle' => 'FixariVan - просмотр сохранённого отчёта',
        'reportId' => 'Report ID',
        'token' => 'Token',
        'created' => 'Создан',
        'documentId' => 'Document ID',
        'dataSection' => 'Данные формы',
        'clientTitle' => '👤 Клиент',
        'clientName' => 'ФИО',
        'clientPhone' => 'Телефон',
        'clientEmail' => 'Email',
        'deviceTitle' => '📱 Устройство',
        'deviceType' => 'Тип',
        'deviceModel' => 'Модель',
        'deviceSerial' => 'Серийный номер',
        'overallTitle' => '⭐ Общая оценка устройства',
        'externalTitle' => '🎨 Оценка внешнего состояния',
        'componentTestsTitle' => '🔍 Тесты компонентов',
        'temperaturesTitle' => '🌡️ Температуры',
        'cpuTemp' => 'CPU (°C)',
        'gpuTemp' => 'GPU (°C)',
        'diskTemp' => 'Диск (°C)',
        'ambientTemp' => 'Окружение (°C)',
        'batteryTitle' => '🔋 Аккумулятор',
        'batteryCapacity' => 'Ёмкость (%)',
        'batteryPassportCapacity' => 'Паспортная ёмкость (mAh)',
        'batteryCurrentCapacity' => 'Текущая ёмкость (mAh)',
        'batteryWearLevel' => 'Износ (%)',
        'batteryStatus' => 'Состояние',
        'batteryReplacement' => 'Замена',
        'batteryNotes' => 'Примечания',
        'cleaningTitle' => '🧽 Услуги очистки',
        'softwareServicesTitle' => '💿 Программные услуги',
        'cleaningEmpty' => '—',
        'diagnosisTitle' => '📋 Диагноз и рекомендации',
        'diagnosisLabel' => 'Диагноз',
        'recommendationsLabel' => 'Рекомендации',
        'masterTitle' => '👨‍🔧 Мастер',
        'masterName' => 'ФИО',
        'workDate' => 'Дата',
        'additionalData' => 'Дополнительные данные',
        'replacementRequired' => 'Требуется замена аккумулятора',
        'replacementNotRequired' => 'Не требуется замена',
    ],
    'en' => [
        'copyLink' => 'Copy link',
        'printPage' => 'Print (or Save as PDF in the dialog)',
        'copySuccess' => 'Link copied',
        'copyFailed' => 'Failed to copy',
            'backToDashboard' => 'Back to dashboard',
        'pageTitle' => 'Diagnostics report (read-only)',
        'pageSubtitle' => 'FixariVan - view saved report',
        'reportId' => 'Report ID',
        'token' => 'Token',
        'created' => 'Created',
        'documentId' => 'Document ID',
        'dataSection' => 'Form data',
        'clientTitle' => '👤 Client',
        'clientName' => 'Client name',
        'clientPhone' => 'Phone',
        'clientEmail' => 'Email',
        'deviceTitle' => '📱 Device',
        'deviceType' => 'Type',
        'deviceModel' => 'Model',
        'deviceSerial' => 'Serial number',
        'overallTitle' => '⭐ Overall device rating',
        'externalTitle' => '🎨 External condition rating',
        'componentTestsTitle' => '🔍 Component tests',
        'temperaturesTitle' => '🌡️ Temperatures',
        'cpuTemp' => 'CPU (°C)',
        'gpuTemp' => 'GPU (°C)',
        'diskTemp' => 'Disk (°C)',
        'ambientTemp' => 'Ambient (°C)',
        'batteryTitle' => '🔋 Battery',
        'batteryCapacity' => 'Capacity (%)',
        'batteryPassportCapacity' => 'Passport capacity (mAh)',
        'batteryCurrentCapacity' => 'Current capacity (mAh)',
        'batteryWearLevel' => 'Wear level (%)',
        'batteryStatus' => 'Condition',
        'batteryReplacement' => 'Replacement',
        'batteryNotes' => 'Notes',
        'cleaningTitle' => '🧽 Cleaning services',
        'softwareServicesTitle' => '💿 Software services',
        'cleaningEmpty' => '—',
        'diagnosisTitle' => '📋 Diagnosis and recommendations',
        'diagnosisLabel' => 'Diagnosis',
        'recommendationsLabel' => 'Recommendations',
        'masterTitle' => '👨‍🔧 Technician',
        'masterName' => 'Technician name',
        'workDate' => 'Date',
        'additionalData' => 'Additional data',
        'replacementRequired' => 'Battery replacement required',
        'replacementNotRequired' => 'No replacement required',
    ],
    'fi' => [
        'copyLink' => 'Kopioi linkki',
        'printPage' => 'Tulosta (tai Tallenna PDF:nä)',
        'copySuccess' => 'Linkki kopioitu',
        'copyFailed' => 'Kopiointi epäonnistui',
            'backToDashboard' => 'Takaisin hallintapaneeliin',
        'pageTitle' => 'Diagnostiikkaraportti (vain luku)',
        'pageSubtitle' => 'FixariVan - tallennetun raportin katselu',
        'reportId' => 'Report ID',
        'token' => 'Token',
        'created' => 'Luotu',
        'documentId' => 'Document ID',
        'dataSection' => 'Lomakkeen tiedot',
        'clientTitle' => '👤 Asiakas',
        'clientName' => 'Asiakkaan nimi',
        'clientPhone' => 'Puhelin',
        'clientEmail' => 'Sähköposti',
        'deviceTitle' => '📱 Laite',
        'deviceType' => 'Tyyppi',
        'deviceModel' => 'Malli',
        'deviceSerial' => 'Sarjanumero',
        'overallTitle' => '⭐ Laitteen yleisarvio',
        'externalTitle' => '🎨 Ulkoisen kunnon arvio',
        'componentTestsTitle' => '🔍 Komponenttitestit',
        'temperaturesTitle' => '🌡️ Lämpötilat',
        'cpuTemp' => 'CPU (°C)',
        'gpuTemp' => 'GPU (°C)',
        'diskTemp' => 'Levy (°C)',
        'ambientTemp' => 'Ympäristö (°C)',
        'batteryTitle' => '🔋 Akku',
        'batteryCapacity' => 'Akun kapasiteetti (%)',
        'batteryPassportCapacity' => 'Akun passiivinen kapasiteetti (mAh)',
        'batteryCurrentCapacity' => 'Nykyinen kapasiteetti (mAh)',
        'batteryWearLevel' => 'Kulutustaso (%)',
        'batteryStatus' => 'Kunto',
        'batteryReplacement' => 'Vaihde',
        'batteryNotes' => 'Huomautukset',
        'cleaningTitle' => '🧽 Puhdistuspalvelut',
        'softwareServicesTitle' => '💿 Ohjelmistopalvelut',
        'cleaningEmpty' => '—',
        'diagnosisTitle' => '📋 Diagnoosi ja suositukset',
        'diagnosisLabel' => 'Diagnoosi',
        'recommendationsLabel' => 'Suositukset',
        'masterTitle' => '👨‍🔧 Teknikko',
        'masterName' => 'Teknikon nimi',
        'workDate' => 'Päivä',
        'additionalData' => 'Lisätiedot',
        'replacementRequired' => 'Akun vaihto vaaditaan',
        'replacementNotRequired' => 'Ei vaadi vaihtoa',
    ],
];

// Translate field keys for "Additional data" (raw_json.data.* keys)
$additionalFieldLabels = [
    // Mobile/PC common
    'problemDescription' => ['ru' => 'Описание проблемы', 'en' => 'Problem description', 'fi' => 'Ongelman kuvaus'],
    'technician' => ['ru' => 'Мастер', 'en' => 'Technician', 'fi' => 'Teknikko'],
    'uniqueCode' => ['ru' => 'Код подтверждения', 'en' => 'Confirmation code', 'fi' => 'Vahvistuskoodi'],
    'placeOfAcceptance' => ['ru' => 'Место приёма', 'en' => 'Acceptance location', 'fi' => 'Vastaanottopaikka'],
    'dateOfAcceptance' => ['ru' => 'Дата приёма', 'en' => 'Acceptance date', 'fi' => 'Vastaanottopäivä'],
    'reportType' => ['ru' => 'Тип отчёта', 'en' => 'Report type', 'fi' => 'Raportin tyyppi'],
    'language' => ['ru' => 'Язык', 'en' => 'Language', 'fi' => 'Kieli'],

    // PC-specific (diagnostic_pc.html payload)
    'operatingSystem' => ['ru' => 'ОС', 'en' => 'Operating system', 'fi' => 'Käyttöjärjestelmä'],
    'cpuTemp' => ['ru' => 'Температура CPU (°C)', 'en' => 'CPU temperature (°C)', 'fi' => 'CPU-lämpötila (°C)'],
    'gpuTemp' => ['ru' => 'Температура GPU (°C)', 'en' => 'GPU temperature (°C)', 'fi' => 'GPU-lämpötila (°C)'],
    'diskTemp' => ['ru' => 'Температура диска (°C)', 'en' => 'Disk temperature (°C)', 'fi' => 'Levyn lämpötila (°C)'],
    'ambientTemp' => ['ru' => 'Температура окружения (°C)', 'en' => 'Ambient temperature (°C)', 'fi' => 'Ympäristön lämpötila (°C)'],

    'currentCapacity' => ['ru' => 'Текущая ёмкость (mAh)', 'en' => 'Current capacity (mAh)', 'fi' => 'Nykyinen kapasiteetti (mAh)'],
    'wearLevel' => ['ru' => 'Износ (%)', 'en' => 'Wear level (%)', 'fi' => 'Kulutustaso (%)'],

    'services' => ['ru' => 'Программные услуги', 'en' => 'Software services', 'fi' => 'Ohjelmistopalvelut'],
    'estimatedCost' => ['ru' => 'Оценка стоимости', 'en' => 'Estimated cost', 'fi' => 'Arvioitu hinta'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo h((string)$viewerLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>FixariVan — <?php echo h((string)$T[$viewerLang]['pageTitle']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            min-height: 100dvh;
            color: #333;
            overflow-x: hidden;
        }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; color: white; }
        .header h1 { font-size: 2rem; margin-bottom: 10px; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .header p { font-size: 1rem; opacity: 0.9; }
        .card {
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            backdrop-filter: blur(10px);
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 14px;
        }
        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
            font-size: 0.95rem;
        }
        .meta div {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 10px;
        }
        .section-title {
            font-size: 1.2rem;
            color: #667eea;
            font-weight: 700;
            margin: 10px 0 12px;
        }
        .group-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #2f3a66;
            margin: 18px 0 10px;
        }
        .stars-row {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .star-view {
            font-size: 18px;
            opacity: 0.25;
            filter: grayscale(100%);
        }
        .star-view.active {
            opacity: 1;
            filter: grayscale(0%);
        }
        .rating-subtitle {
            margin-top: 6px;
            font-weight: 700;
            color: #667eea;
        }
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .chip-view {
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.9rem;
            border: 1px solid transparent;
            background: #e9ecef;
            color: #333;
        }
        .chip-good {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .chip-bad {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .chip-neutral {
            background: #e9ecef;
            border-color: #dee2e6;
            color: #333;
        }
        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .action-btn {
            border: 1px solid #667eea;
            background: #667eea;
            color: #fff;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .action-btn:hover {
            background: #5a6fd8;
            border-color: #5a6fd8;
        }
        .copy-status {
            font-size: 0.9rem;
            color: #155724;
            display: none;
            align-self: center;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .row {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: white;
            padding: 10px 12px;
        }
        .label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 4px;
        }
        .value {
            font-size: 1rem;
            white-space: pre-wrap;
            word-break: break-word;
        }
        @media (max-width: 768px) {
            .container {
                padding: 10px;
                padding-bottom: max(14px, env(safe-area-inset-bottom, 0px));
                padding-left: max(10px, env(safe-area-inset-left, 0px));
                padding-right: max(10px, env(safe-area-inset-right, 0px));
            }
            .card { padding: 16px; }
            .meta { grid-template-columns: 1fr; }
            .meta div { word-break: break-word; overflow-wrap: anywhere; }
            .action-btn {
                min-height: 44px;
                padding: 10px 14px;
                font-size: 16px;
            }
            .header h1 { font-size: 1.45rem; word-break: break-word; }
        }
        @media print {
            .header button, .actions { display: none !important; }
            body { background: #fff; }
            .container { max-width: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 <?php echo h((string)$T[$viewerLang]['pageTitle']); ?></h1>
            <p><?php echo h((string)$T[$viewerLang]['pageSubtitle']); ?></p>
            <div style="margin-top: 12px; display: flex; justify-content: center;">
                <button type="button" class="action-btn" onclick="window.location.href='./index.php'">
                    <?php echo h((string)$T[$viewerLang]['backToDashboard']); ?>
                </button>
            </div>
        </div>

        <div class="card">
                <div class="actions">
                    <button type="button" class="action-btn" id="copyLinkBtn"><?php echo h((string)$T[$viewerLang]['copyLink']); ?></button>
                    <span class="copy-status" id="copyStatus"><?php echo h((string)$T[$viewerLang]['copySuccess']); ?></span>
                </div>
                <div class="meta">
                    <div><strong><?php echo h((string)$T[$viewerLang]['reportId']); ?>:</strong> <?php echo h((string) ($report['report_id'] ?? '—')); ?></div>
                    <div><strong><?php echo h((string)$T[$viewerLang]['token']); ?>:</strong> <?php echo h((string) ($report['token'] ?? '—')); ?></div>
                    <div><strong><?php echo h((string)$T[$viewerLang]['created']); ?>:</strong> <?php echo h((string) ($report['created_at'] ?? '—')); ?></div>
                    <div><strong><?php echo h((string)$T[$viewerLang]['documentId']); ?>:</strong> <?php echo h((string) ($reportData['documentId'] ?? '—')); ?></div>
                </div>

                <div class="section-title"><?php echo h((string)$T[$viewerLang]['dataSection']); ?></div>
                <?php
                    $d = is_array($reportData) ? $reportData : [];
                    $reportType = strtolower(trim((string)($d['reportType'] ?? 'mobile')));
                    $deviceRating = (int)($d['deviceRating'] ?? 0);
                    $conditionRating = (int)($d['conditionRating'] ?? 0);
                    $batteryReplacement = (bool)($d['batteryReplacement'] ?? false);
                    $cleaning = is_array($d['cleaning'] ?? null) ? $d['cleaning'] : [];
                    $softwareServices = is_array($d['services'] ?? null) ? $d['services'] : [];
                    $componentTests = $d['componentTests'] ?? '';

                    // Часть полей специально рендерим красиво для клиента
                    $renderedKeys = [
                        'clientName','clientPhone','clientEmail',
                        'deviceType','deviceModel','deviceSerial',
                        'deviceRating','conditionRating',
                        'componentTests',
                        'cpuTemp','gpuTemp','diskTemp','ambientTemp',
                        'batteryCapacity','batteryStatus','batteryReplacement','batteryNotes',
                        'cleaning',
                        'services',
                        'diagnosis','recommendations',
                        'technicianName','workDate'
                    ];
                    if ($reportType !== 'pc') {
                        // Mobile doesn't have `services[]` — don't suppress it from additional data.
                        $renderedKeys = array_values(array_diff($renderedKeys, ['services']));
                    } else {
                        // PC battery fields should be shown in the battery block, not under additional data.
                        $renderedKeys = array_values(array_unique(array_merge($renderedKeys, ['currentCapacity', 'wearLevel'])));
                    }
                    $rest = array_diff_key($d, array_flip($renderedKeys));
                ?>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['clientTitle']); ?></div>
                <div class="grid">
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['clientName']); ?></div>
                        <div class="value"><?php echo h((string)($d['clientName'] ?? '—')); ?></div>
                    </div>
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['clientPhone']); ?></div>
                        <div class="value"><?php echo h((string)($d['clientPhone'] ?? '—')); ?></div>
                    </div>
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['clientEmail']); ?></div>
                        <div class="value"><?php echo h((string)($d['clientEmail'] ?? '—')); ?></div>
                    </div>
                </div>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['deviceTitle']); ?></div>
                <div class="grid">
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['deviceType']); ?></div>
                        <div class="value"><?php echo h((string)($d['deviceType'] ?? '—')); ?></div>
                    </div>
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['deviceModel']); ?></div>
                        <div class="value"><?php echo h((string)($d['deviceModel'] ?? '—')); ?></div>
                    </div>
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['deviceSerial']); ?></div>
                        <div class="value"><?php echo h((string)($d['deviceSerial'] ?? '—')); ?></div>
                    </div>
                </div>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['overallTitle']); ?></div>
                <div class="grid">
                    <div class="row">
                        <div class="value"><?php echo renderStars($deviceRating, 10); ?></div>
                    </div>
                </div>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['externalTitle']); ?></div>
                <div class="grid">
                    <div class="row">
                        <div class="value"><?php echo renderStars($conditionRating, 10); ?></div>
                    </div>
                </div>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['componentTestsTitle']); ?></div>
                <div class="grid">
                    <div class="row">
                        <div class="value"><?php echo renderComponentTests($componentTests); ?></div>
                    </div>
                </div>

                <?php if ($reportType === 'pc'): ?>
                    <div class="group-title"><?php echo h((string)$T[$viewerLang]['temperaturesTitle']); ?></div>
                    <div class="grid">
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['cpuTemp']); ?></div>
                            <div class="value"><?php echo h((string)($d['cpuTemp'] ?? '—')); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['gpuTemp']); ?></div>
                            <div class="value"><?php echo h((string)($d['gpuTemp'] ?? '—')); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['diskTemp']); ?></div>
                            <div class="value"><?php echo h((string)($d['diskTemp'] ?? '—')); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['ambientTemp']); ?></div>
                            <div class="value"><?php echo h((string)($d['ambientTemp'] ?? '—')); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['batteryTitle']); ?></div>
                <div class="grid">
                    <?php if ($reportType === 'pc'): ?>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['batteryPassportCapacity']); ?></div>
                            <div class="value"><?php echo h((string)($d['batteryCapacity'] ?? '—')); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['batteryCurrentCapacity']); ?></div>
                            <div class="value"><?php echo h((string)($d['currentCapacity'] ?? '—')); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['batteryWearLevel']); ?></div>
                            <div class="value"><?php echo h((string)($d['wearLevel'] ?? '—')); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['batteryCapacity']); ?></div>
                            <div class="value"><?php echo h((string)($d['batteryCapacity'] ?? '—')); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['batteryStatus']); ?></div>
                            <div class="value"><?php echo h((string)batteryStatusLabel($d['batteryStatus'] ?? '')); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['batteryReplacement']); ?></div>
                            <div class="value"><?php echo h($batteryReplacement ? (string)$T[$viewerLang]['replacementRequired'] : (string)$T[$viewerLang]['replacementNotRequired']); ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?php echo h((string)$T[$viewerLang]['batteryNotes']); ?></div>
                            <div class="value"><?php echo h((string)($d['batteryNotes'] ?? '—')); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="group-title">
                    <?php echo h((string)($reportType === 'pc' ? $T[$viewerLang]['softwareServicesTitle'] : $T[$viewerLang]['cleaningTitle'])); ?>
                </div>
                <div class="grid">
                    <div class="row">
                        <?php if ($reportType === 'pc'): ?>
                            <div class="value">
                                <?php if ($softwareServices === []): ?>
                                    <?php echo h((string)$T[$viewerLang]['cleaningEmpty']); ?>
                                <?php else: ?>
                                    <div class="chips-row">
                                        <?php foreach ($softwareServices as $item): ?>
                                            <span class="chip-view chip-neutral"><?php echo h(softwareServiceLabel((string)$item)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="value">
                                <?php if ($cleaning === []): ?>
                                    <?php echo h((string)$T[$viewerLang]['cleaningEmpty']); ?>
                                <?php else: ?>
                                    <div class="chips-row">
                                        <?php foreach ($cleaning as $item): ?>
                                            <span class="chip-view chip-neutral"><?php echo h(cleaningLabel((string)$item)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['diagnosisTitle']); ?></div>
                <div class="grid">
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['diagnosisLabel']); ?></div>
                        <div class="value"><?php echo h((string)($d['diagnosis'] ?? '—')); ?></div>
                    </div>
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['recommendationsLabel']); ?></div>
                        <div class="value"><?php echo h((string)($d['recommendations'] ?? '—')); ?></div>
                    </div>
                </div>

                <div class="group-title"><?php echo h((string)$T[$viewerLang]['masterTitle']); ?></div>
                <div class="grid">
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['masterName']); ?></div>
                        <div class="value"><?php echo h((string)($d['technicianName'] ?? '—')); ?></div>
                    </div>
                    <div class="row">
                        <div class="label"><?php echo h((string)$T[$viewerLang]['workDate']); ?></div>
                        <div class="value"><?php echo h((string)($d['workDate'] ?? '—')); ?></div>
                    </div>
                </div>

                <?php if (!empty($rest)): ?>
                    <details style="margin-top:16px;">
                        <summary class="section-title" style="cursor:pointer;"><?php echo h((string)$T[$viewerLang]['additionalData']); ?></summary>
                        <div class="grid">
                            <?php foreach ($rest as $key => $value): ?>
                                <div class="row">
                                    <div class="label">
                                        <?php
                                            $translatedKey = $additionalFieldLabels[$key][$viewerLang] ?? null;
                                            echo h((string)($translatedKey ?? humanizeKey((string)$key)));
                                        ?>
                                    </div>
                                    <div class="value"><?php echo h(formatValue($value)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
        </div>
    </div>
    <script>
        (function() {
            const copyBtn = document.getElementById('copyLinkBtn');
            const status = document.getElementById('copyStatus');
            if (!copyBtn || !status) return;

            copyBtn.addEventListener('click', async function() {
                try {
                    await navigator.clipboard.writeText(window.location.href);
                    status.textContent = <?php echo json_encode((string)$T[$viewerLang]['copySuccess'], JSON_UNESCAPED_UNICODE); ?>;
                    status.style.display = 'inline';
                } catch (e) {
                    status.textContent = <?php echo json_encode((string)$T[$viewerLang]['copyFailed'], JSON_UNESCAPED_UNICODE); ?>;
                    status.style.color = '#721c24';
                    status.style.display = 'inline';
                }
            });
        })();
    </script>
</body>
</html>
