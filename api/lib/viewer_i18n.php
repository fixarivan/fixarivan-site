<?php
declare(strict_types=1);

/**
 * Единые строки для client_portal и публичных viewer-страниц (ru / en / fi).
 * Заголовки документов согласованы с api/lib/document_templates.php (dt_translations).
 */

require_once __DIR__ . '/document_templates.php';

function fixarivan_viewer_normalize_lang(?string $lang): string
{
    return dt_normalize_language($lang);
}

/** Публичный статус заказа (TZ v4): in_progress, waiting_parts, … */
function fixarivan_viewer_public_status_label(string $lang, string $code): string
{
    $l = fixarivan_viewer_normalize_lang($lang);
    $ru = [
        'in_progress' => 'В работе',
        'waiting_parts' => 'Ожидает запчасть',
        'in_transit' => 'В пути',
        'done' => 'Готово',
        'delivered' => 'Выдано',
    ];
    $en = [
        'in_progress' => 'In progress',
        'waiting_parts' => 'Waiting for parts',
        'in_transit' => 'In transit',
        'done' => 'Ready',
        'delivered' => 'Delivered',
    ];
    $fi = [
        'in_progress' => 'Käsittelyssä',
        'waiting_parts' => 'Osia odotellessa',
        'in_transit' => 'Matkalla',
        'done' => 'Valmis',
        'delivered' => 'Luovutettu',
    ];
    $map = $l === 'en' ? $en : ($l === 'fi' ? $fi : $ru);

    return $map[$code] ?? $code;
}

/** Статус запчастей по заказу (агрегат). */
function fixarivan_viewer_parts_status_label(string $lang, ?string $code): string
{
    if ($code === null || trim($code) === '') {
        return '—';
    }
    $c = trim($code);
    $l = fixarivan_viewer_normalize_lang($lang);
    $ru = [
        'ordered' => 'Заказано',
        'in_transit' => 'В пути',
        'arrived' => 'Прибыло',
        'installed' => 'Установлено',
        'waiting' => 'Ожидание запчастей',
        'partial' => 'Частично пришло',
        'ready' => 'Запчасти готовы',
    ];
    $en = [
        'ordered' => 'Ordered',
        'in_transit' => 'In transit',
        'arrived' => 'Arrived',
        'installed' => 'Installed',
        'waiting' => 'Waiting for parts',
        'partial' => 'Partially received',
        'ready' => 'Parts ready',
    ];
    $fi = [
        'ordered' => 'Tilattu',
        'in_transit' => 'Matkalla',
        'arrived' => 'Saapunut',
        'installed' => 'Asennettu',
        'waiting' => 'Osia odotellessa',
        'partial' => 'Osittain saapunut',
        'ready' => 'Osat valmiina',
    ];
    $map = $l === 'en' ? $en : ($l === 'fi' ? $fi : $ru);

    return $map[$c] ?? $c;
}

/**
 * Статус акта в workflow (sent_to_client, viewed, signed, draft, pending, …).
 * Совпадает с подписями в PDF (dt_translations values.status).
 */
function fixarivan_viewer_order_workflow_status_label(string $lang, string $status): string
{
    $dict = dt_translations(fixarivan_viewer_normalize_lang($lang));
    $s = strtolower(trim($status));
    if ($s === '') {
        $s = 'draft';
    }
    $map = $dict['values']['status'] ?? [];
    if (isset($map[$s])) {
        return (string) $map[$s];
    }

    return $status !== '' ? $status : (string) ($map['draft'] ?? '—');
}

/**
 * UI клиентского портала (те же ключи, что раньше в client_portal.php $t).
 *
 * @return array<string, string>
 */
