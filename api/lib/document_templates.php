<?php

// Helper functions for rendering PDF/HTML documents.

require_once __DIR__ . '/company_profile.php';
require_once __DIR__ . '/format_money.php';
require_once __DIR__ . '/invoice_center.php';
require_once __DIR__ . '/invoice_i18n.php';

function dt_project_root_path(): string {
    return dirname(__DIR__, 2);
}

function dt_detect_image_mime(string $absPath): string {
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $m = finfo_file($fi, $absPath);
            finfo_close($fi);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }
    }
    $ext = strtolower((string)pathinfo($absPath, PATHINFO_EXTENSION));
    return match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream',
    };
}

/**
 * HTML блока реквизитов компании (шапка под заголовком документа).
 *
 * @param array<string,string> $profile
 * @param array<string,mixed> $data
 */
function dt_build_company_block_html(array $profile, array $data, array $dict, bool $skip_logos = false): string {
    $root = dt_project_root_path();
    $html = '';
    if (!$skip_logos) {
        $invoiceLogoRel = trim((string)($data['invoice_logo'] ?? ''));
        if ($invoiceLogoRel !== '') {
            $absInv = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $invoiceLogoRel);
            if (is_file($absInv) && is_readable($absInv)) {
                $rawInv = @file_get_contents($absInv);
                if ($rawInv !== false && $rawInv !== '') {
                    $mimeInv = dt_detect_image_mime($absInv);
                    $b64Inv = base64_encode($rawInv);
                    $html .= '<div class="dt-company-logo-wrap"><img class="dt-invoice-logo" src="data:' . htmlspecialchars($mimeInv, ENT_QUOTES, 'UTF-8') . ';base64,' . htmlspecialchars($b64Inv, ENT_QUOTES, 'UTF-8') . '" alt="" /></div>';
                }
            }
        }
        $logoRel = trim((string)($profile['company_logo'] ?? ''));
        if ($invoiceLogoRel === '' && $logoRel !== '') {
            $abs = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoRel);
            if (is_file($abs) && is_readable($abs)) {
                $raw = @file_get_contents($abs);
                if ($raw !== false && $raw !== '') {
                    $mime = dt_detect_image_mime($abs);
                    $b64 = base64_encode($raw);
                    $html .= '<div class="dt-company-logo-wrap"><img class="dt-company-logo" src="data:' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . ';base64,' . htmlspecialchars($b64, ENT_QUOTES, 'UTF-8') . '" alt="" /></div>';
                }
            }
        }
    }

    $name = trim((string)($profile['company_name'] ?? '')) ?: (string)($dict['company_name'] ?? '');
    $addr = trim((string)($profile['company_address'] ?? '')) ?: (string)($dict['company_address'] ?? '');
    $html .= '<div class="dt-company-title">' . dt_sanitize($name) . '</div>';
    if ($addr !== '') {
        $html .= '<div>' . dt_sanitize($addr) . '</div>';
    }

    $phone = trim((string)($profile['company_phone'] ?? ''));
    $email = trim((string)($profile['company_email'] ?? ''));
    $web = trim((string)($profile['company_website'] ?? ''));
    if ($phone !== '') {
        $html .= '<div>' . dt_sanitize($phone) . '</div>';
    }
    if ($email !== '') {
        $html .= '<div>' . dt_sanitize($email) . '</div>';
    }
    if ($web !== '') {
        $html .= '<div>' . dt_sanitize($web) . '</div>';
    }

    $yt = trim((string)($profile['y_tunnus'] ?? ''));
    if ($yt !== '') {
        $lbl = $dict['labels']['y_tunnus'] ?? 'Y-tunnus';
        $html .= '<div><span class="dt-k">' . dt_sanitize($lbl) . ':</span> ' . dt_sanitize($yt) . '</div>';
    }
    $bank = trim((string)($profile['bank_name'] ?? ''));
    $iban = trim((string)($profile['iban'] ?? ''));
    $bic = trim((string)($profile['bic'] ?? ''));
    if ($bank !== '') {
        $lbl = $dict['labels']['bank_name'] ?? 'Bank';
        $html .= '<div><span class="dt-k">' . dt_sanitize($lbl) . ':</span> ' . dt_sanitize($bank) . '</div>';
    }
    if ($iban !== '') {
        $lbl = $dict['labels']['iban'] ?? 'IBAN';
        $html .= '<div><span class="dt-k">' . dt_sanitize($lbl) . ':</span> ' . dt_sanitize($iban) . '</div>';
    }
    if ($bic !== '') {
        $lbl = $dict['labels']['bic'] ?? 'BIC';
        $html .= '<div><span class="dt-k">' . dt_sanitize($lbl) . ':</span> ' . dt_sanitize($bic) . '</div>';
    }

    return $html;
}

/**
 * Шапка PDF счёта: бренд слева, тип документа и номер справа.
 *
 * @param array<string,mixed> $profile
 * @param array<string,mixed> $data
 * @param array<string,mixed> $dict
 */
function dt_invoice_pdf_header_html(array $profile, array $data, array $dict, string $companyTitle, string $docTitle): string
{
    $logoHtml = dt_company_logo_img_html($profile, $data);
    if ($logoHtml !== '') {
        $logoHtml = str_replace('dt-receipt-brand-logo', 'dt-inv-pdf-logo', $logoHtml);
    }
    $invoiceNo = trim((string)($data['invoice_id'] ?? ''));
    if ($invoiceNo === '') {
        $invoiceNo = trim((string)($data['document_id'] ?? ''));
    }
    $lblNo = (string)($dict['labels']['invoice_id'] ?? 'Invoice #');
    /* Обёртка div + bgcolor для Dompdf: фон таблицы в PDF часто не виден */
    $html = '<div class="dt-inv-pdf-header">';
    $html .= '<table class="dt-inv-pdf-header-inner" cellpadding="0" cellspacing="0" width="100%" bgcolor="#1e40af"><tr>';
    $html .= '<td class="dt-inv-pdf-header-left" valign="middle">';
    $html .= '<table class="dt-inv-pdf-header-brandtbl" cellpadding="0" cellspacing="0"><tr>';
    $html .= '<td class="dt-inv-pdf-header-logo-cell" valign="middle">';
    if ($logoHtml !== '') {
        $html .= $logoHtml;
    } else {
        $html .= '<div class="dt-inv-pdf-logo-fallback">FV</div>';
    }
    $html .= '</td><td valign="middle">';
    $html .= '<div class="dt-inv-pdf-company">' . dt_sanitize($companyTitle) . '</div>';
    $html .= '<div class="dt-inv-pdf-tagline">Mobile Tech Service</div>';
    $html .= '</td></tr></table>';
    $html .= '</td>';
    $html .= '<td class="dt-inv-pdf-header-right" valign="middle" align="right">';
    $html .= '<div class="dt-inv-pdf-doc-title">' . dt_sanitize($docTitle) . '</div>';
    $html .= '<div class="dt-inv-pdf-invoice-no">' . dt_sanitize($lblNo) . ': <strong>' . dt_sanitize($invoiceNo !== '' ? $invoiceNo : '—') . '</strong></div>';
    $html .= '</td></tr></table></div>';

    return $html;
}

/**
 * Встроенный логотип компании для компактного header PDF.
 *
 * @param array<string,string> $profile
 * @param array<string,mixed> $data
 */
function dt_company_logo_img_html(array $profile, array $data): string
{
    $root = dt_project_root_path();
    $invoiceLogoRel = trim((string)($data['invoice_logo'] ?? ''));
    $logoRel = $invoiceLogoRel !== ''
        ? $invoiceLogoRel
        : trim((string)($profile['company_logo'] ?? ''));

    if ($logoRel === '') {
        return '';
    }

    $abs = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoRel);
    if (!is_file($abs) || !is_readable($abs)) {
        return '';
    }

    $raw = @file_get_contents($abs);
    if ($raw === false || $raw === '') {
        return '';
    }

    $mime = dt_detect_image_mime($abs);
    $b64 = base64_encode($raw);

    return '<img class="dt-receipt-brand-logo" src="data:' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . ';base64,' . htmlspecialchars($b64, ENT_QUOTES, 'UTF-8') . '" alt="">';
}

function dt_normalize_language(?string $language): string
{
    $lang = strtolower($language ?? 'ru');
    return in_array($lang, ['ru', 'en', 'fi'], true) ? $lang : 'ru';
}

/** Человекочитаемый способ оплаты (holvi_terminal, cash, …). */
function dt_payment_method_label(string $code, string $lang): string
{
    $code = strtolower(trim($code));
    $l = dt_normalize_language($lang);
    $maps = [
        'ru' => [
            'holvi_terminal' => 'Holvi (терминал)',
            'cash' => 'Наличные',
            'bank_transfer' => 'Банковский перевод',
            'card' => 'Карта',
            'mobilepay' => 'MobilePay',
            'other' => 'Другое',
        ],
        'en' => [
            'holvi_terminal' => 'Holvi terminal',
            'cash' => 'Cash',
            'bank_transfer' => 'Bank transfer',
            'card' => 'Card',
            'mobilepay' => 'MobilePay',
            'other' => 'Other',
        ],
        'fi' => [
            'holvi_terminal' => 'Holvi (pääte)',
            'cash' => 'Käteinen',
            'bank_transfer' => 'Tilisiirto',
            'card' => 'Kortti',
            'mobilepay' => 'MobilePay',
            'other' => 'Muu',
        ],
    ];
    if ($code === '') {
        return '—';
    }

    return $maps[$l][$code] ?? $maps['en'][$code] ?? $code;
}

/** Статус оплаты квитанции (paid, pending, partial, …). */
function dt_receipt_payment_status_label(string $status, string $lang): string
{
    $s = strtolower(trim($status));
    $l = dt_normalize_language($lang);
    $maps = [
        'ru' => [
            'paid' => 'Оплачено',
            'pending' => 'Ожидает',
            'partial' => 'Частично',
            'cancelled' => 'Отменено',
        ],
        'en' => [
            'paid' => 'Paid',
            'pending' => 'Pending',
            'partial' => 'Partially paid',
            'cancelled' => 'Cancelled',
        ],
        'fi' => [
            'paid' => 'Maksettu',
            'pending' => 'Odottaa',
            'partial' => 'Osittainen',
            'cancelled' => 'Peruttu',
        ],
    ];
    if ($s === '') {
        return '—';
    }

    return $maps[$l][$s] ?? $maps['en'][$s] ?? $s;
}

