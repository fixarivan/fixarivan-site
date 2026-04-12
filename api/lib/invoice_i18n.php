<?php
declare(strict_types=1);

/**
 * Единый словарь для счёта (viewer, PDF, print, форма invoice.html).
 * Ключи дополняют dt_translations() через fixarivan_invoice_i18n_merge().
 */

function fixarivan_invoice_i18n_merge(string $lang): array
{
    $map = [
        'ru' => [
            'document_titles' => [
                'invoice' => 'Счёт',
            ],
            'sections' => [
                'invoice_company' => 'Реквизиты компании',
                'invoice_details' => 'Данные счёта',
                'invoice_customer' => 'Клиент',
                'invoice_items' => 'Позиции счёта',
                'invoice_totals' => 'Итого и примечание',
                'invoice_payment' => 'Оплата',
            ],
            'labels' => [
                'customer' => 'Клиент',
                'grand_total' => 'Итого',
                'vat_total' => 'Сумма НДС',
                'invoice_date' => 'Дата счёта',
                'col_name' => 'Наименование',
                'col_qty' => 'Кол-во',
                'col_price' => 'Цена',
                'col_vat' => 'НДС %',
                'col_sum' => 'Сумма',
                'subtotal_amount' => 'Сумма без НДС',
                'fmt_vat_net' => 'Сумма без НДС (%s)',
                'fmt_vat_tax' => 'НДС %s',
                'invoice_legal_note' => 'Счёт сформирован в соответствии с применимыми налоговыми правилами. Суммы с НДС указаны по строкам (0% или 25,5%).',
            ],
            'values' => [
                'invoice_status' => [
                    'draft' => 'Черновик',
                    'issued' => 'Выставлен',
                    'partially_paid' => 'Частично оплачен',
                    'paid' => 'Оплачен',
                    'overdue' => 'Просрочен',
                    'cancelled' => 'Отменён',
                ],
            ],
            'ui' => [
                'page_title' => 'Счёт — FixariVan',
                'btn_print' => 'Печать / PDF из браузера',
                'btn_pdf' => 'Скачать PDF',
                'pdf_error' => 'Ошибка PDF',
                'doc_language_label' => 'Язык документа',
                'doc_language_saved' => 'В счёте',
            ],
            'footer_invoice' => '© ' . date('Y') . ' FixariVan',
        ],
        'en' => [
            'document_titles' => [
                'invoice' => 'Invoice',
            ],
            'sections' => [
                'invoice_company' => 'Company details',
                'invoice_details' => 'Invoice details',
                'invoice_customer' => 'Customer details',
                'invoice_items' => 'Invoice items',
                'invoice_totals' => 'Notes & totals',
                'invoice_payment' => 'Payment',
            ],
            'labels' => [
                'customer' => 'Customer',
                'grand_total' => 'Grand total',
                'vat_total' => 'VAT amount',
                'invoice_id' => 'Invoice number',
                'invoice_date' => 'Invoice date',
                'due_date' => 'Due date',
                'payment_terms' => 'Payment terms',
                'service_object' => 'Service / object',
                'notes' => 'Notes',
                'status' => 'Status',
                'updated_at' => 'Last updated',
                'col_name' => 'Description',
                'col_qty' => 'Quantity',
                'col_price' => 'Unit price',
                'col_vat' => 'VAT %',
                'col_sum' => 'Total',
                'subtotal_amount' => 'Net amount',
                'fmt_vat_net' => 'Net amount (%s)',
                'fmt_vat_tax' => 'VAT %s',
                'invoice_legal_note' => 'This invoice is issued in accordance with applicable tax regulations. VAT is shown per line (0% or 25.5%).',
            ],
            'values' => [
                'invoice_status' => [
                    'draft' => 'Draft',
                    'issued' => 'Sent',
                    'partially_paid' => 'Partially paid',
                    'paid' => 'Paid',
                    'overdue' => 'Overdue',
                    'cancelled' => 'Cancelled',
                ],
            ],
            'ui' => [
                'page_title' => 'Invoice — FixariVan',
                'btn_print' => 'Print / Save as PDF',
                'btn_pdf' => 'Download PDF',
                'pdf_error' => 'PDF error',
                'doc_language_label' => 'Document language',
                'doc_language_saved' => 'Saved in invoice',
            ],
            'footer_invoice' => '© ' . date('Y') . ' FixariVan',
        ],
        'fi' => [
            'document_titles' => [
                'invoice' => 'Lasku',
            ],
            'sections' => [
                'invoice_company' => 'Yrityksen tiedot',
                'invoice_details' => 'Laskun tiedot',
                'invoice_customer' => 'Asiakkaan tiedot',
                'invoice_items' => 'Laskurivit',
                'invoice_totals' => 'Lisätiedot',
                'invoice_payment' => 'Maksu',
            ],
            'labels' => [
                'customer' => 'Asiakas',
                'grand_total' => 'Yhteensä',
                'vat_total' => 'Arvonlisävero',
                'invoice_id' => 'Laskun numero',
                'invoice_date' => 'Laskun päiväys',
                'due_date' => 'Eräpäivä',
                'payment_terms' => 'Maksuehto',
                'service_object' => 'Palvelukohde',
                'notes' => 'Lisätiedot',
                'status' => 'Tila',
                'updated_at' => 'Päivitetty',
                'col_name' => 'Nimike',
                'col_qty' => 'Määrä',
                'col_price' => 'Yksikköhinta',
                'col_vat' => 'ALV %',
                'col_sum' => 'Summa',
                'subtotal_amount' => 'Veroton summa',
                'fmt_vat_net' => 'Veroton summa (%s)',
                'fmt_vat_tax' => 'ALV %s',
                'invoice_legal_note' => 'Lasku on laadittu sovellettavien verosääntöjen mukaisesti. ALV näytetään rivikohtaisesti (0 % tai 25,5 %).',
            ],
            'values' => [
                'invoice_status' => [
                    'draft' => 'Luonnos',
                    'issued' => 'Lähetetty',
                    'partially_paid' => 'Osittain maksettu',
                    'paid' => 'Maksettu',
                    'overdue' => 'Myöhässä',
                    'cancelled' => 'Peruttu',
                ],
            ],
            'ui' => [
                'page_title' => 'Lasku — FixariVan',
                'btn_print' => 'Tulosta / PDF selaimeen',
                'btn_pdf' => 'Lataa PDF',
                'pdf_error' => 'PDF-virhe',
                'doc_language_label' => 'Asiakirjan kieli',
                'doc_language_saved' => 'Tallennettu laskuun',
            ],
            'footer_invoice' => '© ' . date('Y') . ' FixariVan',
        ],
    ];

    return $map[$lang] ?? $map['ru'];
}