function fixarivan_viewer_portal_ui(string $lang): array
{
    $l = fixarivan_viewer_normalize_lang($lang);
    $dt = dt_translations($l);
    $actTitle = (string) ($dt['document_titles']['order'] ?? '');
    $all = [
        'ru' => [
            'page_title' => 'FixariVan — клиентский портал',
            'client' => 'Клиент',
            'orders' => 'Заказы',
            'active' => 'Текущий заказ',
            'status' => 'Статус',
            'expected' => 'Ориентировочно',
            'completed_on' => 'Завершено',
            'work_cost' => 'Ориентировочная стоимость работы',
            'lines_subtotal' => 'Позиции (итого)',
            'line_sum' => 'Сумма',
            'grand_total' => 'Итого',
            'comment' => 'Комментарий',
            'lines' => 'Позиции',
            'detail_section' => 'Состав и суммы',
            'parts' => 'Запчасти',
            'parts_status' => 'Статус запчастей',
            'sum' => 'Итого по позициям (продажа)',
            'act' => $actTitle !== '' ? $actTitle : 'Акт приёма',
            'receipts' => 'Квитанции',
            'invoices' => 'Счета',
            'reports' => 'Отчёты диагностики',
            'qty' => 'Кол-во',
            'price' => 'Цена',
            'name' => 'Наименование',
            'order_id' => 'Заказ',
            'device' => 'Устройство',
            'switch' => 'Переключить заказ',
            'other_orders' => 'Другие заказы',
            'open_order' => 'Открыть',
            'documents' => 'Документы',
            'documents_order_hint' => 'Квитанции, счета и отчёты по выбранному заказу.',
            'positions' => 'Позиции заказа',
            'orphan_reports' => 'Диагностика (без привязки к заказу)',
            'orphan_reports_hint' => 'Отчёты с вашим телефоном, сохранённые без номера заказа.',
            'comment_title' => 'Комментарий мастера',
            'view_act' => '📄 Посмотреть акт приёма',
            'view_receipt' => 'Посмотреть квитанцию',
            'view_invoice' => 'Посмотреть счёт',
            'view_report' => 'Посмотреть отчёт',
            'trust_call' => 'Позвонить',
            'trust_whatsapp' => 'WhatsApp',
            'snake_fab' => 'Мини-игра',
            'snake_title' => 'FixariVan — змейка',
            'snake_hint' => 'Пасхалка: общий анонимный рекорд для всех клиентов.',
            'snake_global' => 'Общий рекорд',
            'snake_yours' => 'Ваш счёт',
            'snake_close' => 'Закрыть',
            'snake_start' => 'Старт',
            'snake_again' => 'Ещё раз',
            'snake_pause' => 'Пауза',
            'snake_resume' => 'Дальше',
            'snake_speed' => 'Скорость',
            'snake_slow' => 'Медленно',
            'snake_normal' => 'Норма',
            'snake_fast' => 'Быстро',
            'snake_new_record' => 'Новый общий рекорд!',
            'snake_load_error' => 'Не удалось загрузить рекорд',
        ],
        'en' => [
            'page_title' => 'FixariVan — client portal',
            'client' => 'Client',
            'orders' => 'Orders',
            'active' => 'Current order',
            'status' => 'Status',
            'expected' => 'Expected',
            'completed_on' => 'Completed on',
            'work_cost' => 'Estimated work cost',
            'lines_subtotal' => 'Line items (subtotal)',
            'line_sum' => 'Line total',
            'grand_total' => 'Grand total',
            'comment' => 'Comment',
            'lines' => 'Line items',
            'detail_section' => 'Items and totals',
            'parts' => 'Parts',
            'parts_status' => 'Parts status',
            'sum' => 'Items total (sale)',
            'act' => $actTitle !== '' ? $actTitle : 'Acceptance act',
            'receipts' => 'Receipts',
            'invoices' => 'Invoices',
            'reports' => 'Diagnostic reports',
            'qty' => 'Qty',
            'price' => 'Price',
            'name' => 'Item',
            'order_id' => 'Order',
            'device' => 'Device',
            'switch' => 'Switch order',
            'other_orders' => 'Other orders',
            'open_order' => 'Open',
            'documents' => 'Documents',
            'documents_order_hint' => 'Receipts, invoices and reports for this order.',
            'positions' => 'Order lines',
            'orphan_reports' => 'Diagnostics (no order linked)',
            'orphan_reports_hint' => 'Reports saved with your phone number but without an order id.',
            'comment_title' => 'Technician comment',
            'view_act' => '📄 View acceptance act',
            'view_receipt' => 'View receipt',
            'view_invoice' => 'View invoice',
            'view_report' => 'View report',
            'trust_call' => 'Call',
            'trust_whatsapp' => 'WhatsApp',
            'snake_fab' => 'Mini game',
            'snake_title' => 'FixariVan — snake',
            'snake_hint' => 'Easter egg: one anonymous global high score for all clients.',
            'snake_global' => 'Global best',
            'snake_yours' => 'Your score',
            'snake_close' => 'Close',
            'snake_start' => 'Start',
            'snake_again' => 'Again',
            'snake_pause' => 'Pause',
            'snake_resume' => 'Resume',
            'snake_speed' => 'Speed',
            'snake_slow' => 'Slow',
            'snake_normal' => 'Normal',
            'snake_fast' => 'Fast',
            'snake_new_record' => 'New global record!',
            'snake_load_error' => 'Could not load record',
        ],
        'fi' => [
            'page_title' => 'FixariVan — asiakasportaali',
            'client' => 'Asiakas',
            'orders' => 'Tilaukset',
            'active' => 'Nykyinen tilaus',
            'status' => 'Tila',
            'expected' => 'Arvio',
            'completed_on' => 'Valmistumispäivä',
            'work_cost' => 'Arvioitu työn hinta',
            'lines_subtotal' => 'Rivit yhteensä',
            'line_sum' => 'Rivisumma',
            'grand_total' => 'Yhteensä',
            'comment' => 'Kommentti',
            'lines' => 'Rivit',
            'detail_section' => 'Sisältö ja summat',
            'parts' => 'Osat',
            'parts_status' => 'Osien tila',
            'sum' => 'Rivit yhteensä (myynti)',
            'act' => $actTitle !== '' ? $actTitle : 'Vastaanotto',
            'receipts' => 'Kuitit',
            'invoices' => 'Laskut',
            'reports' => 'Raportit',
            'qty' => 'Määrä',
            'price' => 'Hinta',
            'name' => 'Nimi',
            'order_id' => 'Tilaus',
            'device' => 'Laite',
            'switch' => 'Vaihda tilaus',
            'other_orders' => 'Muut tilaukset',
            'open_order' => 'Avaa',
            'documents' => 'Asiakirjat',
            'documents_order_hint' => 'Kuitit, laskut ja raportit valitulle tilaukselle.',
            'positions' => 'Tilausrivit',
            'orphan_reports' => 'Diagnostiikka (ei tilauksen linkkiä)',
            'orphan_reports_hint' => 'Raportit, joissa on puhelinnumerosi, mutta ei tilauksen tunnistetta.',
            'comment_title' => 'Teknikon kommentti',
            'view_act' => '📄 Avaa vastaanotto',
            'view_receipt' => 'Avaa kuitti',
            'view_invoice' => 'Avaa lasku',
            'view_report' => 'Avaa raportti',
            'trust_call' => 'Soita',
            'trust_whatsapp' => 'WhatsApp',
            'snake_fab' => 'Minipeli',
            'snake_title' => 'FixariVan — mato',
            'snake_hint' => 'Pääsiäismuna: yksi anonyymi paras tulos kaikille asiakkaille.',
            'snake_global' => 'Paras tulos',
            'snake_yours' => 'Pisteesi',
            'snake_close' => 'Sulje',
            'snake_start' => 'Aloita',
            'snake_again' => 'Uudestaan',
            'snake_pause' => 'Tauko',
            'snake_resume' => 'Jatka',
            'snake_speed' => 'Nopeus',
            'snake_slow' => 'Hidas',
            'snake_normal' => 'Norm.',
            'snake_fast' => 'Nopea',
            'snake_new_record' => 'Uusi paras tulos!',
            'snake_load_error' => 'Tuloksen lataus epäonnistui',
        ],
    ];

    return $all[$l];
}