function dt_translations(string $language): array
{
    $lang = dt_normalize_language($language);
    $map = [
        'ru' => [
            'company_name' => 'FixariVan',
            'company_address' => 'Turku, Finland',
            'document_titles' => [
                'order' => 'Акт приёма техники',
                'receipt' => 'Квитанция',
                'report' => 'Диагностический отчёт',
                'invoice' => 'Счёт на оплату'
            ],
            'sections' => [
                'summary' => 'Основные данные',
                'customer' => 'Информация о клиенте',
                'device' => 'Информация об устройстве',
                'finance' => 'Финансовая информация',
                'services' => 'Услуги и примечания',
                'diagnosis' => 'Результаты диагностики',
                'recommendations' => 'Рекомендации',
                'additional' => 'Дополнительные сведения',
                'portal' => 'Информация для клиента',
                'signatures' => 'Подписи и подтверждения',
                'report_metrics' => 'Оценки и проверки',
                'report_battery' => 'Батарея и температура',
                'report_software' => 'ПО и услуги',
                'report_extra' => 'Дополнительные поля'
            ],
            'labels' => [
                'document_id' => 'Номер документа',
                'created_at' => 'Дата создания',
                'updated_at' => 'Дата обновления',
                'place' => 'Место приёма',
                'accept_date' => 'Дата приёма',
                'unique_code' => 'Уникальный код',
                'language' => 'Язык',
                'client_name' => 'ФИО клиента',
                'client_phone' => 'Телефон',
                'client_email' => 'Email',
                'device_model' => 'Модель',
                'device_serial' => 'Серийный номер',
                'device_type' => 'Тип устройства',
                'device_condition' => 'Состояние устройства',
                'accessories' => 'Аксессуары',
                'device_password' => 'Пароль / код блокировки',
                'problem_description' => 'Описание проблемы',
                'priority' => 'Приоритет',
                'status' => 'Статус',
                'diagnosis' => 'Диагноз',
                'recommendations' => 'Рекомендации',
                'repair_cost' => 'Стоимость ремонта',
                'repair_time' => 'Время ремонта',
                'warranty' => 'Гарантия',
                'technician' => 'Мастер',
                'work_date' => 'Дата выполнения',
                'services_rendered' => 'Оказанные услуги',
                'payment_method' => 'Способ оплаты',
                'payment_status' => 'Статус оплаты',
                'payment_date' => 'Дата оплаты',
                'payment_note' => 'Комментарий оплаты',
                'amount_paid' => 'Оплачено',
                'total_amount' => 'Сумма',
                'invoice_id' => 'Номер счёта',
                'due_date' => 'Срок оплаты',
                'service_object' => 'Объект/услуга',
                'service_address' => 'Адрес объекта',
                'payment_terms' => 'Условия оплаты',
                'notes' => 'Примечания',
                'signature' => 'Подпись клиента',
                'receipt_signature' => 'Подпись мастера',
                'pattern' => 'Графический пароль',
                'y_tunnus' => 'Y-tunnus',
                'iban' => 'IBAN',
                'bic' => 'BIC / SWIFT',
                'bank_name' => 'Банк',
                'website' => 'Сайт',
                'invoice_date' => 'Дата счёта',
                'subtotal_amount' => 'Сумма без НДС',
                'tax_amount' => 'НДС',
                'line_items' => 'Позиции счёта',
                'col_name' => 'Наименование',
                'col_qty' => 'Кол-во',
                'col_price' => 'Цена',
                'col_vat' => 'НДС %',
                'col_sum' => 'Сумма',
                'public_status_client' => 'Статус для клиента',
                'public_portal_expected' => 'Ориентировочная дата',
                'public_portal_comment' => 'Комментарий для клиента',
                'public_estimated_cost' => 'Ориентировочная стоимость работы',
                'report_type' => 'Тип отчёта',
                'report_device_rating' => 'Оценка устройства (из 10)',
                'report_condition_rating' => 'Внешнее состояние (из 10)',
                'report_component_tests' => 'Тесты компонентов',
                'report_cleaning' => 'Очистка',
                'report_battery_capacity' => 'Ёмкость батареи',
                'report_battery_status' => 'Состояние батареи',
                'report_battery_replace' => 'Замена батареи',
                'report_battery_notes' => 'Примечания',
                'report_current_capacity' => 'Текущая ёмкость',
                'report_wear_level' => 'Износ',
                'report_temp_cpu' => 'CPU °C',
                'report_temp_gpu' => 'GPU °C',
                'report_temp_disk' => 'Диск °C',
                'report_temp_ambient' => 'Окружение °C'
            ],
            'values' => [
                'priority' => [
                    'low' => 'Низкий',
                    'normal' => 'Нормальный',
                    'high' => 'Высокий',
                    'urgent' => 'Срочный'
                ],
                'status' => [
                    'pending' => 'В ожидании',
                    'in_progress' => 'В работе',
                    'completed' => 'Завершён',
                    'cancelled' => 'Отменён',
                    'draft' => 'Черновик',
                    'sent_to_client' => 'Отправлен клиенту',
                    'viewed' => 'Просмотрен',
                    'signed' => 'Подписан'
                ],
                'warranty' => [
                    '0' => 'Без гарантии',
                    '1' => 'С гарантией'
                ]
            ],
            'signature_caption' => 'Подпись подтверждает согласие с условиями обслуживания',
            'pattern_caption' => 'Зафиксированный графический пароль',
            'no_data' => 'Не указано',
            'boolean' => ['yes' => 'Да', 'no' => 'Нет'],
            'ui' => [
                'page_title' => 'Документ — FixariVan',
                'btn_print' => 'Печать',
                'btn_pdf' => 'Скачать PDF',
                'pdf_error' => 'Ошибка PDF',
                'doc_language_label' => 'Язык документа',
                'doc_language_saved' => 'Сохранённый язык',
            ],
        ],
        'en' => [
            'company_name' => 'FixariVan',
            'company_address' => 'Turku, Finland',
            'document_titles' => [
                'order' => 'Equipment Acceptance Act',
                'receipt' => 'Payment Receipt',
                'report' => 'Diagnostic Report',
                'invoice' => 'Invoice'
            ],
            'sections' => [
                'summary' => 'Summary',
                'customer' => 'Customer Information',
                'device' => 'Device Details',
                'finance' => 'Financial Information',
                'services' => 'Services & Notes',
                'diagnosis' => 'Diagnostic Results',
                'recommendations' => 'Recommendations',
                'additional' => 'Additional Details',
                'portal' => 'Client-facing information',
                'signatures' => 'Signatures & Confirmations',
                'report_metrics' => 'Ratings & checks',
                'report_battery' => 'Battery & sensors',
                'report_software' => 'Software & services',
                'report_extra' => 'Additional fields'
            ],
            'labels' => [
                'document_id' => 'Document ID',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
                'place' => 'Place of Acceptance',
                'accept_date' => 'Acceptance Date',
                'unique_code' => 'Verification Code',
                'language' => 'Language',
                'client_name' => 'Client Name',
                'client_phone' => 'Phone',
                'client_email' => 'Email',
                'device_model' => 'Model',
                'device_serial' => 'Serial Number',
                'device_type' => 'Device Type',
                'device_condition' => 'Device Condition',
                'accessories' => 'Accessories',
                'device_password' => 'Password / Lock Code',
                'problem_description' => 'Problem Description',
                'priority' => 'Priority',
                'status' => 'Status',
                'diagnosis' => 'Diagnosis',
                'recommendations' => 'Recommendations',
                'repair_cost' => 'Repair Cost',
                'repair_time' => 'Repair Time',
                'warranty' => 'Warranty',
                'technician' => 'Technician',
                'work_date' => 'Service Date',
                'services_rendered' => 'Services Rendered',
                'payment_method' => 'Payment Method',
                'payment_status' => 'Payment Status',
                'payment_date' => 'Payment Date',
                'payment_note' => 'Payment Note',
                'amount_paid' => 'Amount paid',
                'total_amount' => 'Total Amount',
                'invoice_id' => 'Invoice Number',
                'due_date' => 'Due Date',
                'service_object' => 'Service Object',
                'service_address' => 'Service address',
                'payment_terms' => 'Payment Terms',
                'notes' => 'Notes',
                'signature' => 'Customer Signature',
                'receipt_signature' => 'Technician Signature',
                'pattern' => 'Pattern Lock',
                'y_tunnus' => 'Business ID (Y-tunnus)',
                'iban' => 'IBAN',
                'bic' => 'BIC / SWIFT',
                'bank_name' => 'Bank',
                'website' => 'Website',
                'invoice_date' => 'Invoice date',
                'subtotal_amount' => 'Subtotal (excl. VAT)',
                'tax_amount' => 'VAT',
                'line_items' => 'Line items',
                'col_name' => 'Description',
                'col_qty' => 'Qty',
                'col_price' => 'Price',
                'col_vat' => 'VAT %',
                'col_sum' => 'Amount',
                'public_status_client' => 'Status (for client)',
                'public_portal_expected' => 'Expected date',
                'public_portal_comment' => 'Comment for client',
                'public_estimated_cost' => 'Estimated labor / work cost',
                'report_type' => 'Report type',
                'report_device_rating' => 'Device score (out of 10)',
                'report_condition_rating' => 'External condition (out of 10)',
                'report_component_tests' => 'Component tests',
                'report_cleaning' => 'Cleaning',
                'report_battery_capacity' => 'Battery capacity',
                'report_battery_status' => 'Battery health',
                'report_battery_replace' => 'Battery replacement',
                'report_battery_notes' => 'Notes',
                'report_current_capacity' => 'Current capacity',
                'report_wear_level' => 'Wear level',
                'report_temp_cpu' => 'CPU °C',
                'report_temp_gpu' => 'GPU °C',
                'report_temp_disk' => 'Disk °C',
                'report_temp_ambient' => 'Ambient °C'
            ],
            'values' => [
                'priority' => [
                    'low' => 'Low',
                    'normal' => 'Normal',
                    'high' => 'High',
                    'urgent' => 'Urgent'
                ],
                'status' => [
                    'pending' => 'Pending',
                    'in_progress' => 'In Progress',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    'draft' => 'Draft',
                    'sent_to_client' => 'Sent to client',
                    'viewed' => 'Viewed',
                    'signed' => 'Signed'
                ],
                'warranty' => [
                    '0' => 'No warranty',
                    '1' => 'With warranty'
                ],
                'public_order_status' => [
                    'in_progress' => 'In progress',
                    'waiting_parts' => 'Waiting for parts',
                    'in_transit' => 'In transit',
                    'done' => 'Ready',
                    'delivered' => 'Delivered',
                ],
            ],
            'signature_caption' => 'Signature confirms acceptance of service terms',
            'pattern_caption' => 'Captured pattern lock',
            'no_data' => 'Not provided',
            'boolean' => ['yes' => 'Yes', 'no' => 'No'],
            'ui' => [
                'page_title' => 'Document — FixariVan',
                'btn_print' => 'Print',
                'btn_pdf' => 'Download PDF',
                'pdf_error' => 'PDF error',
                'doc_language_label' => 'Document language',
                'doc_language_saved' => 'Saved language',
            ],
        ],
        'fi' => [
            'company_name' => 'FixariVan',
            'company_address' => 'Turku, Finland',
            'document_titles' => [
                'order' => 'Laitteen vastaanottopöytäkirja',
                'receipt' => 'Maksukuitti',
                'report' => 'Diagnostiikkaraportti',
                'invoice' => 'Lasku'
            ],
            'sections' => [
                'summary' => 'Yhteenveto',
                'customer' => 'Asiakastiedot',
                'device' => 'Laitteen tiedot',
                'finance' => 'Taloustiedot',
                'services' => 'Palvelut ja huomiot',
                'diagnosis' => 'Diagnoosi',
                'recommendations' => 'Suositukset',
                'additional' => 'Lisätiedot',
                'portal' => 'Asiakkaalle näkyvä tieto',
                'signatures' => 'Allekirjoitukset ja vahvistukset',
                'report_metrics' => 'Arviot ja tarkistukset',
                'report_battery' => 'Akku ja anturit',
                'report_software' => 'Ohjelmistot ja palvelut',
                'report_extra' => 'Muut kentät'
            ],
            'labels' => [
                'document_id' => 'Asiakirjan numero',
                'created_at' => 'Luotu',
                'updated_at' => 'Päivitetty',
                'place' => 'Vastaanottopaikka',
                'accept_date' => 'Vastaanottopäivä',
                'unique_code' => 'Vahvistuskoodi',
                'language' => 'Kieli',
                'client_name' => 'Asiakkaan nimi',
                'client_phone' => 'Puhelin',
                'client_email' => 'Sähköposti',
                'device_model' => 'Malli',
                'device_serial' => 'Sarjanumero',
                'device_type' => 'Laitetyyppi',
                'device_condition' => 'Laitteen kunto',
                'accessories' => 'Lisävarusteet',
                'device_password' => 'Salasana / lukituskoodi',
                'problem_description' => 'Vian kuvaus',
                'priority' => 'Prioriteetti',
                'status' => 'Tila',
                'diagnosis' => 'Diagnoosi',
                'recommendations' => 'Suositukset',
                'repair_cost' => 'Korjauksen hinta',
                'repair_time' => 'Korjauksen kesto',
                'warranty' => 'Takuu',
                'technician' => 'Teknikko',
                'work_date' => 'Työn päivämäärä',
                'services_rendered' => 'Suoritetut palvelut',
                'payment_method' => 'Maksutapa',
                'payment_status' => 'Maksun tila',
                'payment_date' => 'Maksupäivä',
                'payment_note' => 'Maksun kommentti',
                'amount_paid' => 'Maksettu',
                'total_amount' => 'Summa',
                'invoice_id' => 'Laskun numero',
                'due_date' => 'Eräpäivä',
                'service_object' => 'Palvelukohde',
                'service_address' => 'Osoite',
                'payment_terms' => 'Maksuehdot',
                'notes' => 'Huomiot',
                'signature' => 'Asiakkaan allekirjoitus',
                'receipt_signature' => 'Teknikon allekirjoitus',
                'pattern' => 'Kuviolukko',
                'y_tunnus' => 'Y-tunnus',
                'iban' => 'IBAN',
                'bic' => 'BIC / SWIFT',
                'bank_name' => 'Pankki',
                'website' => 'Verkkosivusto',
                'invoice_date' => 'Laskun päivä',
                'subtotal_amount' => 'Veroton summa',
                'tax_amount' => 'ALV',
                'line_items' => 'Rivit',
                'col_name' => 'Nimike',
                'col_qty' => 'Määrä',
                'col_price' => 'Hinta',
                'col_vat' => 'ALV %',
                'col_sum' => 'Summa',
                'public_status_client' => 'Tila (asiakkaalle)',
                'public_portal_expected' => 'Arvioitu päivä',
                'public_portal_comment' => 'Kommentti asiakkaalle',
                'public_estimated_cost' => 'Arvioitu työn hinta',
                'report_type' => 'Raportin tyyppi',
                'report_device_rating' => 'Laitteen arvio (10)',
                'report_condition_rating' => 'Ulkonäkö (10)',
                'report_component_tests' => 'Komponenttitestit',
                'report_cleaning' => 'Puhdistus',
                'report_battery_capacity' => 'Akun kapasiteetti',
                'report_battery_status' => 'Akun kunto',
                'report_battery_replace' => 'Akun vaihto',
                'report_battery_notes' => 'Huomiot',
                'report_current_capacity' => 'Nykyinen kapasiteetti',
                'report_wear_level' => 'Kuluma',
                'report_temp_cpu' => 'CPU °C',
                'report_temp_gpu' => 'GPU °C',
                'report_temp_disk' => 'Levy °C',
                'report_temp_ambient' => 'Ympäristö °C'
            ],
            'values' => [
                'priority' => [
                    'low' => 'Matala',
                    'normal' => 'Normaali',
                    'high' => 'Korkea',
                    'urgent' => 'Kiireellinen'
                ],
                'status' => [
                    'pending' => 'Odottaa',
                    'in_progress' => 'Työn alla',
                    'completed' => 'Valmis',
                    'cancelled' => 'Peruttu',
                    'draft' => 'Luonnos',
                    'sent_to_client' => 'Lähetetty asiakkaalle',
                    'viewed' => 'Katsottu',
                    'signed' => 'Allekirjoitettu'
                ],
                'warranty' => [
                    '0' => 'Ei takuuta',
                    '1' => 'Takuu mukana'
                ],
                'public_order_status' => [
                    'in_progress' => 'Käsittelyssä',
                    'waiting_parts' => 'Osia odotellessa',
                    'in_transit' => 'Matkalla',
                    'done' => 'Valmis',
                    'delivered' => 'Luovutettu',
                ],
            ],
            'signature_caption' => 'Allekirjoitus vahvistaa palveluehdot',
            'pattern_caption' => 'Tallennettu kuviolukko',
            'no_data' => 'Ei tietoa',
            'boolean' => ['yes' => 'Kyllä', 'no' => 'Ei'],
            'ui' => [
                'page_title' => 'Asiakirja — FixariVan',
                'btn_print' => 'Tulosta',
                'btn_pdf' => 'Lataa PDF',
                'pdf_error' => 'PDF-virhe',
                'doc_language_label' => 'Asiakirjan kieli',
                'doc_language_saved' => 'Tallennettu kieli',
            ],
        ]
    ];

    $dict = $map[$lang] ?? $map['ru'];
    return fixarivan_invoice_i18n_merge_into_dict($dict, $lang);
}

/** Часовой пояс для дат в PDF/печати (совпадает с бизнес-логикой квитанций). */
function dt_display_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('Europe/Helsinki');
    }

    return $tz;
}

/**
 * Парсит дату/время и возвращает момент в Europe/Helsinki для отображения.
 * Строки без часового пояса (как в SQLite) считаются локальным временем Финляндии.
 */
function dt_parse_to_zoned_datetime(?string $raw): ?DateTimeImmutable
{
    if ($raw === null) {
        return null;
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    $tz = dt_display_timezone();
    $hasTz = preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $s) === 1;
    $onlyDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    try {
        if ($onlyDate) {
            return new DateTimeImmutable($s . ' 00:00:00', $tz);
        }
        if (
            !$hasTz
            && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}/', $s) === 1
        ) {
            $norm = str_replace('T', ' ', $s);

            return new DateTimeImmutable($norm, $tz);
        }
        $dt = new DateTimeImmutable($s);

        return $dt->setTimezone($tz);
    } catch (Throwable $e) {
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }

        return (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    }
}