function fixarivan_invoice_i18n_merge_into_dict(array $dict, string $lang): array
{
    $patch = fixarivan_invoice_i18n_merge($lang);
    if (isset($patch['document_titles'])) {
        $dict['document_titles'] = array_merge($dict['document_titles'] ?? [], $patch['document_titles']);
    }
    if (isset($patch['sections'])) {
        $dict['sections'] = array_merge($dict['sections'] ?? [], $patch['sections']);
    }
    if (isset($patch['labels'])) {
        $dict['labels'] = array_merge($dict['labels'] ?? [], $patch['labels']);
    }
    if (isset($patch['values']['invoice_status'])) {
        $dict['values'] = $dict['values'] ?? [];
        $dict['values']['invoice_status'] = $patch['values']['invoice_status'];
    }
    if (isset($patch['ui'])) {
        $dict['ui'] = array_merge($dict['ui'] ?? [], $patch['ui']);
    }
    if (isset($patch['footer_invoice'])) {
        $dict['footer_invoice'] = $patch['footer_invoice'];
    }
    return $dict;
}

/**
 * Метка статуса счёта (draft / issued / paid / overdue / cancelled).
 */
function dt_invoice_status_label(string $status, array $dict): string
{
    $status = strtolower(trim($status));
    if ($status === '') {
        return $dict['no_data'] ?? '—';
    }
    $map = $dict['values']['invoice_status'] ?? [];
    return $map[$status] ?? $status;
}

/**
 * JSON для invoice.html: ru/fi/en → плоские подписи формы.
 *
 * @return array<string, array<string, string>>
 */
