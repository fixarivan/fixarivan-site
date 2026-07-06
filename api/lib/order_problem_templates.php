<?php
declare(strict_types=1);

require_once __DIR__ . '/client_order_i18n.php';

/**
 * Настраиваемые шаблоны «Описание / неисправность» для order_new.html.
 * Хранение: storage/order_problem_templates.json (схема БД не меняется).
 * Каждый шаблон: label (RU для мастера) + i18n.text (ru/fi/en) + опциональные variants.
 */

function fixarivan_order_problem_templates_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'order_problem_templates.json';
}

/**
 * @param array<string, string> $texts
 * @return array{ru:array{text:string},fi:array{text:string},en:array{text:string}}
 */
function fixarivan_order_problem_i18n_from_texts(array $texts): array
{
    return [
        'ru' => ['text' => trim((string) ($texts['ru'] ?? ''))],
        'fi' => ['text' => trim((string) ($texts['fi'] ?? ''))],
        'en' => ['text' => trim((string) ($texts['en'] ?? ''))],
    ];
}

/**
 * @param array<string, string> $texts
 * @return array{id:string,label:string,i18n:array<string,array{text:string}>}
 */
function fixarivan_order_problem_variant(string $id, string $labelRu, array $texts): array
{
    return [
        'id' => $id,
        'label' => $labelRu,
        'i18n' => fixarivan_order_problem_i18n_from_texts($texts),
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function fixarivan_order_problem_templates_defaults(): array
{
    return [
        [
            'id' => 'screen', 'emoji' => '📱', 'label' => 'Экран', 'sort' => 10, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Неисправность экрана',
                'fi' => 'Näyttövika',
                'en' => 'Screen issue',
            ]),
            'variants' => [
                fixarivan_order_problem_variant('screen_crack', 'Трещина', [
                    'ru' => 'Трещина на экране',
                    'fi' => 'Haljennut näyttö',
                    'en' => 'Cracked screen',
                ]),
                fixarivan_order_problem_variant('screen_lines', 'Полосы', [
                    'ru' => 'Полосы на экране',
                    'fi' => 'Raidat näytössä',
                    'en' => 'Display lines',
                ]),
                fixarivan_order_problem_variant('screen_black', 'Нет изображения', [
                    'ru' => 'Нет изображения на экране',
                    'fi' => 'Ei kuvaa näytöllä',
                    'en' => 'No display image',
                ]),
                fixarivan_order_problem_variant('screen_touch', 'Не работает тач', [
                    'ru' => 'Не работает сенсор / тачскрин',
                    'fi' => 'Kosketusnäyttö ei toimi',
                    'en' => 'Touchscreen not working',
                ]),
            ],
        ],
        [
            'id' => 'battery', 'emoji' => '🔋', 'label' => 'АКБ', 'sort' => 20, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Проблема с аккумулятором',
                'fi' => 'Akkuongelma',
                'en' => 'Battery issue',
            ]),
            'variants' => [
                fixarivan_order_problem_variant('battery_drain', 'Не держит заряд', [
                    'ru' => 'Аккумулятор не держит заряд',
                    'fi' => 'Akku purkautuu nopeasti',
                    'en' => 'Battery drains quickly',
                ]),
                fixarivan_order_problem_variant('battery_fast', 'Быстро разряжается', [
                    'ru' => 'Быстро разряжается',
                    'fi' => 'Akku tyhjenee nopeasti',
                    'en' => 'Battery discharges quickly',
                ]),
                fixarivan_order_problem_variant('battery_swollen', 'Вздутие', [
                    'ru' => 'Вздутие аккумулятора',
                    'fi' => 'Akku turvonnut',
                    'en' => 'Swollen battery',
                ]),
            ],
        ],
        [
            'id' => 'charge', 'emoji' => '🔌', 'label' => 'Зарядка', 'sort' => 30, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Проблема с зарядкой',
                'fi' => 'Latausongelma',
                'en' => 'Charging issue',
            ]),
            'variants' => [
                fixarivan_order_problem_variant('charge_no', 'Не заряжается', [
                    'ru' => 'Не заряжается',
                    'fi' => 'Ei lataudu',
                    'en' => 'Does not charge',
                ]),
                fixarivan_order_problem_variant('charge_port', 'Разъём', [
                    'ru' => 'Проблема с разъёмом зарядки',
                    'fi' => 'Latausportin vika',
                    'en' => 'Charging port issue',
                ]),
            ],
        ],
        [
            'id' => 'water', 'emoji' => '💧', 'label' => 'После воды', 'sort' => 40, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Попадание влаги / после воды',
                'fi' => 'Vesivaurio / kosteus',
                'en' => 'Water damage / moisture',
            ]),
        ],
        [
            'id' => 'power', 'emoji' => '🔥', 'label' => 'Не включается', 'sort' => 50, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не включается / не реагирует на кнопку питания',
                'fi' => 'Ei käynnisty / virtapainike ei reagoi',
                'en' => 'Does not power on / power button unresponsive',
            ]),
        ],
        [
            'id' => 'camera', 'emoji' => '📷', 'label' => 'Камера', 'sort' => 60, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не работает камера',
                'fi' => 'Kamera ei toimi',
                'en' => 'Camera not working',
            ]),
            'variants' => [
                fixarivan_order_problem_variant('camera_main', 'Основная', [
                    'ru' => 'Не работает основная камера',
                    'fi' => 'Takakamera ei toimi',
                    'en' => 'Rear camera not working',
                ]),
                fixarivan_order_problem_variant('camera_front', 'Фронтальная', [
                    'ru' => 'Не работает фронтальная камера',
                    'fi' => 'Etukamera ei toimi',
                    'en' => 'Front camera not working',
                ]),
            ],
        ],
        [
            'id' => 'speaker', 'emoji' => '🔊', 'label' => 'Динамик', 'sort' => 70, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не работает динамик / хрип / тихий звук',
                'fi' => 'Kaiutin ei toimi / särö / hiljainen ääni',
                'en' => 'Speaker not working / crackling / low volume',
            ]),
        ],
        [
            'id' => 'mic', 'emoji' => '🎤', 'label' => 'Микрофон', 'sort' => 80, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не работает микрофон / собеседник не слышит',
                'fi' => 'Mikrofoni ei toimi / vastapuoli ei kuule',
                'en' => 'Microphone not working / caller cannot hear',
            ]),
        ],
        [
            'id' => 'faceid', 'emoji' => '🙂', 'label' => 'Face ID', 'sort' => 85, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не работает Face ID / распознавание лица',
                'fi' => 'Face ID / kasvojentunnistus ei toimi',
                'en' => 'Face ID / facial recognition not working',
            ]),
        ],
        [
            'id' => 'buttons', 'emoji' => '🔘', 'label' => 'Кнопки', 'sort' => 87, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не работают кнопки',
                'fi' => 'Painikkeet eivät toimi',
                'en' => 'Buttons not working',
            ]),
        ],
        [
            'id' => 'body', 'emoji' => '📦', 'label' => 'Корпус', 'sort' => 88, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Повреждение корпуса',
                'fi' => 'Kotelovaurio',
                'en' => 'Housing / body damage',
            ]),
        ],
        [
            'id' => 'network', 'emoji' => '📶', 'label' => 'Связь', 'sort' => 90, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Проблемы со связью / нет сети / слабый сигнал',
                'fi' => 'Verkko-ongelma / ei verkkoa / heikko signaali',
                'en' => 'Network issues / no signal / weak signal',
            ]),
        ],
        [
            'id' => 'wifi', 'emoji' => '🌐', 'label' => 'Wi-Fi', 'sort' => 100, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не работает Wi-Fi / не подключается к сети',
                'fi' => 'Wi-Fi ei toimi / ei yhdistä verkkoon',
                'en' => 'Wi-Fi not working / cannot connect',
            ]),
        ],
        [
            'id' => 'ssd', 'emoji' => '💾', 'label' => 'SSD', 'sort' => 110, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Замена / апгрейд SSD, медленный диск',
                'fi' => 'SSD-vaihto / päivitys, hidas levy',
                'en' => 'SSD replacement / upgrade, slow drive',
            ]),
        ],
        [
            'id' => 'windows', 'emoji' => '🪟', 'label' => 'Windows', 'sort' => 120, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Проблемы Windows / переустановка / не загружается',
                'fi' => 'Windows-ongelma / uudelleenasennus / ei käynnisty',
                'en' => 'Windows issues / reinstall / will not boot',
            ]),
        ],
        [
            'id' => 'printer', 'emoji' => '🖨', 'label' => 'Принтер', 'sort' => 130, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Не печатает / замятие / проблема с принтером',
                'fi' => 'Ei tulosta / paperitukos / tulostinongelma',
                'en' => 'Does not print / paper jam / printer issue',
            ]),
        ],
        [
            'id' => 'sale', 'emoji' => '🛒', 'label' => 'Продажа', 'sort' => 140, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Продажа товара / аксессуара',
                'fi' => 'Tuotteen / lisävarusteen myynti',
                'en' => 'Product / accessory sale',
            ]),
        ],
        [
            'id' => 'other', 'emoji' => '❓', 'label' => 'Другое', 'sort' => 150, 'enabled' => true,
            'i18n' => fixarivan_order_problem_i18n_from_texts([
                'ru' => 'Другая неисправность',
                'fi' => 'Muu vika',
                'en' => 'Other issue',
            ]),
        ],
    ];
}