function dt_format_date(?string $date, string $language, bool $withTime = false): string
{
    if (!$date) {
        return '';
    }
    $z = dt_parse_to_zoned_datetime($date);
    if ($z === null) {
        return $date;
    }

    switch ($language) {
        case 'en':
            return $withTime ? $z->format('F j, Y H:i') : $z->format('F j, Y');
        case 'fi':
            return $withTime ? $z->format('d.m.Y H:i') : $z->format('d.m.Y');
        default:
            return $withTime ? $z->format('d.m.Y H:i') : $z->format('d.m.Y');
    }
}

function dt_format_currency($value, string $language): string
{
    $amount = is_numeric($value) ? (float)$value : 0.0;

    return fixarivan_format_money($amount, $language);
}

function dt_boolean_label($value, array $dict): string
{
    $val = null;
    if (is_bool($value)) {
        $val = $value;
    } elseif (is_numeric($value)) {
        $val = ((int)$value) !== 0;
    } elseif (is_string($value)) {
        $val = in_array(strtolower($value), ['1', 'true', 'yes', 'да', 'kyllä', 'kylä'], true);
    }

    if ($val === null) {
        return $dict['no_data'] ?? '—';
    }

    return $val ? ($dict['boolean']['yes'] ?? 'Yes') : ($dict['boolean']['no'] ?? 'No');
}