/**
 * Просмотр акта приёма (order_view.php): герой + подпись + сообщения JS.
 *
 * @return array<string, string>
 */
function fixarivan_viewer_order_document_ui(string $lang): array
{
    $l = fixarivan_viewer_normalize_lang($lang);
    $dt = dt_translations($l);
    $docTitle = (string) ($dt['document_titles']['order'] ?? '');

    $m = [
        'ru' => [
            'page_title' => 'FixariVan — ' . $docTitle,
            'hero_sub' => 'FixariVan • доступ по защищённой ссылке',
            'status_prefix' => 'Статус:',
            'token_badge' => 'токен',
            'lang_note' => 'Просмотр только для чтения • доступ по защищённой ссылке',
            'sign_title' => 'Подпись клиента',
            'sign_hint' => 'Подписывая этот акт, вы подтверждаете его. После подписи статус изменится.',
            'consent_label' => 'Я подтверждаю, что устройство принято на диагностику.',
            'btn_sign' => 'Подписать',
            'btn_pdf' => 'Скачать PDF',
            'js_consent_required' => 'Нужно согласие.',
            'js_signed_ok' => 'Подпись сохранена.',
            'js_sign_error_prefix' => '',
            'js_pdf_error' => 'Ошибка PDF: ',
            'js_pdf_link_missing' => 'PDF создан, но ссылка не найдена.',
        ],
        'en' => [
            'page_title' => 'FixariVan — ' . $docTitle,
            'hero_sub' => 'FixariVan • secure link access',
            'status_prefix' => 'Status:',
            'token_badge' => 'token',
            'lang_note' => 'Read-only view • secure link access',
            'sign_title' => 'Client signature',
            'sign_hint' => 'Signing this document will mark it as signed.',
            'consent_label' => 'I confirm the device is accepted for diagnostics.',
            'btn_sign' => 'Sign',
            'btn_pdf' => 'Download PDF',
            'js_consent_required' => 'Consent is required.',
            'js_signed_ok' => 'Signed successfully.',
            'js_sign_error_prefix' => 'Error: ',
            'js_pdf_error' => 'PDF error: ',
            'js_pdf_link_missing' => 'PDF was created but the link was not found.',
        ],
        'fi' => [
            'page_title' => 'FixariVan — ' . $docTitle,
            'hero_sub' => 'FixariVan • suojattu linkki',
            'status_prefix' => 'Tila:',
            'token_badge' => 'tunnus',
            'lang_note' => 'Vain luku • suojattu linkki',
            'sign_title' => 'Asiakkaan allekirjoitus',
            'sign_hint' => 'Allekirjoittamalla tämän asiakirjan se merkitään allekirjoitetuksi.',
            'consent_label' => 'Vahvistan, että laite on vastaanotettu diagnostiikkaa varten.',
            'btn_sign' => 'Allekirjoita',
            'btn_pdf' => 'Lataa PDF',
            'js_consent_required' => 'Hyväksyntä vaaditaan.',
            'js_signed_ok' => 'Allekirjoitus onnistui.',
            'js_sign_error_prefix' => '',
            'js_pdf_error' => 'PDF-virhe: ',
            'js_pdf_link_missing' => 'PDF luotiin, mutta linkkiä ei löytynyt.',
        ],
    ];

    $out = $m[$l];
    $out['hero_title'] = $docTitle;

    return $out;
}