function fixarivan_invoice_i18n_for_form(): array
{
    $keys = static function (string $lang): array {
        $d = fixarivan_invoice_merge_for_form_one($lang);
        return $d;
    };

    return [
        'ru' => $keys('ru'),
        'en' => $keys('en'),
        'fi' => $keys('fi'),
    ];
}

function fixarivan_invoice_merge_for_form_one(string $lang): array
{
    $base = [
        'ru' => [
            'heading' => 'Счёт на оплату',
            'intro' => 'Отдельный документ (не квитанция), нумерация: FV-YYYY-XXXX (авто). Общие реквизиты и логотип компании — в <a href="admin/settings.php" target="_blank" rel="noopener">настройках</a>. Ниже можно добавить свою картинку только для этого счёта.',
            'logo_label' => 'Картинка на этом счёте (опционально)',
            'logo_remove' => 'Убрать картинку со счёта при следующем сохранении',
            'document_id' => 'Document ID',
            'invoice_id' => 'Номер счёта',
            'invoice_id_ph' => 'генерируется автоматически',
            'status' => 'Статус',
            'client_name' => 'Клиент',
            'client_phone' => 'Телефон',
            'client_email' => 'Email',
            'email' => 'Email',
            'service_object' => 'Объект/услуга',
            'service_object_ph' => 'Напр. установка камер на объекте',
            'due_date' => 'Срок оплаты',
            'payment_date_lbl' => 'Дата оплаты',
            'payment_method_lbl' => 'Способ оплаты',
            'pm_holvi_terminal' => 'Holvi (терминал)',
            'pm_cash' => 'Наличные',
            'pm_bank_transfer' => 'Банковский перевод',
            'pm_card' => 'Карта',
            'pm_mobilepay' => 'MobilePay',
            'pm_other' => 'Другое',
            'subtotal' => 'Сумма без НДС',
            'tax_rate' => 'НДС % (средн.)',
            'vat_hint' => 'НДС считается по каждой строке; итоги по ставкам ниже (без среднего %).',
            'fmt_vat_net' => 'Сумма без НДС (%s)',
            'fmt_vat_tax' => 'НДС %s',
            'total' => 'Итого',
            'payment_terms' => 'Условия оплаты',
            'order_id' => 'Order ID (опционально)',
            'order_bind_hint' => 'Привязка к заказу (document_id акта):',
            'line_items' => 'Позиции счёта',
            'th_name' => 'Наименование',
            'th_qty' => 'Кол-во',
            'th_price' => 'Цена',
            'th_vat' => 'НДС %',
            'th_sum' => 'Сумма',
            'add_row' => '+ Добавить позицию',
            'note' => 'Примечание',
            'save' => 'Сохранить счёт',
            'pdf' => 'Скачать PDF',
            'print' => 'Печать / PDF из браузера',
            'back' => 'Назад',
            'language' => 'Язык документа',
            'status_draft' => 'Черновик',
            'status_issued' => 'Выставлен',
            'status_paid' => 'Оплачен',
            'status_overdue' => 'Просрочен',
            'saved' => 'Счёт сохранён',
            'saved_viewer' => 'Счёт сохранён. Viewer:',
            'save_first' => 'Сначала сохраните счёт',
            'err_save' => 'Ошибка:',
            'err_pdf' => 'Ошибка PDF:',
            'file_too_big' => 'Размер файла не больше 2.5 МБ',
            'company_loading' => 'Загрузка реквизитов компании…',
            'company_fail' => 'Не удалось загрузить реквизиты (нужна админ-сессия).',
            'company' => 'Компания',
            'bank' => 'Банк',
            'val_client_name_required' => 'Укажите имя клиента',
            'val_contact_required' => 'Укажите телефон или email',
            'val_items_required' => 'Добавьте хотя бы одну позицию',
            'val_item_qty_invalid' => 'Неверное количество',
            'val_item_qty_positive' => 'Количество должно быть больше 0',
            'val_item_price_invalid' => 'Неверная цена',
            'val_item_price_negative' => 'Цена не может быть отрицательной',
            'val_item_vat_invalid' => 'Неверная ставка НДС',
            'val_item_vat_allowed' => 'НДС только 0% или 25,5%',
            'val_totals_invalid' => 'Ошибка расчёта итогов',
            'val_due_date_invalid' => 'Неверная дата оплаты',
            'val_due_before_invoice' => 'Срок оплаты не раньше даты счёта',
        ],
        'en' => [
            'heading' => 'Invoice',
            'intro' => 'Standalone document (not a receipt), numbering: FV-YYYY-XXXX (auto). Company details and logo are in <a href="admin/settings.php" target="_blank" rel="noopener">settings</a>. You can attach an image for this invoice only.',
            'logo_label' => 'Image on this invoice (optional)',
            'logo_remove' => 'Remove invoice image on next save',
            'document_id' => 'Document ID',
            'invoice_id' => 'Invoice number',
            'invoice_id_ph' => 'generated automatically',
            'status' => 'Status',
            'client_name' => 'Customer',
            'client_phone' => 'Phone',
            'client_email' => 'Email',
            'email' => 'Email',
            'service_object' => 'Service / object',
            'service_object_ph' => 'e.g. camera installation on site',
            'due_date' => 'Due date',
            'payment_date_lbl' => 'Payment date',
            'payment_method_lbl' => 'Payment method',
            'pm_holvi_terminal' => 'Holvi terminal',
            'pm_cash' => 'Cash',
            'pm_bank_transfer' => 'Bank transfer',
            'pm_card' => 'Card',
            'pm_mobilepay' => 'MobilePay',
            'pm_other' => 'Other',
            'subtotal' => 'Net amount',
            'tax_rate' => 'VAT % (average)',
            'vat_hint' => 'VAT is calculated per line; totals by rate below (no blended %).',
            'fmt_vat_net' => 'Net amount (%s)',
            'fmt_vat_tax' => 'VAT %s',
            'total' => 'Grand total',
            'payment_terms' => 'Payment terms',
            'order_id' => 'Order ID (optional)',
            'order_bind_hint' => 'Linked to order (work order document id):',
            'line_items' => 'Invoice items',
            'th_name' => 'Description',
            'th_qty' => 'Quantity',
            'th_price' => 'Unit price',
            'th_vat' => 'VAT %',
            'th_sum' => 'Total',
            'add_row' => '+ Add line',
            'note' => 'Notes',
            'save' => 'Save invoice',
            'pdf' => 'Download PDF',
            'print' => 'Print / Save as PDF',
            'back' => 'Back',
            'language' => 'Document language',
            'status_draft' => 'Draft',
            'status_issued' => 'Sent',
            'status_paid' => 'Paid',
            'status_overdue' => 'Overdue',
            'saved' => 'Invoice saved',
            'saved_viewer' => 'Invoice saved. Viewer:',
            'save_first' => 'Save the invoice first',
            'err_save' => 'Error:',
            'err_pdf' => 'PDF error:',
            'file_too_big' => 'File size must be at most 2.5 MB',
            'company_loading' => 'Loading company profile…',
            'company_fail' => 'Could not load company profile (admin session required).',
            'company' => 'Company',
            'bank' => 'Bank',
            'val_client_name_required' => 'Customer name is required',
            'val_contact_required' => 'Phone or email is required',
            'val_items_required' => 'Add at least one line item',
            'val_item_qty_invalid' => 'Invalid quantity',
            'val_item_qty_positive' => 'Quantity must be greater than 0',
            'val_item_price_invalid' => 'Invalid unit price',
            'val_item_price_negative' => 'Unit price cannot be negative',
            'val_item_vat_invalid' => 'Invalid VAT rate',
            'val_item_vat_allowed' => 'VAT must be 0% or 25.5%',
            'val_totals_invalid' => 'Invalid totals',
            'val_due_date_invalid' => 'Invalid due date',
            'val_due_before_invoice' => 'Due date must be on or after the invoice date',
        ],
        'fi' => [
            'heading' => 'Lasku',
            'intro' => 'Erillinen asiakirja (ei kuitti), numerointi: FV-YYYY-XXXX (automaattinen). Yrityksen tiedot ja logo ovat <a href="admin/settings.php" target="_blank" rel="noopener">asetuksissa</a>. Voit liittää kuvan vain tälle laskulle.',
            'logo_label' => 'Kuva tällä laskulla (valinnainen)',
            'logo_remove' => 'Poista kuva laskusta seuraavalla tallennuksella',
            'document_id' => 'Asiakirjatunnus',
            'invoice_id' => 'Laskun numero',
            'invoice_id_ph' => 'muodostetaan automaattisesti',
            'status' => 'Tila',
            'client_name' => 'Asiakas',
            'client_phone' => 'Puhelin',
            'client_email' => 'Sähköposti',
            'email' => 'Sähköposti',
            'service_object' => 'Palvelukohde',
            'service_object_ph' => 'esim. kameroiden asennus kohteessa',
            'due_date' => 'Eräpäivä',
            'payment_date_lbl' => 'Maksupäivä',
            'payment_method_lbl' => 'Maksutapa',
            'pm_holvi_terminal' => 'Holvi (pääte)',
            'pm_cash' => 'Käteinen',
            'pm_bank_transfer' => 'Tilisiirto',
            'pm_card' => 'Kortti',
            'pm_mobilepay' => 'MobilePay',
            'pm_other' => 'Muu',
            'subtotal' => 'Veroton summa',
            'tax_rate' => 'ALV % (keskiarvo)',
            'vat_hint' => 'ALV lasketaan rivittäin; erittely alla (ei keskiarvoprosenttia).',
            'fmt_vat_net' => 'Veroton summa (%s)',
            'fmt_vat_tax' => 'ALV %s',
            'total' => 'Yhteensä',
            'payment_terms' => 'Maksuehto',
            'order_id' => 'Tilaustunnus (valinnainen)',
            'order_bind_hint' => 'Linkitetty tilaukseen (työmääräyksen document_id):',
            'line_items' => 'Laskurivit',
            'th_name' => 'Nimike',
            'th_qty' => 'Määrä',
            'th_price' => 'Yksikköhinta',
            'th_vat' => 'ALV %',
            'th_sum' => 'Summa',
            'add_row' => '+ Lisää rivi',
            'note' => 'Lisätiedot',
            'save' => 'Tallenna lasku',
            'pdf' => 'Lataa PDF',
            'print' => 'Tulosta / PDF selaimeen',
            'back' => 'Takaisin',
            'language' => 'Asiakirjan kieli',
            'status_draft' => 'Luonnos',
            'status_issued' => 'Lähetetty',
            'status_paid' => 'Maksettu',
            'status_overdue' => 'Myöhässä',
            'saved' => 'Lasku tallennettu',
            'saved_viewer' => 'Lasku tallennettu. Linkki:',
            'save_first' => 'Tallenna lasku ensin',
            'err_save' => 'Virhe:',
            'err_pdf' => 'PDF-virhe:',
            'file_too_big' => 'Tiedoston koko enintään 2,5 Mt',
            'company_loading' => 'Ladataan yrityksen tietoja…',
            'company_fail' => 'Tietoja ei voitu ladata (tarvitaan ylläpitäjän istunto).',
            'company' => 'Yritys',
            'bank' => 'Pankki',
            'val_client_name_required' => 'Anna asiakkaan nimi',
            'val_contact_required' => 'Anna puhelin tai sähköposti',
            'val_items_required' => 'Lisää vähintään yksi laskurivi',
            'val_item_qty_invalid' => 'Virheellinen määrä',
            'val_item_qty_positive' => 'Määrän on oltava suurempi kuin 0',
            'val_item_price_invalid' => 'Virheellinen yksikköhinta',
            'val_item_price_negative' => 'Yksikköhinta ei voi olla negatiivinen',
            'val_item_vat_invalid' => 'Virheellinen ALV-prosentti',
            'val_item_vat_allowed' => 'ALV voi olla vain 0 % tai 25,5 %',
            'val_totals_invalid' => 'Virheelliset summat',
            'val_due_date_invalid' => 'Virheellinen eräpäivä',
            'val_due_before_invoice' => 'Eräpäivä ei voi olla ennen laskupäivää',
        ],
    ];

    return $base[$lang] ?? $base['ru'];
}