function dt_sanitize(?string $value, string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dt_render_field(string $label, ?string $value): string
{
    return '<div class="dt-field"><div class="dt-label">' . $label . '</div><div class="dt-value">' . $value . '</div></div>';
}

function dt_has_meaningful_value($value): bool
{
    if ($value === null) {
        return false;
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return false;
    }
    $normalized = mb_strtolower($raw, 'UTF-8');

    return !in_array($normalized, ['—', '-', 'null', 'не указано', 'not specified', 'ei määritetty', 'n/a'], true);
}

function dt_order_detail_line(string $label, ?string $value, bool $multiline = false): string
{
    if (!dt_has_meaningful_value($value)) {
        return '';
    }
    $out = $multiline ? nl2br(dt_sanitize((string)$value, '')) : dt_sanitize((string)$value, '');

    return '<div class="dt-order-detail"><div class="dt-order-detail-label">' . dt_sanitize($label) . '</div><div class="dt-order-detail-value">' . $out . '</div></div>';
}

function dt_order_card_html(string $title, string $body): string
{
    if (trim($body) === '') {
        return '';
    }

    return '<div class="dt-order-card"><div class="dt-order-card-title">' . dt_sanitize($title) . '</div>' . $body . '</div>';
}

function dt_prepare_image_src(string $value): string
{
    if (strpos($value, 'data:image') === 0) {
        return $value;
    }
    return 'data:image/png;base64,' . $value;
}

function dt_merge_data(array $dbData = null, array $override = null): array
{
    $normalized = [];
    if ($dbData) {
        foreach ($dbData as $key => $value) {
            $normalized[$key] = $value;
        }
    }

    if ($override) {
        $map = [
            'documentId' => 'document_id',
            'clientName' => 'client_name',
            'clientPhone' => 'client_phone',
            'clientEmail' => 'client_email',
            'deviceModel' => 'device_model',
            'deviceSerial' => 'device_serial',
            'deviceType' => 'device_type',
            'diagnosis' => 'diagnosis',
            'recommendations' => 'recommendations',
            'repairCost' => 'repair_cost',
            'repairTime' => 'repair_time',
            'warranty' => 'warranty',
            'technicianName' => 'technician_name',
            'technician' => 'technician_name',
            'workDate' => 'work_date',
            'uniqueCode' => 'unique_code',
            'placeOfAcceptance' => 'place_of_acceptance',
            'dateOfAcceptance' => 'date_of_acceptance',
            'language' => 'language',
            'patternData' => 'pattern_data',
            'signatureData' => 'client_signature',
            'clientSignature' => 'client_signature',
            'servicesRendered' => 'services_rendered',
            'services' => 'services_rendered',
            'receiptDate' => 'date_of_acceptance',
            'paymentDate' => 'payment_date',
            'paymentStatus' => 'payment_status',
            'paymentNote' => 'payment_note',
            'amountPaid' => 'amount_paid',
            'paymentMethod' => 'payment_method',
            'totalAmount' => 'total_amount'
            ,'invoiceId' => 'invoice_id'
            ,'dueDate' => 'due_date'
            ,'serviceObject' => 'service_object'
            ,'displayMode' => 'display_mode'
            ,'paymentTerms' => 'payment_terms'
            ,'publicEstimatedCost' => 'public_estimated_cost'
            ,'publicComment' => 'public_comment'
            ,'publicExpectedDate' => 'public_expected_date'
            ,'publicStatus' => 'public_status'
        ];

        foreach ($override as $key => $value) {
            if (isset($map[$key])) {
                $normalized[$map[$key]] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }
    }

    return $normalized;
}

function dt_render_document_html(string $type, array $data, string $language): string
{
    $lang = dt_normalize_language($language);
    $dict = dt_translations($lang);
    $profile = fixarivan_company_profile_load();

    $title = $dict['document_titles'][$type] ?? $dict['document_titles']['order'];
    $noData = $dict['no_data'];

    $companyTitle = trim((string)($profile['company_name'] ?? '')) !== ''
        ? (string)$profile['company_name']
        : (string)($dict['company_name'] ?? 'FixariVan');

    $docClass = 'dt-document dt-document--' . preg_replace('/[^a-z0-9_-]/i', '', $type);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<style>' . dt_css() . '</style>';
    if ($type === 'invoice') {
        $html .= '<style>' . dt_invoice_pdf_extra_css() . '</style>';
    }
    $html .= '</head><body><div class="' . dt_sanitize($docClass) . '">';
    if ($type === 'receipt') {
        $receiptLogo = dt_company_logo_img_html($profile, $data);
        $receiptDate = dt_format_date((string)($data['payment_date'] ?? $data['date_created'] ?? ''), $lang);
        $receiptDate = $receiptDate !== '' ? $receiptDate : $noData;
        $receiptYt = trim((string)($profile['y_tunnus'] ?? ''));
        $receiptPhone = trim((string)($profile['company_phone'] ?? ''));
        $receiptEmail = trim((string)($profile['company_email'] ?? ''));
        $brandBits = [dt_sanitize($companyTitle)];
        if ($receiptYt !== '') {
            $brandBits[] = dt_sanitize((string)($dict['labels']['y_tunnus'] ?? 'Y-tunnus')) . ': ' . dt_sanitize($receiptYt);
        }
        if ($receiptPhone !== '') {
            $brandBits[] = dt_sanitize((string)($dict['labels']['client_phone'] ?? 'Phone')) . ': ' . dt_sanitize($receiptPhone);
        }
        if ($receiptEmail !== '') {
            $brandBits[] = dt_sanitize((string)($dict['labels']['client_email'] ?? 'Email')) . ': ' . dt_sanitize($receiptEmail);
        }
        $docBits = [
            dt_sanitize((string)($dict['labels']['document_id'] ?? 'Document')) . ': ' . dt_sanitize((string)($data['document_id'] ?? $noData), $noData),
            dt_sanitize((string)($dict['labels']['payment_date'] ?? $dict['labels']['created_at'] ?? 'Date')) . ': ' . dt_sanitize($receiptDate, $noData),
        ];
        $html .= '<div class="dt-receipt-header">';
        $html .= '<div class="dt-receipt-header-main">';
        $html .= '<div class="dt-receipt-brand">';
        if ($receiptLogo !== '') {
            $html .= $receiptLogo;
        } else {
            $html .= '<div class="dt-receipt-brand-fallback">FV</div>';
        }
        $html .= '<div class="dt-receipt-brand-copy">';
        $html .= '<div class="dt-receipt-brand-name">' . dt_sanitize($companyTitle) . '</div>';
        $html .= '<div class="dt-receipt-brand-sub">Mobile Tech Service</div>';
        $html .= '<div class="dt-receipt-brand-meta">' . implode('<span class="dt-receipt-sep">|</span>', $brandBits) . '</div>';
        $html .= '</div></div>';
        $html .= '<div class="dt-receipt-head-meta">';
        $html .= '<div class="dt-receipt-doc-title">' . dt_sanitize($title) . '</div>';
        $html .= '<div class="dt-receipt-doc-meta">' . implode('<span class="dt-receipt-sep">|</span>', $docBits) . '</div>';
        $html .= '</div></div>';
        $html .= '</div>';
    } elseif ($type === 'order') {
        $orderLogo = dt_company_logo_img_html($profile, $data);
        $orderDate = dt_format_date((string)($data['date_of_acceptance'] ?? $data['date_created'] ?? ''), $lang);
        $orderDate = $orderDate !== '' ? $orderDate : $noData;
        $orderAddress = trim((string)($profile['company_address'] ?? ''));
        $orderPhone = trim((string)($profile['company_phone'] ?? ''));
        $orderEmail = trim((string)($profile['company_email'] ?? ''));
        $orderYt = trim((string)($profile['y_tunnus'] ?? ''));
        $html .= '<div class="dt-order-header">';
        $html .= '<div class="dt-order-brand">';
        if ($orderLogo !== '') {
            $html .= str_replace('dt-receipt-brand-logo', 'dt-order-brand-logo', $orderLogo);
        } else {
            $html .= '<div class="dt-order-brand-fallback">FV</div>';
        }
        $html .= '<div class="dt-order-brand-copy">';
        $html .= '<div class="dt-order-brand-name">' . dt_sanitize($companyTitle) . '</div>';
        $html .= '<div class="dt-order-brand-sub">Mobile Tech Service</div>';
        if ($orderAddress !== '') {
            $html .= '<div class="dt-order-brand-meta">' . dt_sanitize($orderAddress) . '</div>';
        }
        if ($orderPhone !== '') {
            $html .= '<div class="dt-order-brand-meta">' . dt_sanitize($orderPhone) . '</div>';
        }
        if ($orderEmail !== '') {
            $html .= '<div class="dt-order-brand-meta">' . dt_sanitize($orderEmail) . '</div>';
        }
        if ($orderYt !== '') {
            $html .= '<div class="dt-order-brand-meta">' . dt_sanitize((string)($dict['labels']['y_tunnus'] ?? 'Y-tunnus')) . ': ' . dt_sanitize($orderYt) . '</div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="dt-order-head-meta">';
        $html .= '<div class="dt-order-doc-title">' . dt_sanitize($title) . '</div>';
        $html .= '<div class="dt-order-doc-meta">' . dt_sanitize((string)($dict['labels']['document_id'] ?? 'Document')) . ': ' . dt_sanitize((string)($data['document_id'] ?? $noData), $noData) . '</div>';
        $html .= '<div class="dt-order-doc-meta">' . dt_sanitize((string)($dict['labels']['accept_date'] ?? $dict['labels']['created_at'] ?? 'Date')) . ': ' . dt_sanitize($orderDate, $noData) . '</div>';
        $html .= '</div></div>';
    } elseif ($type === 'invoice') {
        $html .= dt_invoice_pdf_header_html($profile, $data, $dict, $companyTitle, $title);
    } else {
        $html .= '<div class="dt-header"><div class="dt-header-title">' . dt_sanitize($companyTitle) . '</div>';
        $html .= '<div class="dt-header-sub">' . dt_sanitize($title) . '</div></div>';
        $html .= '<div class="dt-company">';
        $html .= dt_build_company_block_html($profile, $data, $dict) . '</div>';
    }

    if ($type !== 'invoice' && $type !== 'receipt' && $type !== 'order') {
        $html .= '<div class="dt-section">';
        $html .= '<div class="dt-section-title">' . dt_sanitize($dict['sections']['summary']) . '</div>';
        $html .= dt_render_field($dict['labels']['document_id'], dt_sanitize($data['document_id'] ?? $noData, $noData));
        $dateLabel = $dict['labels']['created_at'];
        $html .= dt_render_field($dateLabel, dt_sanitize(dt_format_date($data['date_created'] ?? null, $lang, true), $noData));
        if (!empty($data['date_updated'])) {
            $html .= dt_render_field($dict['labels']['updated_at'], dt_sanitize(dt_format_date($data['date_updated'], $lang, true), $noData));
        }
        if (!empty($data['place_of_acceptance'])) {
            $html .= dt_render_field($dict['labels']['place'], dt_sanitize($data['place_of_acceptance']));
        }
        if (!empty($data['date_of_acceptance'])) {
            $html .= dt_render_field($dict['labels']['accept_date'], dt_sanitize(dt_format_date($data['date_of_acceptance'], $lang)));
        }
        if (!empty($data['unique_code'])) {
            $html .= dt_render_field($dict['labels']['unique_code'], dt_sanitize($data['unique_code']));
        }
        if (!empty($data['language'])) {
            $html .= dt_render_field($dict['labels']['language'], dt_sanitize(strtoupper((string)$data['language'])));
        }
        $html .= '</div>';
    }

    if ($type === 'order') {
        $html .= dt_section_order($data, $dict, $lang);
    } elseif ($type === 'receipt') {
        $html .= dt_section_receipt($data, $dict, $lang);
    } elseif ($type === 'invoice') {
        $html .= dt_section_invoice($data, $dict, $lang, $profile);
    } else {
        $html .= dt_section_report($data, $dict, $lang);
    }

    if ($type !== 'invoice') {
        $showSignatures = $type !== 'report'
            || !empty($data['client_signature'])
            || !empty($data['pattern_data']);
        if ($showSignatures) {
            if ($type === 'receipt') {
                $html .= dt_section_receipt_signature($data, $dict);
            } elseif ($type === 'order') {
                $html .= dt_section_order_signature($data, $dict);
            } else {
                $html .= '<div class="dt-section">';
                $html .= '<div class="dt-section-title">' . dt_sanitize($dict['sections']['signatures']) . '</div>';
                if (!empty($dict['labels']['signature'])) {
                    $html .= '<div class="dt-signature-block"><div class="dt-signature-label">' . dt_sanitize($dict['labels']['signature']) . '</div>';
                    if (!empty($data['client_signature'])) {
                        $html .= '<img class="dt-signature" src="' . dt_prepare_image_src($data['client_signature']) . '" alt="signature">';
                    }
                    $html .= '<div class="dt-signature-caption">' . dt_sanitize($dict['signature_caption']) . '</div></div>';
                }
                if (!empty($dict['labels']['pattern'])) {
                    $html .= '<div class="dt-signature-block"><div class="dt-signature-label">' . dt_sanitize($dict['labels']['pattern']) . '</div>';
                    if (!empty($data['pattern_data'])) {
                        $html .= '<img class="dt-pattern" src="' . dt_prepare_image_src($data['pattern_data']) . '" alt="pattern">';
                    }
                    $html .= '<div class="dt-signature-caption">' . dt_sanitize($dict['pattern_caption']) . '</div></div>';
                }
                $html .= '</div>';
            }
        }
    }

    $footerYear = (new DateTimeImmutable('now', dt_display_timezone()))->format('Y');
    $footerText = (string)($dict['footer_invoice'] ?? ('© ' . $footerYear . ' FixariVan'));
    $html .= '<div class="dt-footer">' . dt_sanitize($footerText, '') . '</div>';
    $html .= '</div></body></html>';

    return $html;
}

function dt_section_order(array $data, array $dict, string $lang): string
{
    $no = $dict['no_data'];
    $titles = [
        'ru' => ['client' => 'Клиент', 'device' => 'Устройство', 'status' => 'Статус / условия'],
        'en' => ['client' => 'Client', 'device' => 'Device', 'status' => 'Status / terms'],
        'fi' => ['client' => 'Asiakas', 'device' => 'Laite', 'status' => 'Tila / ehdot'],
    ];
    $T = $titles[$lang] ?? $titles['en'];
    $clientName = dt_has_meaningful_value($data['client_name'] ?? null) ? (string)$data['client_name'] : $no;
    $clientBody = '<div class="dt-order-main-value">' . dt_sanitize($clientName, $no) . '</div>';
    $clientBody .= dt_order_detail_line((string)($dict['labels']['client_phone'] ?? 'Phone'), (string)($data['client_phone'] ?? ''));
    $clientBody .= dt_order_detail_line((string)($dict['labels']['client_email'] ?? 'Email'), (string)($data['client_email'] ?? ''));

    $deviceModel = dt_has_meaningful_value($data['device_model'] ?? null) ? (string)$data['device_model'] : $no;
    $deviceBody = '<div class="dt-order-main-value">' . dt_sanitize($deviceModel, $no) . '</div>';
    $deviceBody .= dt_order_detail_line((string)($dict['labels']['device_type'] ?? 'Type'), (string)($data['device_type'] ?? ''));
    $deviceBody .= dt_order_detail_line((string)($dict['labels']['device_serial'] ?? 'Serial'), (string)($data['device_serial'] ?? ''));
    $deviceBody .= dt_order_detail_line((string)($dict['labels']['device_condition'] ?? 'Condition'), (string)($data['device_condition'] ?? ''));
    $deviceBody .= dt_order_detail_line((string)($dict['labels']['accessories'] ?? 'Accessories'), (string)($data['accessories'] ?? ''));
    $deviceBody .= dt_order_detail_line((string)($dict['labels']['device_password'] ?? 'Password'), (string)($data['device_password'] ?? ''));
    if (dt_has_meaningful_value($data['problem_description'] ?? null)) {
        $deviceBody .= '<div class="dt-order-problem-block">';
        $deviceBody .= '<div class="dt-order-detail-label">' . dt_sanitize((string)($dict['labels']['problem_description'] ?? 'Problem')) . '</div>';
        $deviceBody .= '<div class="dt-order-problem-text">' . nl2br(dt_sanitize((string)$data['problem_description'], '')) . '</div>';
        $deviceBody .= '</div>';
    }

    $pubSt = trim((string)($data['public_status'] ?? ''));
    $pubExp = trim((string)($data['public_expected_date'] ?? ''));
    $pubCom = trim((string)($data['public_comment'] ?? ''));
    $pubCost = trim((string)($data['public_estimated_cost'] ?? ''));
    $posMap = $dict['values']['public_order_status'] ?? [];
    $statusLabel = '';
    if ($pubSt !== '') {
        $statusLabel = is_array($posMap) && isset($posMap[$pubSt]) ? (string)$posMap[$pubSt] : $pubSt;
    } elseif (isset($data['status'])) {
        $statusLabel = (string)($dict['values']['status'][$data['status']] ?? $data['status']);
    }
    $statusBody = '';
    $statusBody .= dt_order_detail_line((string)($dict['labels']['status'] ?? 'Status'), $statusLabel);
    $statusBody .= dt_order_detail_line((string)($dict['labels']['public_portal_expected'] ?? 'Expected date'), $pubExp !== '' ? dt_format_date($pubExp, $lang) : '');
    $statusBody .= dt_order_detail_line((string)($dict['labels']['public_estimated_cost'] ?? 'Work cost'), $pubCost);
    $statusBody .= dt_order_detail_line((string)($dict['labels']['public_portal_comment'] ?? 'Comment'), $pubCom, true);
    if ($statusBody === '') {
        $statusBody = '<div class="dt-order-main-value">' . dt_sanitize($no) . '</div>';
    }

    $html = '<div class="dt-order-layout">';
    $html .= dt_order_card_html($T['client'], $clientBody);
    $html .= dt_order_card_html($T['device'], $deviceBody);
    $html .= dt_order_card_html($T['status'], $statusBody);
    $html .= '</div>';

    return $html;
}

function dt_section_order_signature(array $data, array $dict): string
{
    $html = '<div class="dt-order-signature-card">';
    $html .= '<div class="dt-order-card-title">' . dt_sanitize((string)($dict['sections']['signatures'] ?? 'Signature')) . '</div>';
    if (!empty($data['client_signature'])) {
        $html .= '<img class="dt-order-signature-image" src="' . dt_prepare_image_src((string)$data['client_signature']) . '" alt="signature">';
    }
    $html .= '<div class="dt-order-signature-line"></div>';
    $html .= '<div class="dt-order-signature-label">' . dt_sanitize((string)($dict['labels']['signature'] ?? 'Client signature')) . '</div>';
    if (!empty($data['pattern_data'])) {
        $html .= '<div class="dt-order-pattern-wrap">';
        $html .= '<div class="dt-order-pattern-label">' . dt_sanitize((string)($dict['labels']['pattern'] ?? 'Pattern')) . '</div>';
        $html .= '<img class="dt-order-pattern-image" src="' . dt_prepare_image_src((string)$data['pattern_data']) . '" alt="pattern">';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

function dt_section_receipt(array $data, array $dict, string $lang): string
{
    $no = $dict['no_data'];
    $ps = trim((string)($data['payment_status'] ?? ''));
    $pm = trim((string)($data['payment_method'] ?? ''));
    $pd = trim((string)($data['payment_date'] ?? ''));
    $paymentStatusLabel = $ps === '' ? $no : dt_receipt_payment_status_label($ps, $lang);
    $paymentMethodLabel = $pm === '' ? $no : dt_payment_method_label($pm, $lang);
    $paymentDateLabel = $pd === '' ? $no : dt_format_date($pd, $lang);
    $statusLabel = isset($data['status']) ? (string)($dict['values']['status'][$data['status']] ?? $data['status']) : $no;
    $formattedTotal = dt_format_currency($data['total_amount'] ?? 0, $lang);
    $uiText = [
        'ru' => [
            'client_title' => 'Клиент',
            'phone' => 'Тел',
            'email' => 'Email',
            'goods' => 'Товары',
            'labor' => 'Работа',
            'total' => 'Итого',
            'paid' => 'Оплачено',
            'vat_note' => 'ALV 0% (small business)',
            'repair_service' => 'Работа (услуга ремонта)',
        ],
        'en' => [
            'client_title' => 'Client',
            'phone' => 'Phone',
            'email' => 'Email',
            'goods' => 'Goods',
            'labor' => 'Repair service',
            'total' => 'Total',
            'paid' => 'Paid',
            'vat_note' => 'VAT 0% (not VAT registered)',
            'repair_service' => 'Repair service',
        ],
        'fi' => [
            'client_title' => 'Asiakas',
            'phone' => 'Puh',
            'email' => 'Email',
            'goods' => 'Tuotteet',
            'labor' => 'Korjaustyo',
            'total' => 'Yhteensa',
            'paid' => 'Maksettu',
            'vat_note' => 'ALV 0% (small business)',
            'repair_service' => 'Korjaustyo',
        ],
    ];
    $txt = $uiText[$lang] ?? $uiText['en'];
    $extractWarrantyBlock = static function (string $text): array {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return ['', ''];
        }
        $markers = [
            '🛠️ Условия / гарантия',
            'Условия / гарантия',
            'Гарантия 30 дней',
            '🛠️ Terms / warranty',
            'Terms / warranty',
            '30-day warranty on completed work',
            '🛠️ Ehdot / takuu',
            'Ehdot / takuu',
            '30 päivän takuu suoritetulle työlle',
        ];
        $pos = null;
        foreach ($markers as $marker) {
            $p = stripos($text, $marker);
            if ($p === false) {
                continue;
            }
            if ($pos === null || $p < $pos) {
                $pos = $p;
            }
        }
        if ($pos === null) {
            return [$text, ''];
        }

        $before = trim(substr($text, 0, $pos));
        $warranty = trim(substr($text, $pos));

        return [$before, $warranty];
    };
    $normalizeReceiptService = static function (string $name, string $description) use ($txt): array {
        $name = trim($name);
        $description = trim($description);
        $nameLower = mb_strtolower($name, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $isRepairService = $nameLower === 'repair service'
            || str_contains($nameLower, 'repair service')
            || str_contains($nameLower, 'услуга ремонта')
            || str_contains($descLower, 'ориентировочная стоимость работы')
            || str_contains($descLower, 'estimated labor')
            || str_contains($descLower, 'estimated work');
        if ($isRepairService) {
            $name = $txt['repair_service'];
            $description = '';
        }

        return [$name, $description, $isRepairService];
    };

    $rawServices = $data['services_rendered'] ?? null;
    $serviceRows = [];
    if (is_array($rawServices)) {
        foreach ($rawServices as $item) {
            if (!is_array($item)) {
                continue;
            }
            $serviceRows[] = [
                'name' => trim((string)($item['name'] ?? '')),
                'description' => trim((string)($item['description'] ?? '')),
                'qty' => 1.0,
                'price' => isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : null,
            ];
        }
    } else {
        $serviceText = trim((string)$rawServices);
        if ($serviceText !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $serviceText) ?: [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                $price = null;
                if (preg_match('/^(.*?)(?:\s*-\s*|\s+)([0-9]+(?:[.,][0-9]+)?)\s*(?:€|eur)?$/iu', $line, $m)) {
                    $line = trim((string)$m[1]);
                    $price = (float)str_replace(',', '.', (string)$m[2]);
                }
                $name = $line;
                $description = '';
                if (preg_match('/^(.*?)\s*\((.*?)\)\s*$/u', $line, $parts)) {
                    $name = trim((string)$parts[1]);
                    $description = trim((string)$parts[2]);
                }
                $serviceRows[] = [
                    'name' => $name,
                    'description' => $description,
                    'qty' => 1.0,
                    'price' => $price,
                ];
            }
        }
    }

    if ($serviceRows === []) {
        $serviceRows[] = [
            'name' => trim((string)($data['device_model'] ?? '')) !== '' ? trim((string)$data['device_model']) : $no,
            'description' => '',
            'qty' => 1.0,
            'price' => isset($data['total_amount']) ? (float)$data['total_amount'] : null,
        ];
    }
    if (count($serviceRows) === 1 && $serviceRows[0]['price'] === null && isset($data['total_amount'])) {
        $serviceRows[0]['price'] = (float)$data['total_amount'];
    }
    foreach ($serviceRows as &$row) {
        [$name, $description, $isRepairService] = $normalizeReceiptService(
            (string)($row['name'] ?? ''),
            (string)($row['description'] ?? '')
        );
        $row['name'] = $name;
        $row['description'] = $description;
        $row['is_repair_service'] = $isRepairService;
    }
    unset($row);

    $heroTitle = trim((string)($data['device_model'] ?? ''));
    if ($heroTitle === '') {
        $heroTitle = trim((string)($serviceRows[0]['name'] ?? ''));
    }
    if ($heroTitle === '') {
        $heroTitle = $no;
    }

    $heroDesc = '';
    foreach ($serviceRows as $row) {
        $desc = trim((string)($row['description'] ?? ''));
        if ($desc !== '') {
            $heroDesc = $desc;
            break;
        }
    }

    $clientName = trim((string)($data['client_name'] ?? ''));
    $clientPhone = trim((string)($data['client_phone'] ?? ''));
    $clientEmail = trim((string)($data['client_email'] ?? ''));
    $customerName = $clientName !== '' ? dt_sanitize($clientName) : $no;
    $customerPhone = $clientPhone !== '' ? dt_sanitize($clientPhone) : '';
    $customerEmail = $clientEmail !== '' ? dt_sanitize($clientEmail) : '';

    $typeRaw = trim((string)($data['order_type'] ?? $data['order_mode'] ?? ''));
    if ($typeRaw !== '') {
        $typeMap = [
            'ru' => ['repair' => 'Ремонт', 'sale' => 'Продажа', 'custom' => 'Нестандарт'],
            'en' => ['repair' => 'Repair', 'sale' => 'Sale', 'custom' => 'Custom'],
            'fi' => ['repair' => 'Korjaus', 'sale' => 'Myynti', 'custom' => 'Muu'],
        ];
        $typeRaw = $typeMap[$lang][strtolower($typeRaw)] ?? $typeRaw;
    }
    $typeValue = $typeRaw !== '' ? $typeRaw : '';
    $acceptDate = trim((string)($data['date_of_acceptance'] ?? ''));
    $acceptDate = $acceptDate !== '' ? dt_format_date($acceptDate, $lang) : '';
    $place = trim((string)($data['place_of_acceptance'] ?? ''));
    $place = $place !== '' ? $place : '';

    $paymentNote = trim((string)($data['payment_note'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));
    [$notesWithoutWarranty, $warrantyText] = $extractWarrantyBlock($notes);
    $commentBits = [];
    if ($paymentNote !== '') { $commentBits[] = $paymentNote; }
    if ($notesWithoutWarranty !== '') { $commentBits[] = $notesWithoutWarranty; }
    $commentText = trim(implode("\n", $commentBits));
    $totalAmount = isset($data['total_amount']) && is_numeric($data['total_amount']) ? (float)$data['total_amount'] : 0.0;

    $html = '<div class="dt-receipt-hero">';
    $html .= '<div class="dt-receipt-hero-left">';
    $html .= '<div class="dt-receipt-hero-title">' . dt_sanitize($heroTitle, $no) . '</div>';
    if ($heroDesc !== '') {
        $html .= '<div class="dt-receipt-hero-desc">' . dt_sanitize($heroDesc, '') . '</div>';
    }
    if ($warrantyText !== '') {
        $warrantyCompact = preg_replace('/\s+/u', ' ', $warrantyText) ?? $warrantyText;
        $html .= '<div class="dt-receipt-hero-note">' . dt_sanitize($warrantyCompact, '') . '</div>';
    }
    $html .= '</div>';
    $html .= '<div class="dt-receipt-hero-right">';
    $html .= '<div class="dt-receipt-hero-amount">' . dt_sanitize($formattedTotal, $no) . '</div>';
    $html .= '<div class="dt-receipt-status-pill">' . dt_sanitize($paymentStatusLabel, $no) . '</div>';
    $html .= '</div></div>';

    $html .= '<div class="dt-receipt-card dt-receipt-customer-card">';
    $html .= '<div class="dt-receipt-customer-line"><span class="dt-receipt-inline-label">' . dt_sanitize($txt['client_title']) . ':</span> ' . $customerName . '</div>';
    if ($customerPhone !== '') {
        $html .= '<div class="dt-receipt-customer-line"><span class="dt-receipt-inline-label">' . dt_sanitize($txt['phone']) . ':</span> ' . $customerPhone . '</div>';
    }
    if ($customerEmail !== '') {
        $html .= '<div class="dt-receipt-customer-line"><span class="dt-receipt-inline-label">' . dt_sanitize($txt['email']) . ':</span> ' . $customerEmail . '</div>';
    }
    $html .= '</div>';

    $html .= '<div class="dt-receipt-card"><table class="dt-receipt-detail-table"><tr>';
    $html .= '<td class="dt-receipt-detail-cell">';
    if ($acceptDate !== '') {
        $html .= dt_receipt_detail_row((string)($dict['labels']['accept_date'] ?? 'Date'), dt_sanitize($acceptDate, $no));
    }
    if ($place !== '') {
        $html .= dt_receipt_detail_row((string)($dict['labels']['place'] ?? 'Place'), dt_sanitize($place, $no));
    }
    if ($typeValue !== '') {
        $html .= dt_receipt_detail_row((string)($dict['labels']['service_object'] ?? 'Type'), dt_sanitize($typeValue, $no));
    }
    $html .= '</td>';
    $html .= '<td class="dt-receipt-detail-cell">';
    if ($pm !== '') {
        $html .= dt_receipt_detail_row((string)($dict['labels']['payment_method'] ?? 'Payment method'), dt_sanitize($paymentMethodLabel, $no));
    }
    if ($pd !== '') {
        $html .= dt_receipt_detail_row((string)($dict['labels']['payment_date'] ?? 'Payment date'), dt_sanitize($paymentDateLabel, $no));
    }
    if (dt_has_meaningful_value($statusLabel)) {
        $html .= dt_receipt_detail_row((string)($dict['labels']['status'] ?? 'Status'), dt_sanitize($statusLabel, $no));
    }
    if (strtolower($ps) === 'partial' && isset($data['amount_paid']) && is_numeric($data['amount_paid'])) {
        $html .= dt_receipt_detail_row((string)($dict['labels']['amount_paid'] ?? 'Paid'), dt_sanitize(dt_format_currency((float)$data['amount_paid'], $lang), $no));
    }
    $html .= '</td></tr></table></div>';

    $html .= '<div class="dt-receipt-card">';
    $html .= '<div class="dt-receipt-section-title">' . dt_sanitize((string)($dict['sections']['services'] ?? 'Services')) . '</div>';
    $html .= '<table class="dt-receipt-table"><thead><tr>';
    $html .= '<th>' . dt_sanitize((string)($dict['labels']['services_rendered'] ?? 'Service')) . '</th>';
    $html .= '<th class="dt-receipt-num">Qty</th>';
    $html .= '<th class="dt-receipt-num">' . dt_sanitize((string)($dict['labels']['total_amount'] ?? 'Price')) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($serviceRows as $row) {
        $name = trim((string)($row['name'] ?? '')) !== '' ? (string)$row['name'] : $no;
        $desc = trim((string)($row['description'] ?? ''));
        $qty = (float)($row['qty'] ?? 1);
        $price = $row['price'];
        $html .= '<tr>';
        $html .= '<td><div class="dt-receipt-line-name">' . dt_sanitize($name, $no) . '</div>';
        if ($desc !== '') {
            $html .= '<div class="dt-receipt-line-desc">' . dt_sanitize($desc, '') . '</div>';
        }
        $html .= '</td>';
        $html .= '<td class="dt-receipt-num">' . dt_sanitize(rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'), '1') . '</td>';
        $html .= '<td class="dt-receipt-num">' . dt_sanitize($price === null ? '—' : dt_format_currency((float)$price, $lang), $no) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    $html .= '<div class="dt-receipt-card dt-receipt-totals-card">';
    $html .= dt_receipt_detail_row($txt['total'], dt_sanitize(dt_format_currency($totalAmount, $lang), $no));
    $html .= '<div class="dt-receipt-vat-note">' . dt_sanitize($txt['vat_note']) . '</div>';
    $html .= '</div>';

    if ($commentText !== '') {
        $html .= '<div class="dt-receipt-card">';
        $html .= '<div class="dt-receipt-section-title">' . dt_sanitize((string)($dict['labels']['notes'] ?? 'Notes')) . '</div>';
        $html .= '<div class="dt-receipt-comment">' . nl2br(dt_sanitize($commentText, '')) . '</div>';
        $html .= '</div>';
    }

    return $html;
}

function dt_receipt_detail_row(string $label, string $value): string
{
    return '<div class="dt-receipt-detail-row"><span class="dt-receipt-detail-label">' . dt_sanitize($label) . '</span><span class="dt-receipt-detail-value">' . $value . '</span></div>';
}

function dt_section_receipt_signature(array $data, array $dict): string
{
    $html = '<div class="dt-receipt-signature-wrap">';
    $html .= '<div class="dt-receipt-signature-main">';
    if (!empty($data['client_signature'])) {
        $html .= '<img class="dt-receipt-signature-image" src="' . dt_prepare_image_src((string)$data['client_signature']) . '" alt="signature">';
    }
    $html .= '<div class="dt-receipt-signature-inline"><span class="dt-receipt-signature-text">' . dt_sanitize((string)($dict['labels']['receipt_signature'] ?? $dict['labels']['signature'] ?? 'Signature')) . ':</span><span class="dt-receipt-signature-line"></span></div>';
    $html .= '</div>';
    if (!empty($data['pattern_data'])) {
        $html .= '<div class="dt-receipt-pattern-mini">';
        $html .= '<div class="dt-receipt-pattern-label">' . dt_sanitize((string)($dict['labels']['pattern'] ?? 'Pattern')) . '</div>';
        $html .= '<img class="dt-receipt-pattern-image" src="' . dt_prepare_image_src((string)$data['pattern_data']) . '" alt="pattern">';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

function dt_report_format_mixed_for_pdf($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? '✓' : '—';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_string($value)) {
        $t = trim($value);

        return $t !== '' ? $t : '—';
    }
    if (is_array($value)) {
        if ($value === []) {
            return '—';
        }
        $keys = array_keys($value);
        $isList = $keys === range(0, count($value) - 1);
        if ($isList) {
            return implode(', ', array_map(static function ($v) {
                return is_scalar($v) || $v === null ? (string) $v : (json_encode($v, JSON_UNESCAPED_UNICODE) ?: '—');
            }, $value));
        }
        $parts = [];
        foreach ($value as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $parts[] = $k . ': ' . (is_scalar($v) || $v === null ? (string) $v : (json_encode($v, JSON_UNESCAPED_UNICODE) ?: '—'));
        }

        return implode('; ', $parts);
    }

    return '—';
}

/**
 * @param array<string, mixed> $data
 * @param list<string> $keys
 * @return mixed|null
 */
function dt_report_pick(array $data, array $keys)
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $data)) {
            continue;
        }
        $v = $data[$k];
        if ($v === null || $v === '') {
            continue;
        }
        if (is_string($v) && trim($v) === '') {
            continue;
        }

        return $v;
    }

    return null;
}

function dt_report_type_label(string $rt, string $lang): string
{
    $r = strtolower(trim($rt));
    $map = [
        'ru' => ['mobile' => 'Мобильная диагностика', 'pc' => 'ПК / ноутбук'],
        'en' => ['mobile' => 'Mobile diagnostics', 'pc' => 'PC / laptop'],
        'fi' => ['mobile' => 'Mobiilidiagnostiikka', 'pc' => 'Tietokone'],
    ];
    $l = in_array($lang, ['ru', 'en', 'fi'], true) ? $lang : 'en';

    return $map[$l][$r] ?? $rt;
}

function dt_section_report(array $data, array $dict, string $lang): string
{
    $no = $dict['no_data'];
    $L = $dict['labels'];
    $S = $dict['sections'];

    $html = '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['customer']) . '</div>';
    $html .= dt_render_field($L['client_name'], dt_sanitize($data['client_name'] ?? $no, $no));
    $html .= dt_render_field($L['client_phone'], dt_sanitize($data['client_phone'] ?? $no, $no));
    $html .= dt_render_field($L['client_email'], dt_sanitize($data['client_email'] ?? $no, $no));
    $html .= '</div>';

    $html .= '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['device']) . '</div>';
    $html .= dt_render_field($L['device_model'], dt_sanitize($data['device_model'] ?? $no, $no));
    $html .= dt_render_field($L['device_type'], dt_sanitize($data['device_type'] ?? $no, $no));
    $html .= dt_render_field($L['device_serial'], dt_sanitize($data['device_serial'] ?? $no, $no));
    $html .= '</div>';

    $rt = trim((string) (dt_report_pick($data, ['reportType', 'report_type']) ?? ''));
    $dr = (int) (dt_report_pick($data, ['deviceRating', 'device_rating']) ?? 0);
    $cr = (int) (dt_report_pick($data, ['conditionRating', 'condition_rating']) ?? 0);
    $ct = dt_report_pick($data, ['componentTests', 'component_tests']);
    $cl = dt_report_pick($data, ['cleaning']);
    $hasMetrics = $rt !== '' || $dr > 0 || $cr > 0
        || ($ct !== null && trim((string) $ct) !== '')
        || (is_array($cl) && $cl !== []);

    if ($hasMetrics) {
        $html .= '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['report_metrics'] ?? $S['diagnosis']) . '</div>';
        if ($rt !== '') {
            $html .= dt_render_field($L['report_type'], dt_sanitize(dt_report_type_label($rt, $lang)));
        }
        if ($dr > 0) {
            $html .= dt_render_field($L['report_device_rating'], dt_sanitize((string) $dr . '/10'));
        }
        if ($cr > 0) {
            $html .= dt_render_field($L['report_condition_rating'], dt_sanitize((string) $cr . '/10'));
        }
        if ($ct !== null && trim((string) $ct) !== '') {
            $html .= dt_render_field($L['report_component_tests'], nl2br(dt_sanitize((string) $ct)));
        }
        if (is_array($cl) && $cl !== []) {
            $html .= dt_render_field($L['report_cleaning'], dt_sanitize(dt_report_format_mixed_for_pdf($cl)));
        }
        $html .= '</div>';
    }

    $batCap = dt_report_pick($data, ['batteryCapacity', 'battery_capacity']);
    $batSt = dt_report_pick($data, ['batteryStatus', 'battery_status']);
    $batRep = dt_report_pick($data, ['batteryReplacement', 'battery_replacement']);
    $batNotes = dt_report_pick($data, ['batteryNotes', 'battery_notes']);
    $curCap = dt_report_pick($data, ['currentCapacity', 'current_capacity']);
    $wear = dt_report_pick($data, ['wearLevel', 'wear_level']);
    $cpu = dt_report_pick($data, ['cpuTemp', 'cpu_temp']);
    $gpu = dt_report_pick($data, ['gpuTemp', 'gpu_temp']);
    $disk = dt_report_pick($data, ['diskTemp', 'disk_temp']);
    $amb = dt_report_pick($data, ['ambientTemp', 'ambient_temp']);
    $hasBat = $batCap !== null || $batSt !== null || $batRep !== null || $batNotes !== null
        || $curCap !== null || $wear !== null || $cpu !== null || $gpu !== null || $disk !== null || $amb !== null;

    if ($hasBat) {
        $html .= '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['report_battery'] ?? $S['device']) . '</div>';
        if ($batCap !== null) {
            $html .= dt_render_field($L['report_battery_capacity'], dt_sanitize(dt_report_format_mixed_for_pdf($batCap)));
        }
        if ($batSt !== null) {
            $html .= dt_render_field($L['report_battery_status'], dt_sanitize(dt_report_format_mixed_for_pdf($batSt)));
        }
        if ($batRep !== null) {
            $html .= dt_render_field($L['report_battery_replace'], dt_sanitize(dt_report_format_mixed_for_pdf($batRep)));
        }
        if ($batNotes !== null) {
            $html .= dt_render_field($L['report_battery_notes'], nl2br(dt_sanitize((string) $batNotes)));
        }
        if ($curCap !== null) {
            $html .= dt_render_field($L['report_current_capacity'], dt_sanitize(dt_report_format_mixed_for_pdf($curCap)));
        }
        if ($wear !== null) {
            $html .= dt_render_field($L['report_wear_level'], dt_sanitize(dt_report_format_mixed_for_pdf($wear)));
        }
        if ($cpu !== null) {
            $html .= dt_render_field($L['report_temp_cpu'], dt_sanitize(dt_report_format_mixed_for_pdf($cpu)));
        }
        if ($gpu !== null) {
            $html .= dt_render_field($L['report_temp_gpu'], dt_sanitize(dt_report_format_mixed_for_pdf($gpu)));
        }
        if ($disk !== null) {
            $html .= dt_render_field($L['report_temp_disk'], dt_sanitize(dt_report_format_mixed_for_pdf($disk)));
        }
        if ($amb !== null) {
            $html .= dt_render_field($L['report_temp_ambient'], dt_sanitize(dt_report_format_mixed_for_pdf($amb)));
        }
        $html .= '</div>';
    }

    $svc = $data['services'] ?? null;
    $isPc = strtolower(trim($rt)) === 'pc';
    if (!$isPc && is_array($svc) && $svc !== []) {
        $isPc = true;
    }
    if ($isPc && is_array($svc) && $svc !== []) {
        $html .= '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['report_software'] ?? $S['services']) . '</div>';
        $html .= '<div class="dt-paragraph">' . nl2br(dt_sanitize(dt_report_format_mixed_for_pdf($svc))) . '</div></div>';
    }

    if (!empty($data['diagnosis'])) {
        $html .= '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['diagnosis']) . '</div>';
        $html .= '<div class="dt-paragraph">' . nl2br(dt_sanitize($data['diagnosis'])) . '</div></div>';
    }

    if (!empty($data['recommendations'])) {
        $html .= '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['recommendations']) . '</div>';
        $html .= '<div class="dt-paragraph">' . nl2br(dt_sanitize($data['recommendations'])) . '</div></div>';
    }

    $html .= '<div class="dt-section"><div class="dt-section-title">' . dt_sanitize($S['additional']) . '</div>';
    if (isset($data['repair_cost'])) {
        $html .= dt_render_field($L['repair_cost'], dt_sanitize(dt_format_currency($data['repair_cost'], $lang)));
    }
    if (!empty($data['repair_time'])) {
        $html .= dt_render_field($L['repair_time'], dt_sanitize((string) $data['repair_time']));
    }
    if (isset($data['warranty'])) {
        $warranty = $dict['values']['warranty'][(string) $data['warranty']] ?? dt_boolean_label($data['warranty'], $dict);
        $html .= dt_render_field($L['warranty'], dt_sanitize($warranty));
    }
    if (!empty($data['technician_name'])) {
        $html .= dt_render_field($L['technician'], dt_sanitize($data['technician_name']));
    }
    if (!empty($data['work_date'])) {
        $html .= dt_render_field($L['work_date'], dt_sanitize(dt_format_date($data['work_date'], $lang)));
    }
    if (!empty($data['unique_code'])) {
        $html .= dt_render_field($L['unique_code'], dt_sanitize($data['unique_code']));
    }
    $html .= '</div>';

    $skipExtra = [
        'document_id', 'client_name', 'client_phone', 'client_email', 'device_model', 'device_serial', 'device_type',
        'diagnosis', 'recommendations', 'repair_cost', 'repair_time', 'warranty', 'technician_name', 'work_date',
        'unique_code', 'place_of_acceptance', 'date_of_acceptance', 'date_created', 'date_updated', 'language',
        'status', 'priority', 'problem_description', 'device_password', 'device_condition', 'accessories',
        'pattern_data', 'client_signature', 'services_rendered', 'notes', 'contact_phone', 'contact_email',
        'reportType', 'report_type', 'deviceRating', 'device_rating', 'conditionRating', 'condition_rating',
        'componentTests', 'component_tests', 'cleaning', 'batteryCapacity', 'battery_capacity', 'batteryStatus',
        'battery_status', 'batteryReplacement', 'battery_replacement', 'batteryNotes', 'battery_notes',
        'currentCapacity', 'current_capacity', 'wearLevel', 'wear_level', 'cpuTemp', 'cpu_temp', 'gpuTemp',
        'gpu_temp', 'diskTemp', 'disk_temp', 'ambientTemp', 'ambient_temp', 'documentId', 'orderId', 'order_id',
        'services', 'raw_json', 'token', 'report_id', 'created_at',
        'clientName', 'clientPhone', 'clientEmail', 'deviceModel', 'deviceSerial', 'deviceType',
        'technicianName', 'workDate', 'batteryReplacement',
    ];
    $extraOut = '';
    foreach ($data as $key => $val) {
        if (!is_string($key) || in_array($key, $skipExtra, true)) {
            continue;
        }
        if ($val === null || $val === '' || (is_array($val) && $val === [])) {
            continue;
        }
        $txt = dt_report_format_mixed_for_pdf($val);
        if ($txt === '—') {
            continue;
        }
        $label = $key;
        $extraOut .= dt_render_field($label, dt_sanitize($txt));
    }
    if ($extraOut !== '') {
        $html .= '<div class="dt-section dt-report-extra"><div class="dt-section-title">' . dt_sanitize($S['report_extra'] ?? $S['additional']) . '</div>' . $extraOut . '</div>';
    }

    return $html;
}

/**
 * Отображение ставки НДС в подписях итогов (без усреднения).
 */
function dt_invoice_format_rate_for_display(float $rate, string $lang): string
{
    if (abs($rate) < 0.0001) {
        return $lang === 'en' ? '0%' : '0 %';
    }
    if ($lang === 'fi' || $lang === 'ru') {
        return number_format($rate, 1, ',', '') . ' %';
    }
    $s = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

    return $s . '%';
}

function dt_invoice_totals_table_open(): string
{
    return '<table class="dt-inv-totals"><tbody>';
}

function dt_invoice_totals_table_close(): string
{
    return '</tbody></table>';
}

/**
 * @param string $label Already safe for HTML (dict / sprintf labels)
 * @param string $value Already escaped (dt_sanitize)
 */
function dt_invoice_totals_table_row(string $label, string $value, bool $isGrand = false): string
{
    $trClass = $isGrand ? 'dt-inv-totals-tr dt-grand' : 'dt-inv-totals-tr';

    return '<tr class="' . $trClass . '"><td class="dt-inv-totals-label">' . $label . '</td><td class="dt-inv-totals-val">' . $value . '</td></tr>';
}

/**
 * Финальные итоги счёта: строки «нетто по ставке», «НДС по ставке», «всего» (без дублирующей VAT-таблицы).
 *
 * @param list<array{rate: float, base: float, tax: float}> $groups
 */
function dt_invoice_render_totals_breakdown_html(array $groups, array $dict, string $lang, float $grandTotal): string
{
    $no = $dict['no_data'];
    $fmtNet = (string)($dict['labels']['fmt_vat_net'] ?? '%s');
    $fmtTax = (string)($dict['labels']['fmt_vat_tax'] ?? '%s');
    $lblGrand = (string)($dict['labels']['grand_total'] ?? $dict['labels']['total_amount'] ?? 'Total');

    $html = '<div class="dt-inv-totals-fallback-wrap">';
    $html .= dt_invoice_totals_table_open();
    $onlyZero = count($groups) === 1 && abs((float)($groups[0]['rate'] ?? 0)) < 0.0001;

    if ($onlyZero) {
        $g = $groups[0];
        $rateStr = dt_invoice_format_rate_for_display(0.0, $lang);
        $html .= dt_invoice_totals_table_row(sprintf($fmtNet, $rateStr), dt_sanitize(dt_format_currency($g['base'], $lang), $no));
        $html .= dt_invoice_totals_table_row($lblGrand, dt_sanitize(dt_format_currency($grandTotal, $lang), $no), true);
        $html .= dt_invoice_totals_table_close();

        return $html . '</div>';
    }

    foreach ($groups as $g) {
        $rateStr = dt_invoice_format_rate_for_display((float)$g['rate'], $lang);
        $html .= dt_invoice_totals_table_row(sprintf($fmtNet, $rateStr), dt_sanitize(dt_format_currency($g['base'], $lang), $no));
    }
    foreach ($groups as $g) {
        if ((float)$g['tax'] > 0.000001) {
            $rateStr = dt_invoice_format_rate_for_display((float)$g['rate'], $lang);
            $html .= dt_invoice_totals_table_row(sprintf($fmtTax, $rateStr), dt_sanitize(dt_format_currency($g['tax'], $lang), $no));
        }
    }
    $html .= dt_invoice_totals_table_row($lblGrand, dt_sanitize(dt_format_currency($grandTotal, $lang), $no), true);
    $html .= dt_invoice_totals_table_close();

    return $html . '</div>';
}

/**
 * @param array<string,mixed> $profile Профиль компании (для блока YRITYKSEN TIEDOT рядом с LASKUN TIEDOT).
 */
function dt_section_invoice(array $data, array $dict, string $lang, array $profile = []): string
{
    $no = $dict['no_data'];
    $items = $data['items'] ?? [];
    if (!is_array($items) || $items === []) {
        $raw = $data['items_json'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $dec = json_decode($raw, true);
            $items = is_array($dec) ? $dec : [];
        }
    }

    $custLabel = (string)($dict['labels']['customer'] ?? $dict['labels']['client_name'] ?? 'Customer');

    $html = '<table class="dt-inv-layout-2col" width="100%" cellpadding="0" cellspacing="0"><tr>';
    $html .= '<td class="dt-inv-layout-td dt-inv-layout-td--left" width="50%" valign="top">';
    $html .= '<div class="dt-section dt-inv-card dt-inv-card--company">';
    if (!empty($dict['sections']['invoice_company'])) {
        $html .= '<div class="dt-section-title dt-company-section-title">' . dt_sanitize($dict['sections']['invoice_company']) . '</div>';
    }
    $html .= dt_build_company_block_html($profile, $data, $dict, true);
    $html .= '</div></td>';
    $html .= '<td class="dt-inv-layout-td dt-inv-layout-td--right" width="50%" valign="top">';
    $html .= '<div class="dt-section dt-inv-card dt-inv-card--details"><div class="dt-section-title">' . dt_sanitize($dict['sections']['invoice_details']) . '</div>';
    $html .= dt_render_field($dict['labels']['invoice_id'], dt_sanitize((string)($data['invoice_id'] ?? $data['document_id'] ?? $no), $no));
    $html .= dt_render_field($dict['labels']['invoice_date'], dt_sanitize(dt_format_date($data['date_created'] ?? null, $lang, true), $no));
    if (!empty($data['date_updated'])) {
        $html .= dt_render_field($dict['labels']['updated_at'], dt_sanitize(dt_format_date($data['date_updated'], $lang, true), $no));
    }
    $html .= dt_render_field($dict['labels']['due_date'], dt_sanitize(dt_format_date($data['due_date'] ?? null, $lang), $no));
    $html .= dt_render_field($dict['labels']['payment_terms'], dt_sanitize($data['payment_terms'] ?? $no, $no));
    $so = trim((string)($data['service_object'] ?? ''));
    if ($so !== '') {
        $html .= dt_render_field($dict['labels']['service_object'], dt_sanitize($so));
    }
    $sAddr = trim((string)($data['service_address'] ?? ''));
    if ($sAddr !== '') {
        $html .= dt_render_field($dict['labels']['service_address'] ?? '—', dt_sanitize($sAddr));
    }
    $html .= '</div></td></tr></table>';

    $payTitle = (string)($dict['sections']['invoice_payment'] ?? 'Payment');
    $pm = trim((string)($data['payment_method'] ?? ''));
    $pd = trim((string)($data['payment_date'] ?? ''));
    $custTitle = (string)($dict['sections']['invoice_customer'] ?? 'Customer');

    $html .= '<table class="dt-inv-layout-2col dt-inv-layout-2col--mid" width="100%" cellpadding="0" cellspacing="0"><tr>';
    $html .= '<td class="dt-inv-layout-td dt-inv-layout-td--left" width="50%" valign="top">';
    $html .= '<div class="dt-section dt-inv-card dt-inv-card--payment"><div class="dt-section-title">' . dt_sanitize($payTitle) . '</div>';
    $html .= '<table class="dt-inv-pay-grid" width="100%" cellpadding="0" cellspacing="0">';
    $html .= '<tr>';
    $html .= '<td class="dt-inv-pay-label">' . dt_sanitize((string)$dict['labels']['status']) . '</td>';
    $html .= '<td class="dt-inv-pay-label">' . dt_sanitize((string)$dict['labels']['payment_method']) . '</td>';
    $html .= '</tr><tr>';
    $html .= '<td class="dt-inv-pay-val">' . dt_sanitize(dt_invoice_status_label((string)($data['status'] ?? ''), $dict), $no) . '</td>';
    $html .= '<td class="dt-inv-pay-val">' . dt_sanitize($pm === '' ? $no : dt_payment_method_label($pm, $lang), $no) . '</td>';
    $html .= '</tr>';
    if ($pd !== '') {
        $html .= '<tr><td class="dt-inv-pay-label dt-inv-pay-label--full" colspan="2">' . dt_sanitize((string)$dict['labels']['payment_date']) . '</td></tr>';
        $html .= '<tr><td class="dt-inv-pay-val dt-inv-pay-val--full" colspan="2">' . dt_sanitize(dt_format_date($pd, $lang), $no) . '</td></tr>';
    }
    $html .= '</table></div></td>';
    $html .= '<td class="dt-inv-layout-td dt-inv-layout-td--right" width="50%" valign="top">';
    $html .= '<div class="dt-section dt-inv-card dt-inv-card--customer"><div class="dt-section-title">' . dt_sanitize($custTitle) . '</div>';
    $html .= '<table class="dt-inv-cust-grid" width="100%" cellpadding="0" cellspacing="0">';
    $html .= '<tr>';
    $html .= '<th class="dt-inv-cust-h">' . dt_sanitize($custLabel) . '</th>';
    $html .= '<th class="dt-inv-cust-h">' . dt_sanitize((string)$dict['labels']['client_phone']) . '</th>';
    $html .= '<th class="dt-inv-cust-h">' . dt_sanitize((string)$dict['labels']['client_email']) . '</th>';
    $html .= '</tr><tr>';
    $html .= '<td class="dt-inv-cust-v">' . dt_sanitize($data['client_name'] ?? $no, $no) . '</td>';
    $html .= '<td class="dt-inv-cust-v">' . dt_sanitize($data['client_phone'] ?? $no, $no) . '</td>';
    $html .= '<td class="dt-inv-cust-v">' . dt_sanitize($data['client_email'] ?? $no, $no) . '</td>';
    $html .= '</tr></table></div></td></tr></table>';

    $html .= '<div class="dt-section dt-inv-card dt-inv-card--items"><div class="dt-section-title">' . dt_sanitize($dict['sections']['invoice_items']) . '</div>';
    $displayMode = strtolower(trim((string)($data['display_mode'] ?? $data['displayMode'] ?? 'detailed')));
    if ($displayMode === '') {
        $displayMode = 'detailed';
    }
    $tableRows = [];
    if (is_array($items) && $items !== []) {
        if ($displayMode === 'summary') {
            $tableRows = fixarivan_invoice_summary_display_rows($items, $dict);
        } else {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $nm = (string)($row['name'] ?? $row['description'] ?? '');
                $qty = (float)($row['qty'] ?? $row['quantity'] ?? 0);
                $price = (float)($row['price'] ?? 0);
                $vatp = (float)($row['vat'] ?? $row['tax_rate'] ?? 0);
                $base = $qty * $price;
                $tax = $base * ($vatp / 100);
                $sum = $base + $tax;
                $tableRows[] = [
                    'name' => $nm !== '' ? $nm : '—',
                    'qty' => $qty,
                    'price' => $price,
                    'vat' => $vatp,
                    'line_total' => $sum,
                ];
            }
        }
    }
    if ($tableRows !== []) {
        $html .= '<table class="dt-inv-table dt-inv-line-table"><thead><tr>';
        $html .= '<th>' . dt_sanitize($dict['labels']['col_name']) . '</th>';
        $html .= '<th class="dt-num">' . dt_sanitize($dict['labels']['col_qty']) . '</th>';
        $html .= '<th class="dt-num">' . dt_sanitize($dict['labels']['col_price']) . '</th>';
        $html .= '<th class="dt-num">' . dt_sanitize($dict['labels']['col_vat']) . '</th>';
        $html .= '<th class="dt-num">' . dt_sanitize($dict['labels']['col_sum']) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($tableRows as $row) {
            $nm = (string)($row['name'] ?? '');
            $qty = (float)($row['qty'] ?? 0);
            $price = (float)($row['price'] ?? 0);
            $vatp = (float)($row['vat'] ?? 0);
            $sum = isset($row['line_total']) ? (float)$row['line_total'] : ($qty * $price) * (1 + $vatp / 100);
            $html .= '<tr>';
            $html .= '<td>' . dt_sanitize($nm !== '' ? $nm : '—') . '</td>';
            $html .= '<td class="dt-num">' . dt_sanitize((string)$qty) . '</td>';
            $html .= '<td class="dt-num">' . dt_sanitize(dt_format_currency($price, $lang)) . '</td>';
            $html .= '<td class="dt-num">' . dt_sanitize(dt_invoice_format_rate_for_display($vatp, $lang)) . '</td>';
            $html .= '<td class="dt-num">' . dt_sanitize(dt_format_currency($sum, $lang)) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<div class="dt-paragraph">' . dt_sanitize($no) . '</div>';
    }
    $html .= '</div>';

    $html .= '<div class="dt-section dt-inv-card dt-inv-card--totals">';
    $groups = fixarivan_invoice_vat_groups_by_rate($items);
    if ($groups !== []) {
        $grandTotal = 0.0;
        foreach ($groups as $g) {
            $grandTotal += $g['base'] + $g['tax'];
        }
        $html .= dt_invoice_render_totals_breakdown_html($groups, $dict, $lang, $grandTotal);
    } else {
        $sub = isset($data['subtotal']) ? (float)$data['subtotal'] : null;
        $taxAm = isset($data['tax_amount']) ? (float)$data['tax_amount'] : null;
        $lblGrand = (string)($dict['labels']['grand_total'] ?? $dict['labels']['total_amount'] ?? 'Total');
        $lblVatSum = (string)($dict['labels']['vat_total'] ?? $dict['labels']['tax_amount'] ?? 'VAT');
        $html .= '<div class="dt-inv-totals-fallback-wrap">';
        $html .= dt_invoice_totals_table_open();
        if ($sub !== null) {
            $html .= dt_invoice_totals_table_row((string)$dict['labels']['subtotal_amount'], dt_sanitize(dt_format_currency($sub, $lang), $no));
        }
        if ($taxAm !== null && $taxAm > 0.000001) {
            $html .= dt_invoice_totals_table_row($lblVatSum, dt_sanitize(dt_format_currency($taxAm, $lang), $no));
        }
        $html .= dt_invoice_totals_table_row($lblGrand, dt_sanitize(dt_format_currency($data['total_amount'] ?? 0, $lang), $no), true);
        $html .= dt_invoice_totals_table_close();
        $html .= '</div>';
    }
    $html .= '</div>';

    $noteRaw = trim((string)($data['note'] ?? $data['notes'] ?? ''));
    $notesTitle = (string)($dict['labels']['notes'] ?? 'Notes');
    $html .= '<div class="dt-section dt-inv-card dt-inv-card--notes">';
    $html .= '<div class="dt-section-title">' . dt_sanitize($notesTitle) . '</div>';
    $html .= '<div class="dt-inv-notes-body">';
    if ($noteRaw !== '') {
        $html .= nl2br(dt_sanitize($noteRaw, ''));
    } else {
        $html .= '<span class="dt-inv-note-empty">' . dt_sanitize($no) . '</span>';
    }
    $html .= '</div>';
    $legal = trim((string)($dict['labels']['invoice_legal_note'] ?? ''));
    if ($legal !== '') {
        $html .= '<div class="dt-inv-legal">' . dt_sanitize($legal) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Доп. CSS только для PDF счёта: уже поля A4, плотнее примечания (меньше страниц при длинном note).
 */
function dt_invoice_pdf_extra_css(): string
{
    return <<<CSS
@page { size: A4; margin: 5mm 6mm; }
.dt-document--invoice .dt-inv-pdf-header-inner td { padding: 8px 10px !important; }
.dt-document--invoice .dt-inv-notes-body {
    font-size: 7.5pt !important;
    line-height: 1.2 !important;
    text-align: justify;
}
.dt-document--invoice .dt-inv-legal {
    font-size: 7pt !important;
    line-height: 1.2 !important;
}
.dt-document--invoice .dt-inv-card--notes {
    margin-top: 3px !important;
    padding: 2px 4px 3px 4px !important;
}
.dt-document--invoice .dt-footer {
    page-break-inside: avoid;
    margin-top: 2px;
    padding: 2px 4px;
}
CSS;
}

/** Единый стиль PDF (Dompdf) для актов, счетов, квитанций и отчётов — см. dt_render_document_html. */
function dt_css(): string
{
    return <<<CSS
@page { size: A4; margin: 7mm 8mm; }
html { margin: 0; padding: 0; }
body {
    font-family: 'DejaVu Sans', Arial, sans-serif;
    margin: 0;
    padding: 0;
    background: #fff;
    color: #0f172a;
    font-size: 10.5pt;
    line-height: 1.35;
}
.dt-document {
    background: #ffffff;
    margin: 0;
    max-width: 100%;
    border-radius: 0;
    box-shadow: none;
    overflow: visible;
}
.dt-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #4338ca 100%);
    color: #f8fafc;
    text-align: center;
    padding: 10px 14px;
}
.dt-header-title { font-size: 18px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; line-height: 1.2; }
.dt-header-sub { margin-top: 4px; font-size: 13px; font-weight: 500; opacity: 0.95; line-height: 1.25; }
.dt-company {
    background: #f1f5f9;
    padding: 8px 12px;
    color: #1e293b;
    font-size: 10.5pt;
    display: flex;
    flex-direction: column;
    gap: 2px;
    border-bottom: 1px solid #e2e8f0;
}
.dt-company-section-title { margin: 0 0 6px 0; font-size: 10pt; }
.dt-company-logo-wrap { margin-bottom: 2px; }
.dt-company-logo { max-height: 48px; max-width: 200px; height: auto; width: auto; display: block; }
.dt-invoice-logo { max-height: 64px; max-width: 220px; height: auto; width: auto; display: block; }
.dt-company-title { font-weight: 700; font-size: 11pt; color: #0f172a; }
.dt-k { font-weight: 600; color: #475569; }
.dt-inv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5pt;
    margin-top: 4px;
}
.dt-inv-table thead { display: table-header-group; }
.dt-inv-table th, .dt-inv-table td {
    border: 1px solid #cbd5e1;
    padding: 3px 5px;
    text-align: left;
    vertical-align: top;
}
.dt-inv-table th { background: #e8eef5; font-weight: 600; font-size: 9pt; padding: 4px 5px; }
.dt-inv-table tr { page-break-inside: auto; }
.dt-num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.dt-inv-totals {
    width: 100%;
    max-width: 420px;
    margin: 2px 0 6px 0;
    margin-left: auto;
    border-collapse: collapse;
    font-size: 10pt;
}
.dt-inv-totals td { padding: 2px 0 2px 8px; vertical-align: baseline; }
.dt-inv-totals-label {
    width: 62%;
    text-align: left;
    font-weight: 600;
    color: #475569;
    font-size: 9.5pt;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    line-height: 1.3;
}
.dt-inv-totals-val {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    color: #0f172a;
}
.dt-inv-totals-tr.dt-grand td {
    padding-top: 6px;
    border-top: 1px solid #94a3b8;
    font-size: 11pt;
    font-weight: 700;
}
.dt-section { padding: 8px 12px; border-bottom: 1px solid #e8edf2; }
.dt-section-title {
    font-size: 10.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #1e3a8a;
    margin: 0 0 6px 0;
    line-height: 1.2;
}
.dt-field { display: flex; gap: 8px; margin-bottom: 4px; align-items: flex-start; }
.dt-label {
    width: 38%;
    min-width: 110px;
    max-width: 200px;
    font-weight: 600;
    color: #475569;
    font-size: 9pt;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    line-height: 1.3;
}
.dt-value {
    flex: 1;
    color: #0f172a;
    font-size: 10.5pt;
    line-height: 1.35;
}
.dt-paragraph { font-size: 10.5pt; line-height: 1.4; color: #0f172a; white-space: pre-line; margin: 0; }
.dt-signature-block { margin-bottom: 8px; }
.dt-signature-label { font-weight: 600; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.03em; font-size: 9pt; }
.dt-signature, .dt-pattern {
    max-width: 100%;
    height: auto;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px;
    background: #f8fafc;
}
.dt-signature-caption { font-size: 9pt; color: #64748b; margin-top: 4px; }
.dt-footer { text-align: center; padding: 6px 8px; font-size: 9pt; color: #64748b; background: #f8fafc; border-top: 1px solid #e2e8f0; }
/* Акт и счёт (Dompdf): тот же каркас шапки/секций, что у остальных dt-document */
.dt-document--order,
.dt-document--invoice {
    font-size: 9.5pt;
    line-height: 1.35;
}
.dt-document--order .dt-section {
    page-break-inside: avoid;
}
/* Invoice: не форсировать разрывы внутри каждой карточки — иначе лишняя 2-я страница */
.dt-document--invoice .dt-section.dt-inv-card {
    page-break-inside: auto;
}
.dt-document--invoice .dt-inv-line-table {
    page-break-inside: auto;
}
/* Invoice PDF: контрастная шапка + компактная вёрстка */
.dt-document--invoice {
    max-width: 100%;
    color: #0f172a;
}
.dt-inv-pdf-header {
    width: 100%;
    background-color: #1e40af;
    color: #ffffff;
    padding: 0;
    border-radius: 6px;
    margin: 0 0 5px 0;
    overflow: hidden;
}
.dt-inv-pdf-header-inner {
    width: 100%;
    background-color: #1e40af !important;
    color: #ffffff !important;
}
.dt-inv-pdf-header-inner td {
    background-color: #1e40af;
    color: #ffffff;
    padding: 10px 12px;
}
.dt-inv-pdf-header-left { width: 58%; }
.dt-inv-pdf-header-right { width: 42%; text-align: right; }
.dt-inv-pdf-logo {
    max-height: 40px;
    max-width: 44px;
    width: auto;
    height: auto;
    display: block;
}
.dt-inv-pdf-logo-fallback {
    width: 36px;
    height: 36px;
    line-height: 36px;
    text-align: center;
    border-radius: 6px;
    background: rgba(255,255,255,0.25);
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
}
.dt-inv-pdf-header-brandtbl { width: auto; }
.dt-inv-pdf-header-brandtbl td { padding: 0 !important; background: transparent !important; }
.dt-inv-pdf-header-logo-cell { width: 48px; padding-right: 8px !important; }
.dt-inv-pdf-company { font-size: 13pt; font-weight: 700; letter-spacing: 0.02em; line-height: 1.2; color: #ffffff; }
.dt-inv-pdf-tagline { font-size: 8pt; color: rgba(255,255,255,0.9); margin-top: 1px; }
.dt-inv-pdf-doc-title { font-size: 14pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; line-height: 1.15; color: #ffffff; }
.dt-inv-pdf-invoice-no { margin-top: 4px; font-size: 9pt; color: rgba(255,255,255,0.95); }
.dt-inv-pdf-invoice-no strong { color: #ffffff; }
.dt-inv-layout-2col { margin: 0 0 5px 0; border-collapse: separate; border-spacing: 0; }
.dt-inv-layout-2col--mid { margin-top: 5px; margin-bottom: 2px; }
.dt-inv-layout-td--left { padding: 0 4px 0 0; }
.dt-inv-layout-td--right { padding: 0 0 0 4px; }
.dt-document--invoice .dt-inv-card--company .dt-company-section-title,
.dt-document--invoice .dt-company-section-title { margin: 0 0 3px 0; font-size: 8.5pt; }
.dt-document--invoice .dt-inv-card--company {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 5px 7px;
    margin: 0;
    height: 100%;
}
.dt-document--invoice .dt-inv-card--company .dt-company-title { font-size: 9.5pt; }
.dt-document--invoice .dt-section.dt-inv-card {
    border-bottom: none;
    padding: 5px 7px;
    margin-top: 0;
    margin-bottom: 0;
}
.dt-inv-card {
    background: #f9f9f9;
    border: 1px solid #e8e8e8;
    border-radius: 6px;
    padding: 5px 7px;
    margin-top: 5px;
}
.dt-document--invoice .dt-inv-card--details { margin: 0; }
.dt-inv-pay-grid { border-collapse: collapse; margin-top: 2px; }
.dt-inv-pay-label {
    font-size: 7.5pt;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 0 4px 2px 0;
    vertical-align: bottom;
    line-height: 1.2;
    width: 50%;
}
.dt-inv-pay-label--full { padding-top: 3px; }
.dt-inv-pay-val {
    font-size: 9pt;
    color: #0f172a;
    padding: 0 4px 0 0;
    vertical-align: top;
    line-height: 1.3;
    word-wrap: break-word;
}
.dt-inv-pay-val--full { padding-top: 1px; }
.dt-inv-cust-grid { border-collapse: collapse; margin-top: 2px; table-layout: fixed; }
.dt-inv-cust-h {
    font-size: 7.5pt;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    text-align: left;
    padding: 0 3px 2px 0;
    line-height: 1.2;
    border-bottom: 1px solid #e2e8f0;
}
.dt-inv-cust-v {
    font-size: 9pt;
    color: #0f172a;
    padding: 3px 3px 0 0;
    vertical-align: top;
    line-height: 1.3;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.dt-document--invoice .dt-section-title {
    font-size: 9pt;
    margin: 0 0 4px 0;
    line-height: 1.25;
    color: #1e40af;
    letter-spacing: 0.04em;
}
.dt-document--invoice .dt-field { margin-bottom: 1px; gap: 6px; }
.dt-document--invoice .dt-label { font-size: 8pt; min-width: 85px; line-height: 1.3; }
.dt-document--invoice .dt-value { font-size: 9pt; line-height: 1.35; }
.dt-document--invoice .dt-inv-line-table thead th {
    background: #eeeeee;
    border: 1px solid #d4d4d8;
    padding: 2px 4px;
    font-size: 8pt;
    line-height: 1.3;
}
.dt-document--invoice .dt-inv-line-table td {
    border: 1px solid #e2e8f0;
    padding: 1px 4px;
    font-size: 9pt;
    line-height: 1.32;
}
.dt-document--invoice .dt-inv-line-table tbody tr:nth-child(even) td { background: #f3f4f6; }
.dt-document--invoice .dt-inv-line-table tr { page-break-inside: auto; }
.dt-document--invoice .dt-inv-totals {
    font-size: 9pt;
    margin: 2px 0 4px 0;
}
.dt-document--invoice .dt-inv-totals td { padding: 1px 0 1px 6px; }
.dt-document--invoice .dt-inv-totals-label { font-size: 8.5pt; line-height: 1.3; }
.dt-document--invoice .dt-inv-totals-tr.dt-grand td {
    padding-top: 4px;
    font-size: 10pt;
}
.dt-inv-totals-fallback-wrap {
    max-width: 400px;
    margin-left: auto;
    margin-top: 2px;
}
.dt-inv-totals-fallback-wrap .dt-inv-totals { width: 100%; }
.dt-inv-notes-body {
    font-size: 7.5pt;
    line-height: 1.22;
    max-width: 100%;
    color: #334155;
    margin: 0;
    padding: 2px 0 0 0;
}
.dt-inv-note-empty { color: #94a3b8; }
.dt-inv-legal {
    margin-top: 2px;
    padding-top: 2px;
    border-top: 1px solid #e5e7eb;
    font-size: 7pt;
    line-height: 1.22;
    color: #64748b;
    max-width: 100%;
}
.dt-document--invoice .dt-inv-card--notes {
    margin-top: 4px;
    padding: 3px 5px 2px 5px;
}
.dt-document--invoice .dt-inv-card--notes .dt-section-title {
    margin-bottom: 2px;
}
.dt-document--invoice .dt-footer {
    padding: 3px 4px;
    font-size: 8pt;
    margin-top: 4px;
}
.dt-document--order {
    font-size: 10pt;
    max-width: 760px;
    margin: 0 auto;
}
.dt-order-header {
    width: 100%;
    background: linear-gradient(135deg, #4f46e5 0%, #4338ca 55%, #3730a3 100%);
    color: #f8fafc;
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 18px;
}
.dt-order-brand,
.dt-order-head-meta {
    display: inline-block;
    vertical-align: top;
}
.dt-order-brand {
    width: 60%;
}
.dt-order-head-meta {
    width: 38%;
    text-align: right;
}
.dt-order-brand-logo,
.dt-order-brand-fallback {
    display: inline-block;
    vertical-align: top;
}
.dt-order-brand-logo {
    max-width: 68px;
    max-height: 68px;
    width: auto;
    height: auto;
    margin-right: 12px;
}
.dt-order-brand-fallback {
    width: 58px;
    height: 58px;
    line-height: 58px;
    text-align: center;
    border-radius: 16px;
    margin-right: 12px;
    background: rgba(255,255,255,0.18);
    color: #ffffff;
    font-weight: 700;
    font-size: 20px;
}
.dt-order-brand-copy {
    display: inline-block;
    vertical-align: top;
}
.dt-order-brand-name {
    font-size: 21pt;
    font-weight: 700;
    line-height: 1.1;
}
.dt-order-brand-sub {
    margin-top: 4px;
    font-size: 10.5pt;
    opacity: 0.92;
}
.dt-order-brand-meta {
    margin-top: 3px;
    font-size: 8.8pt;
    opacity: 0.9;
    line-height: 1.35;
}
.dt-order-doc-title {
    font-size: 17pt;
    font-weight: 700;
    line-height: 1.1;
}
.dt-order-doc-meta {
    margin-top: 5px;
    font-size: 9pt;
    opacity: 0.92;
}
.dt-order-layout {
    margin-bottom: 18px;
}
.dt-order-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 16px;
    page-break-inside: avoid;
}
.dt-order-card-title {
    margin: 0 0 10px 0;
    font-size: 10pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #4f46e5;
}
.dt-order-main-value {
    font-size: 14pt;
    font-weight: 700;
    color: #111827;
    line-height: 1.25;
}
.dt-order-detail {
    margin-top: 8px;
}
.dt-order-detail-label {
    font-size: 8.5pt;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 2px;
}
.dt-order-detail-value {
    font-size: 10.2pt;
    color: #111827;
    line-height: 1.4;
}
.dt-order-problem-block {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #eef2f7;
}
.dt-order-problem-text {
    font-size: 10.5pt;
    color: #111827;
    line-height: 1.45;
    white-space: pre-line;
}
.dt-order-signature-card {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 16px 18px;
    margin-top: 4px;
    page-break-inside: avoid;
}
.dt-order-signature-image {
    max-width: 220px;
    max-height: 64px;
    width: auto;
    height: auto;
    display: block;
    margin-bottom: 8px;
}
.dt-order-signature-line {
    width: 240px;
    border-top: 1px solid #111827;
    margin-top: 18px;
}
.dt-order-signature-label {
    margin-top: 6px;
    font-size: 9pt;
    color: #6b7280;
}
.dt-order-pattern-wrap {
    margin-top: 12px;
}
.dt-order-pattern-label {
    font-size: 8pt;
    color: #6b7280;
    margin-bottom: 4px;
}
.dt-order-pattern-image {
    max-width: 110px;
    max-height: 48px;
    width: auto;
    height: auto;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 4px;
    background: #f8fafc;
}
/* Квитанция: компактный premium layout без лишних реквизитов */
.dt-document--receipt {
    font-size: 9.3pt;
    max-width: 760px;
    margin: 0 auto;
}
.dt-receipt-header {
    width: 100%;
    padding: 14px 16px;
    margin-bottom: 12px;
    border-radius: 16px;
    /* Dompdf: без сплошного background-color градиент может не отрисоваться → белый текст на белом листе */
    background-color: #4f46e5;
    background-image: linear-gradient(135deg, #5b4fe8 0%, #4f46e5 48%, #2563eb 100%);
    color: #ffffff;
    box-shadow: 0 10px 24px rgba(79,70,229,0.16);
    page-break-inside: avoid;
}
.dt-receipt-header-main {
    width: 100%;
}
.dt-receipt-brand,
.dt-receipt-head-meta {
    display: inline-block;
    vertical-align: middle;
}
.dt-receipt-brand {
    width: 62%;
}
.dt-receipt-head-meta {
    width: 36%;
    text-align: right;
}
.dt-receipt-brand-logo,
.dt-receipt-brand-fallback {
    display: inline-block;
    vertical-align: middle;
}
.dt-receipt-brand-logo {
    max-width: 46px;
    max-height: 46px;
    width: auto;
    height: auto;
    margin-right: 12px;
    border-radius: 14px;
}
.dt-receipt-brand-fallback {
    width: 44px;
    height: 44px;
    line-height: 44px;
    text-align: center;
    border-radius: 14px;
    margin-right: 12px;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.24);
    color: #ffffff;
    font-weight: 700;
    font-size: 17px;
}
.dt-receipt-brand-copy {
    display: inline-block;
    vertical-align: middle;
}
.dt-receipt-brand-name {
    font-size: 18pt;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.05;
}
.dt-receipt-brand-sub {
    margin-top: 3px;
    font-size: 9pt;
    color: rgba(255,255,255,0.90);
}
.dt-receipt-brand-meta {
    margin-top: 5px;
    font-size: 7.8pt;
    color: rgba(255,255,255,0.82);
    line-height: 1.35;
}
.dt-receipt-sep {
    display: inline-block;
    margin: 0 5px;
    color: rgba(255,255,255,0.55);
}
.dt-receipt-doc-title {
    font-size: 15.5pt;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.05;
}
.dt-receipt-doc-meta {
    margin-top: 5px;
    font-size: 8pt;
    color: rgba(255,255,255,0.84);
    line-height: 1.35;
}
.dt-receipt-hero {
    background: #f7f8ff;
    border: 1px solid #dfe4ff;
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 11px;
    box-shadow: 0 4px 12px rgba(99,102,241,0.04);
}
.dt-receipt-hero-left,
.dt-receipt-hero-right {
    display: inline-block;
    vertical-align: top;
}
.dt-receipt-hero-left {
    width: 60%;
}
.dt-receipt-hero-right {
    width: 38%;
    text-align: right;
}
.dt-receipt-hero-title {
    font-size: 14pt;
    font-weight: 700;
    color: #111827;
    line-height: 1.1;
}
.dt-receipt-hero-desc {
    margin-top: 5px;
    font-size: 8.9pt;
    color: #6b7280;
    line-height: 1.32;
}
.dt-receipt-hero-note {
    margin-top: 9px;
    font-size: 8.3pt;
    color: #4b5563;
    line-height: 1.34;
    padding: 8px 10px;
    border-radius: 11px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
}
.dt-receipt-hero-amount {
    font-size: 21pt;
    font-weight: 700;
    color: #111827;
    line-height: 1.05;
}
.dt-receipt-status-pill {
    display: inline-block;
    margin-top: 7px;
    padding: 5px 10px;
    border-radius: 999px;
    background: #ede9fe;
    color: #5b21b6;
    font-size: 8pt;
    font-weight: 700;
}
.dt-receipt-customer-row {
    margin: 0 0 14px 0;
    font-size: 10pt;
    color: #374151;
}
.dt-receipt-customer-card {
    background: #fbfcff;
}
.dt-receipt-customer-line {
    font-size: 9pt;
    color: #374151;
    line-height: 1.45;
    margin-top: 3px;
}
.dt-receipt-inline-label {
    font-weight: 700;
    color: #111827;
}
.dt-receipt-card {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 12px 14px;
    margin-bottom: 10px;
    page-break-inside: avoid;
    background: #ffffff;
}
.dt-receipt-section-title {
    margin: 0 0 8px 0;
    font-size: 8.1pt;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: #6C5CE7;
}
.dt-receipt-detail-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.dt-receipt-detail-cell {
    width: 50%;
    vertical-align: top;
    padding-right: 12px;
}
.dt-receipt-detail-row {
    margin-bottom: 8px;
}
.dt-receipt-detail-label {
    display: block;
    font-size: 7.7pt;
    color: #667085;
    margin: 0 0 2px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.dt-receipt-detail-value {
    display: block;
    font-size: 8.9pt;
    color: #111827;
    line-height: 1.34;
}
.dt-receipt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8.7pt;
}
.dt-receipt-table th,
.dt-receipt-table td {
    padding: 7px 0;
    border-bottom: 1px solid #eceff3;
    vertical-align: top;
    text-align: left;
}
.dt-receipt-table th {
    font-size: 7.6pt;
    letter-spacing: 0.03em;
    color: #6b7280;
}
.dt-receipt-table th:nth-child(2),
.dt-receipt-table td:nth-child(2) {
    text-align: center;
}
.dt-receipt-table th:nth-child(3),
.dt-receipt-table td:nth-child(3) {
    text-align: right;
}
.dt-receipt-table tbody tr:last-child td {
    border-bottom: none;
}
.dt-receipt-line-name {
    font-weight: 600;
    color: #111827;
}
.dt-receipt-line-desc {
    margin-top: 2px;
    font-size: 7.8pt;
    color: #6b7280;
    line-height: 1.28;
}
.dt-receipt-num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.dt-receipt-comment {
    font-size: 8.7pt;
    line-height: 1.42;
    color: #374151;
    white-space: pre-line;
}
.dt-receipt-totals-card .dt-receipt-detail-row:last-of-type {
    margin-bottom: 0;
}
.dt-receipt-totals-card .dt-receipt-detail-row {
    margin-bottom: 0;
    padding: 2px 0;
}
.dt-receipt-totals-card {
    background: #f8faff;
    border-color: #dfe4ff;
}
.dt-receipt-totals-card .dt-receipt-detail-label,
.dt-receipt-totals-card .dt-receipt-detail-value {
    display: inline-block;
    font-size: 10.8pt;
    font-weight: 700;
    color: #111827;
}
.dt-receipt-vat-note {
    margin-top: 8px;
    padding-top: 7px;
    border-top: 1px solid #eceff3;
    font-size: 8pt;
    color: #6b7280;
}
.dt-receipt-signature-wrap {
    margin-top: 12px;
    page-break-inside: avoid;
}
.dt-receipt-signature-main {
    width: 100%;
}
.dt-receipt-signature-image {
    max-width: 160px;
    max-height: 40px;
    width: auto;
    height: auto;
    display: block;
    margin-bottom: 6px;
}
.dt-receipt-signature-inline {
    white-space: nowrap;
}
.dt-receipt-signature-line {
    display: inline-block;
    vertical-align: middle;
    border-top: 1px solid #111827;
    width: 210px;
    margin-left: 10px;
}
.dt-receipt-signature-text {
    display: inline-block;
    vertical-align: middle;
    font-size: 8.6pt;
    color: #111827;
    font-weight: 600;
}
.dt-receipt-pattern-mini {
    margin-top: 6px;
}
.dt-receipt-pattern-label {
    font-size: 7.5pt;
    color: #6b7280;
    margin-bottom: 2px;
}
.dt-receipt-pattern-image {
    max-width: 84px;
    max-height: 32px;
    width: auto;
    height: auto;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 2px;
    background: #f8fafc;
}
.dt-document--receipt .dt-footer {
    padding: 6px 0 0;
    font-size: 7.8pt;
    background: transparent;
    border-top: none;
}
/* Диагностический отчёт: много полей — плотная сетка, переносы на A4 */
.dt-document--report { font-size: 9.75pt; }
.dt-document--report .dt-header { padding: 8px 12px; }
.dt-document--report .dt-header-title { font-size: 15px; }
.dt-document--report .dt-header-sub { font-size: 11px; }
.dt-document--report .dt-company { padding: 6px 10px; font-size: 9.25pt; }
.dt-document--report .dt-section { padding: 6px 10px; page-break-inside: avoid; }
.dt-document--report .dt-section-title { font-size: 9.75pt; margin-bottom: 4px; }
.dt-document--report .dt-field { margin-bottom: 2px; gap: 6px; }
.dt-document--report .dt-label { font-size: 8pt; min-width: 92px; max-width: 160px; }
.dt-document--report .dt-value { font-size: 9.5pt; }
.dt-document--report .dt-paragraph { font-size: 9.5pt; line-height: 1.3; }
.dt-document--report .dt-report-extra .dt-label { font-size: 7.5pt; word-break: break-word; }
.dt-document--report .dt-footer { padding: 5px 8px; font-size: 8.5pt; }
.dt-legal {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid #e2e8f0;
    font-size: 8.5pt;
    line-height: 1.35;
    color: #64748b;
}
CSS;
}

?>