/**
 * Просмотр квитанции (receipt_view.php).
 *
 * @return array<string, string>
 */
function fixarivan_viewer_receipt_document_ui(string $lang): array
{
    $l = fixarivan_viewer_normalize_lang($lang);
    $dt = dt_translations($l);
    $docTitle = (string) ($dt['document_titles']['receipt'] ?? '');

    $m = [
        'ru' => [
            'page_title' => 'FixariVan — ' . $docTitle,
            'hero_sub' => 'FixariVan • доступ по защищённой ссылке',
            'lang_note' => 'Просмотр только для чтения • доступ по защищённой ссылке',
            'token_badge' => 'токен',
            'pay_method_prefix' => '💳 Способ:',
            'pay_status_prefix' => '📌 Статус:',
            'pay_date_prefix' => '📅 Оплата:',
            'paid_partial_prefix' => '💶 Внесено:',
            'btn_pdf' => 'Скачать PDF',
            'js_pdf_error' => 'Ошибка PDF: ',
        ],
        'en' => [
            'page_title' => 'FixariVan — ' . $docTitle,
            'hero_sub' => 'FixariVan • secure link access',
            'lang_note' => 'Read-only view • secure link access',
            'token_badge' => 'token',
            'pay_method_prefix' => '💳 Method:',
            'pay_status_prefix' => '📌 Status:',
            'pay_date_prefix' => '📅 Payment:',
            'paid_partial_prefix' => '💶 Paid:',
            'btn_pdf' => 'Download PDF',
            'js_pdf_error' => 'PDF error: ',
        ],
        'fi' => [
            'page_title' => 'FixariVan — ' . $docTitle,
            'hero_sub' => 'FixariVan • suojattu linkki',
            'lang_note' => 'Vain luku • suojattu linkki',
            'token_badge' => 'tunnus',
            'pay_method_prefix' => '💳 Tapa:',
            'pay_status_prefix' => '📌 Tila:',
            'pay_date_prefix' => '📅 Maksu:',
            'paid_partial_prefix' => '💶 Maksettu:',
            'btn_pdf' => 'Lataa PDF',
            'js_pdf_error' => 'PDF-virhe: ',
        ],
    ];

    $out = $m[$l];
    $out['hero_title'] = $docTitle;

    return $out;
}