/**
 * @param array<string,mixed> $i18n
 * @param string $lang
 * @param string $fallback
 */
function fixarivan_order_problem_pick_i18n_text(array $i18n, string $lang, string $fallback = ''): string
{
    $l = fixarivan_client_order_normalize_lang($lang);
    $text = trim((string) ($i18n[$l]['text'] ?? ''));
    if ($text !== '') {
        return $text;
    }
    $ru = trim((string) ($i18n['ru']['text'] ?? ''));
    if ($ru !== '') {
        return $ru;
    }

    return $fallback;
}

/**
 * @param mixed $row
 * @return array<string,mixed>|null
 */
function fixarivan_order_problem_template_normalize($row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $label = trim((string) ($row['label'] ?? ''));
    $text = trim((string) ($row['text'] ?? ''));
    if ($label === '' && $text === '' && empty($row['i18n'])) {
        return null;
    }

    $i18n = [];
    if (isset($row['i18n']) && is_array($row['i18n'])) {
        foreach (['ru', 'fi', 'en'] as $code) {
            if (!isset($row['i18n'][$code]) || !is_array($row['i18n'][$code])) {
                continue;
            }
            $i18n[$code] = ['text' => trim((string) ($row['i18n'][$code]['text'] ?? ''))];
        }
    }
    if ($text !== '' && !isset($i18n['ru']['text'])) {
        $i18n['ru'] = ['text' => $text];
    } elseif ($label !== '' && !isset($i18n['ru']['text'])) {
        $i18n['ru'] = ['text' => $label];
    }
    foreach (['fi', 'en'] as $code) {
        $flat = trim((string) ($row['text_' . $code] ?? ''));
        if ($flat !== '') {
            $i18n[$code] = ['text' => $flat];
        }
    }

    $variants = [];
    if (isset($row['variants']) && is_array($row['variants'])) {
        foreach ($row['variants'] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $vLabel = trim((string) ($variant['label'] ?? ''));
            $vId = trim((string) ($variant['id'] ?? ''));
            if ($vLabel === '' && $vId === '') {
                continue;
            }
            if ($vId === '') {
                $vId = 'var_' . substr(md5($vLabel), 0, 8);
            }
            $vI18n = [];
            if (isset($variant['i18n']) && is_array($variant['i18n'])) {
                foreach (['ru', 'fi', 'en'] as $code) {
                    if (!isset($variant['i18n'][$code])) {
                        continue;
                    }
                    $chunk = $variant['i18n'][$code];
                    $vI18n[$code] = ['text' => trim((string) (is_array($chunk) ? ($chunk['text'] ?? '') : $chunk))];
                }
            }
            if (!isset($vI18n['ru']['text']) || $vI18n['ru']['text'] === '') {
                $vI18n['ru'] = ['text' => $vLabel !== '' ? $vLabel : $vId];
            }
            $variants[] = [
                'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', $vId) ?: ('var_' . uniqid()),
                'label' => $vLabel !== '' ? $vLabel : $vI18n['ru']['text'],
                'i18n' => $vI18n,
            ];
        }
    }

    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '') {
        $id = 'tpl_' . substr(md5($label . '|' . ($i18n['ru']['text'] ?? '')), 0, 8);
    }
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?: ('tpl_' . uniqid());

    $ruText = fixarivan_order_problem_pick_i18n_text($i18n, 'ru', $text !== '' ? $text : $label);

    return [
        'id' => $id,
        'emoji' => trim((string) ($row['emoji'] ?? '')),
        'label' => $label !== '' ? $label : (function_exists('mb_substr') ? mb_substr($ruText, 0, 24) : substr($ruText, 0, 24)),
        'text' => $ruText,
        'i18n' => $i18n,
        'variants' => $variants,
        'sort' => (int) ($row['sort'] ?? 0),
        'enabled' => !isset($row['enabled']) || (bool) $row['enabled'],
    ];
}

