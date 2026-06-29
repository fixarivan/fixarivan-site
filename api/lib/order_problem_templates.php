<?php
declare(strict_types=1);

/**
 * Настраиваемые шаблоны «Описание / неисправность» для order_new.html.
 * Хранение: storage/order_problem_templates.json (схема БД не меняется).
 */

function fixarivan_order_problem_templates_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'order_problem_templates.json';
}

/**
 * @return list<array{id:string,emoji:string,label:string,text:string,sort:int,enabled:bool}>
 */
function fixarivan_order_problem_templates_defaults(): array
{
    return [
        ['id' => 'screen', 'emoji' => '📱', 'label' => 'Экран', 'text' => 'Неисправность экрана (трещина, полосы, нет изображения)', 'sort' => 10, 'enabled' => true],
        ['id' => 'battery', 'emoji' => '🔋', 'label' => 'АКБ', 'text' => 'Проблема с аккумулятором (быстро разряжается, не держит заряд, вздутие)', 'sort' => 20, 'enabled' => true],
        ['id' => 'charge', 'emoji' => '🔌', 'label' => 'Зарядка', 'text' => 'Не заряжается / проблема с разъёмом зарядки', 'sort' => 30, 'enabled' => true],
        ['id' => 'water', 'emoji' => '💧', 'label' => 'После воды', 'text' => 'Попадание влаги / после воды', 'sort' => 40, 'enabled' => true],
        ['id' => 'power', 'emoji' => '🔥', 'label' => 'Не включается', 'text' => 'Не включается / не реагирует на кнопку питания', 'sort' => 50, 'enabled' => true],
        ['id' => 'camera', 'emoji' => '📷', 'label' => 'Камера', 'text' => 'Не работает камера (основная / фронтальная)', 'sort' => 60, 'enabled' => true],
        ['id' => 'speaker', 'emoji' => '🔊', 'label' => 'Динамик', 'text' => 'Не работает динамик / хрип / тихий звук', 'sort' => 70, 'enabled' => true],
        ['id' => 'mic', 'emoji' => '🎤', 'label' => 'Микрофон', 'text' => 'Не работает микрофон / собеседник не слышит', 'sort' => 80, 'enabled' => true],
        ['id' => 'network', 'emoji' => '📶', 'label' => 'Связь', 'text' => 'Проблемы со связью / нет сети / слабый сигнал', 'sort' => 90, 'enabled' => true],
        ['id' => 'wifi', 'emoji' => '🌐', 'label' => 'Wi-Fi', 'text' => 'Не работает Wi-Fi / не подключается к сети', 'sort' => 100, 'enabled' => true],
        ['id' => 'ssd', 'emoji' => '💾', 'label' => 'SSD', 'text' => 'Замена / апгрейд SSD, медленный диск', 'sort' => 110, 'enabled' => true],
        ['id' => 'windows', 'emoji' => '🪟', 'label' => 'Windows', 'text' => 'Проблемы Windows / переустановка / не загружается', 'sort' => 120, 'enabled' => true],
        ['id' => 'printer', 'emoji' => '🖨', 'label' => 'Принтер', 'text' => 'Не печатает / замятие / проблема с принтером', 'sort' => 130, 'enabled' => true],
        ['id' => 'sale', 'emoji' => '🛒', 'label' => 'Продажа', 'text' => 'Продажа товара / аксессуара', 'sort' => 140, 'enabled' => true],
    ];
}

/**
 * @param mixed $row
 * @return array{id:string,emoji:string,label:string,text:string,sort:int,enabled:bool}|null
 */
function fixarivan_order_problem_template_normalize($row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $label = trim((string)($row['label'] ?? ''));
    $text = trim((string)($row['text'] ?? ''));
    if ($label === '' && $text === '') {
        return null;
    }
    $id = trim((string)($row['id'] ?? ''));
    if ($id === '') {
        $id = 'tpl_' . substr(md5($label . '|' . $text), 0, 8);
    }
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?: ('tpl_' . uniqid());

    return [
        'id' => $id,
        'emoji' => trim((string)($row['emoji'] ?? '')),
        'label' => $label !== '' ? $label : (function_exists('mb_substr') ? mb_substr($text, 0, 24) : substr($text, 0, 24)),
        'text' => $text !== '' ? $text : $label,
        'sort' => (int)($row['sort'] ?? 0),
        'enabled' => !isset($row['enabled']) || (bool)$row['enabled'],
    ];
}

/**
 * @return list<array{id:string,emoji:string,label:string,text:string,sort:int,enabled:bool}>
 */
function fixarivan_order_problem_templates_load(): array
{
    $path = fixarivan_order_problem_templates_path();
    if (!is_readable($path)) {
        return fixarivan_order_problem_templates_defaults();
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return fixarivan_order_problem_templates_defaults();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return fixarivan_order_problem_templates_defaults();
    }
    $rows = isset($decoded['templates']) && is_array($decoded['templates'])
        ? $decoded['templates']
        : $decoded;
    $out = [];
    foreach ($rows as $row) {
        $norm = fixarivan_order_problem_template_normalize($row);
        if ($norm !== null) {
            $out[] = $norm;
        }
    }
    if ($out === []) {
        return fixarivan_order_problem_templates_defaults();
    }
    usort($out, static function (array $a, array $b): int {
        return ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'], $b['label']);
    });

    return $out;
}

/**
 * @param list<mixed> $templates
 * @return list<array{id:string,emoji:string,label:string,text:string,sort:int,enabled:bool}>
 */
function fixarivan_order_problem_templates_save(array $templates): array
{
    $out = [];
    $sort = 10;
    foreach ($templates as $row) {
        $norm = fixarivan_order_problem_template_normalize($row);
        if ($norm === null) {
            continue;
        }
        if ($norm['sort'] <= 0) {
            $norm['sort'] = $sort;
        }
        $sort += 10;
        $out[] = $norm;
    }
    if ($out === []) {
        $out = fixarivan_order_problem_templates_defaults();
    }
    usort($out, static function (array $a, array $b): int {
        return ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'], $b['label']);
    });

    $dir = dirname(fixarivan_order_problem_templates_path());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Нет каталога storage/');
    }

    $payload = json_encode(['templates' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($payload === false) {
        throw new RuntimeException('Ошибка сериализации шаблонов');
    }
    $path = fixarivan_order_problem_templates_path();
    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить шаблоны');
    }
    @chmod($path, 0640);

    return $out;
}

/**
 * @return list<array{id:string,emoji:string,label:string,text:string,sort:int}>
 */
function fixarivan_order_problem_templates_for_ui(): array
{
    $rows = fixarivan_order_problem_templates_load();
    $out = [];
    foreach ($rows as $row) {
        if (empty($row['enabled'])) {
            continue;
        }
        $out[] = [
            'id' => $row['id'],
            'emoji' => $row['emoji'],
            'label' => $row['label'],
            'text' => $row['text'],
            'sort' => $row['sort'],
        ];
    }

    return $out;
}