/** Добавляет «€» к числовой строке ориентировочной стоимости работы, если валюта не указана. */
function fixarivan_portal_format_money_line(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/€|EUR|eur|\$/u', $raw)) {
        return $raw;
    }
    $compact = preg_replace('/\s+/u', '', $raw);
    if (preg_match('/^[\d.,]+$/u', $compact)) {
        return $raw . ' €';
    }

    return $raw;
}

/** Дата для строки «Завершено» в портале (YYYY-MM-DD или строка, понятная strtotime). */
function fixarivan_portal_format_completion_date(string $lang, ?string $raw): string
{
    $raw = trim((string) ($raw ?? ''));
    if ($raw === '') {
        return '';
    }

    return dt_format_date($raw, fixarivan_viewer_normalize_lang($lang));
}

/** Безопасный суффикс класса для чипа статуса ([a-z0-9_]). */
function fixarivan_portal_status_class_slug(string $raw): string
{
    $s = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $raw));

    return $s !== '' ? $s : 'unknown';
}

/** Эмодзи для публичного статуса заказа (client_portal). */
function fixarivan_portal_public_status_emoji(string $code): string
{
    $code = fixarivan_normalize_public_status($code);
    $map = [
        'in_progress' => '🔧',
        'waiting_parts' => '⏳',
        'in_transit' => '🚚',
        'done' => '✅',
        'delivered' => '📦',
    ];

    return $map[$code] ?? '📋';
}

/** Эмодзи для агрегата статуса запчастей (client_portal). */
function fixarivan_portal_parts_status_emoji(?string $code): string
{
    if ($code === null || trim((string) $code) === '') {
        return '📋';
    }
    $c = fixarivan_normalize_parts_status($code);
    $c = $c !== null ? $c : strtolower(trim((string) $code));
    $map = [
        'ordered' => '📝',
        'in_transit' => '🚚',
        'arrived' => '📥',
        'installed' => '🔩',
        'waiting' => '⏳',
        'partial' => '🔶',
        'ready' => '✅',
    ];

    return $map[$c] ?? '📋';
}

function fixarivan_portal_order_type_code(?string $raw): string
{
    $type = strtolower(trim((string)$raw));
    if ($type === 'sale' || $type === 'custom') {
        return $type;
    }

    return 'repair';
}

/**
 * @return array{code:string,label:string,icon:string,slug:string}
 */