/**
 * @return list<array<string,mixed>>
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
        return ($a['sort'] <=> $b['sort']) ?: strcmp((string) $a['label'], (string) $b['label']);
    });

    return $out;
}

/**
 * @param list<mixed> $templates
 * @return list<array<string,mixed>>
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
        return ($a['sort'] <=> $b['sort']) ?: strcmp((string) $a['label'], (string) $b['label']);
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
 * @param array<string,mixed> $row
 * @return array{id:string,label:string,text:string}
 */
function fixarivan_order_problem_variant_for_ui(array $row, string $lang): array
{
    $i18n = isset($row['i18n']) && is_array($row['i18n']) ? $row['i18n'] : [];

    return [
        'id' => (string) ($row['id'] ?? ''),
        'label' => (string) ($row['label'] ?? ''),
        'text' => fixarivan_order_problem_pick_i18n_text($i18n, $lang, (string) ($row['label'] ?? '')),
    ];
}

/**
 * @return list<array{id:string,emoji:string,label:string,text:string,sort:int,variants:list<array{id:string,label:string,text:string}>}>
 */
function fixarivan_order_problem_templates_for_ui(?string $lang = null): array
{
    $l = fixarivan_client_order_normalize_lang($lang);
    $rows = fixarivan_order_problem_templates_load();
    $out = [];
    foreach ($rows as $row) {
        if (empty($row['enabled'])) {
            continue;
        }
        $i18n = isset($row['i18n']) && is_array($row['i18n']) ? $row['i18n'] : [];
        $variants = [];
        if (!empty($row['variants']) && is_array($row['variants'])) {
            foreach ($row['variants'] as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $variants[] = fixarivan_order_problem_variant_for_ui($variant, $l);
            }
        }
        $out[] = [
            'id' => (string) $row['id'],
            'emoji' => (string) ($row['emoji'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'text' => fixarivan_order_problem_pick_i18n_text($i18n, $l, (string) ($row['text'] ?? $row['label'] ?? '')),
            'sort' => (int) ($row['sort'] ?? 0),
            'variants' => $variants,
        ];
    }

    return $out;
}