function fixarivan_portal_order_type_meta(string $lang, ?string $raw): array
{
    $l = fixarivan_viewer_normalize_lang($lang);
    $code = fixarivan_portal_order_type_code($raw);
    $labels = [
        'ru' => ['repair' => 'Ремонт', 'sale' => 'Продажа', 'custom' => 'Нестандарт'],
        'en' => ['repair' => 'Repair', 'sale' => 'Sale', 'custom' => 'Custom'],
        'fi' => ['repair' => 'Korjaus', 'sale' => 'Myynti', 'custom' => 'Muu'],
    ];
    $icons = ['repair' => '🔧', 'sale' => '📦', 'custom' => '🧩'];
    $map = $labels[$l] ?? $labels['en'];

    return [
        'code' => $code,
        'label' => $map[$code] ?? $map['repair'],
        'icon' => $icons[$code] ?? '📋',
        'slug' => $code,
    ];
}

/**
 * @return array{label:string,icon:string,slug:string}
 */
function fixarivan_portal_client_public_status_meta(string $lang, ?string $orderType, string $code): array
{
    $l = fixarivan_viewer_normalize_lang($lang);
    $type = fixarivan_portal_order_type_code($orderType);
    $status = fixarivan_normalize_public_status($code);

    $map = [
        'repair' => [
            'ru' => [
                'waiting_parts' => ['label' => 'Ожидание', 'icon' => '⏳', 'slug' => 'waiting'],
                'in_transit' => ['label' => 'В пути', 'icon' => '🚚', 'slug' => 'ordered'],
                'in_progress' => ['label' => 'В работе', 'icon' => '🔧', 'slug' => 'processing'],
                'done' => ['label' => 'Готов', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Выдан', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Ожидание', 'icon' => '⏳', 'slug' => 'waiting'],
            ],
            'en' => [
                'waiting_parts' => ['label' => 'Waiting', 'icon' => '⏳', 'slug' => 'waiting'],
                'in_transit' => ['label' => 'In transit', 'icon' => '🚚', 'slug' => 'ordered'],
                'in_progress' => ['label' => 'In service', 'icon' => '🔧', 'slug' => 'processing'],
                'done' => ['label' => 'Ready', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Delivered', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Waiting', 'icon' => '⏳', 'slug' => 'waiting'],
            ],
            'fi' => [
                'waiting_parts' => ['label' => 'Odottaa', 'icon' => '⏳', 'slug' => 'waiting'],
                'in_transit' => ['label' => 'Matkalla', 'icon' => '🚚', 'slug' => 'ordered'],
                'in_progress' => ['label' => 'Työssä', 'icon' => '🔧', 'slug' => 'processing'],
                'done' => ['label' => 'Valmis', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Luovutettu', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Odottaa', 'icon' => '⏳', 'slug' => 'waiting'],
            ],
        ],
        'sale' => [
            'ru' => [
                'waiting_parts' => ['label' => 'Товар заказан', 'icon' => '📦', 'slug' => 'ordered'],
                'in_transit' => ['label' => 'Товар заказан', 'icon' => '📦', 'slug' => 'ordered'],
                'in_progress' => ['label' => 'Заказ оформлен', 'icon' => '🧾', 'slug' => 'accepted'],
                'done' => ['label' => 'К выдаче', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Выдан', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Заказ оформлен', 'icon' => '🧾', 'slug' => 'accepted'],
            ],
            'en' => [
                'waiting_parts' => ['label' => 'Item ordered', 'icon' => '📦', 'slug' => 'ordered'],
                'in_transit' => ['label' => 'Item ordered', 'icon' => '📦', 'slug' => 'ordered'],
                'in_progress' => ['label' => 'Order placed', 'icon' => '🧾', 'slug' => 'accepted'],
                'done' => ['label' => 'Ready pickup', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Delivered', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Order placed', 'icon' => '🧾', 'slug' => 'accepted'],
            ],
            'fi' => [
                'waiting_parts' => ['label' => 'Tuote tilattu', 'icon' => '📦', 'slug' => 'ordered'],
                'in_transit' => ['label' => 'Tuote tilattu', 'icon' => '📦', 'slug' => 'ordered'],
                'in_progress' => ['label' => 'Tilaus tehty', 'icon' => '🧾', 'slug' => 'accepted'],
                'done' => ['label' => 'Noudettavissa', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Luovutettu', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Tilaus tehty', 'icon' => '🧾', 'slug' => 'accepted'],
            ],
        ],
        'custom' => [
            'ru' => [
                'waiting_parts' => ['label' => 'В обработке', 'icon' => '⚙️', 'slug' => 'processing'],
                'in_transit' => ['label' => 'В обработке', 'icon' => '⚙️', 'slug' => 'processing'],
                'in_progress' => ['label' => 'В работе', 'icon' => '🔧', 'slug' => 'processing'],
                'done' => ['label' => 'Готов', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Выдан', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Заказ принят', 'icon' => '🧾', 'slug' => 'accepted'],
            ],
            'en' => [
                'waiting_parts' => ['label' => 'Processing', 'icon' => '⚙️', 'slug' => 'processing'],
                'in_transit' => ['label' => 'Processing', 'icon' => '⚙️', 'slug' => 'processing'],
                'in_progress' => ['label' => 'In work', 'icon' => '🔧', 'slug' => 'processing'],
                'done' => ['label' => 'Ready', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Delivered', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Accepted', 'icon' => '🧾', 'slug' => 'accepted'],
            ],
            'fi' => [
                'waiting_parts' => ['label' => 'Käsittelyssä', 'icon' => '⚙️', 'slug' => 'processing'],
                'in_transit' => ['label' => 'Käsittelyssä', 'icon' => '⚙️', 'slug' => 'processing'],
                'in_progress' => ['label' => 'Työssä', 'icon' => '🔧', 'slug' => 'processing'],
                'done' => ['label' => 'Valmis', 'icon' => '🟢', 'slug' => 'ready'],
                'delivered' => ['label' => 'Luovutettu', 'icon' => '📤', 'slug' => 'delivered'],
                'unknown' => ['label' => 'Vastaanotettu', 'icon' => '🧾', 'slug' => 'accepted'],
            ],
        ],
    ];

    $byType = $map[$type] ?? $map['repair'];
    $byLang = $byType[$l] ?? $byType['en'];

    return $byLang[$status] ?? $byLang['unknown'];
}

/**
 * @return array{label:string,icon:string,slug:string}
 */
function fixarivan_portal_client_parts_status_meta(string $lang, ?string $orderType, ?string $code): array
{
    $empty = ['label' => '', 'icon' => '', 'slug' => 'unknown'];
    if ($code === null || trim((string)$code) === '') {
        return $empty;
    }
    $l = fixarivan_viewer_normalize_lang($lang);
    $type = fixarivan_portal_order_type_code($orderType);
    $status = fixarivan_normalize_parts_status($code);
    $status = $status !== null ? $status : strtolower(trim((string)$code));

    $map = [
        'repair' => [
            'ru' => [
                'ordered' => ['label' => 'Запчасти заказаны', 'icon' => '📦', 'slug' => 'ordered'],
                'in_transit' => ['label' => 'Запчасти заказаны', 'icon' => '📦', 'slug' => 'ordered'],
                'arrived' => ['label' => 'Запчасти готовы', 'icon' => '✅', 'slug' => 'received'],
                'partial' => ['label' => 'Частично пришло', 'icon' => '📦', 'slug' => 'received'],
                'ready' => ['label' => 'Запчасти готовы', 'icon' => '✅', 'slug' => 'ready'],
                'installed' => ['label' => 'В работе', 'icon' => '🔧', 'slug' => 'processing'],
                'waiting' => ['label' => 'Ожидание', 'icon' => '⏳', 'slug' => 'waiting'],
            ],
            'en' => [
                'ordered' => ['label' => 'Parts ordered', 'icon' => '📦', 'slug' => 'ordered'],
                'in_transit' => ['label' => 'Parts ordered', 'icon' => '📦', 'slug' => 'ordered'],
                'arrived' => ['label' => 'Parts ready', 'icon' => '✅', 'slug' => 'received'],
                'partial' => ['label' => 'Partly received', 'icon' => '📦', 'slug' => 'received'],
                'ready' => ['label' => 'Parts ready', 'icon' => '✅', 'slug' => 'ready'],
                'installed' => ['label' => 'In service', 'icon' => '🔧', 'slug' => 'processing'],
                'waiting' => ['label' => 'Waiting', 'icon' => '⏳', 'slug' => 'waiting'],
            ],
            'fi' => [
                'ordered' => ['label' => 'Osat tilattu', 'icon' => '📦', 'slug' => 'ordered'],
                'in_transit' => ['label' => 'Osat tilattu', 'icon' => '📦', 'slug' => 'ordered'],
                'arrived' => ['label' => 'Osat valmiit', 'icon' => '✅', 'slug' => 'received'],
                'partial' => ['label' => 'Osittain tullut', 'icon' => '📦', 'slug' => 'received'],
                'ready' => ['label' => 'Osat valmiit', 'icon' => '✅', 'slug' => 'ready'],
                'installed' => ['label' => 'Työssä', 'icon' => '🔧', 'slug' => 'processing'],
                'waiting' => ['label' => 'Odottaa', 'icon' => '⏳', 'slug' => 'waiting'],
            ],
        ],
        'sale' => [
            'ru' => [
                'arrived' => ['label' => 'Товар получен', 'icon' => '✅', 'slug' => 'received'],
                'partial' => ['label' => 'Товар получен', 'icon' => '✅', 'slug' => 'received'],
                'ready' => ['label' => 'Товар получен', 'icon' => '✅', 'slug' => 'received'],
                'installed' => ['label' => 'Товар получен', 'icon' => '✅', 'slug' => 'received'],
            ],
            'en' => [
                'arrived' => ['label' => 'Item received', 'icon' => '✅', 'slug' => 'received'],
                'partial' => ['label' => 'Item received', 'icon' => '✅', 'slug' => 'received'],
                'ready' => ['label' => 'Item received', 'icon' => '✅', 'slug' => 'received'],
                'installed' => ['label' => 'Item received', 'icon' => '✅', 'slug' => 'received'],
            ],
            'fi' => [
                'arrived' => ['label' => 'Tuote saapunut', 'icon' => '✅', 'slug' => 'received'],
                'partial' => ['label' => 'Tuote saapunut', 'icon' => '✅', 'slug' => 'received'],
                'ready' => ['label' => 'Tuote saapunut', 'icon' => '✅', 'slug' => 'received'],
                'installed' => ['label' => 'Tuote saapunut', 'icon' => '✅', 'slug' => 'received'],
            ],
        ],
        'custom' => [
            'ru' => [
                'arrived' => ['label' => 'Позиции получены', 'icon' => '📦', 'slug' => 'received'],
                'partial' => ['label' => 'Позиции получены', 'icon' => '📦', 'slug' => 'received'],
                'ready' => ['label' => 'Позиции получены', 'icon' => '📦', 'slug' => 'received'],
                'installed' => ['label' => 'Позиции получены', 'icon' => '📦', 'slug' => 'received'],
            ],
            'en' => [
                'arrived' => ['label' => 'Items received', 'icon' => '📦', 'slug' => 'received'],
                'partial' => ['label' => 'Items received', 'icon' => '📦', 'slug' => 'received'],
                'ready' => ['label' => 'Items received', 'icon' => '📦', 'slug' => 'received'],
                'installed' => ['label' => 'Items received', 'icon' => '📦', 'slug' => 'received'],
            ],
            'fi' => [
                'arrived' => ['label' => 'Rivit saapuneet', 'icon' => '📦', 'slug' => 'received'],
                'partial' => ['label' => 'Rivit saapuneet', 'icon' => '📦', 'slug' => 'received'],
                'ready' => ['label' => 'Rivit saapuneet', 'icon' => '📦', 'slug' => 'received'],
                'installed' => ['label' => 'Rivit saapuneet', 'icon' => '📦', 'slug' => 'received'],
            ],
        ],
    ];

    $byType = $map[$type] ?? [];
    $byLang = $byType[$l] ?? [];

    return $byLang[$status] ?? $empty;
}
