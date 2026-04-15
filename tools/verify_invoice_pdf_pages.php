<?php
/**
 * Проверка: сколько страниц у PDF счёта в Dompdf (локально или на сервере).
 * Запуск из корня сайта: php tools/verify_invoice_pdf_pages.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/api/dompdf/autoload.inc.php';
require_once $root . '/api/lib/document_templates.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Данные в духе реального счёта: две строки + длинное примечание (как в жалобах на 4 страницы).
$longNote = implode("\n", [
    'Työhön sisältyy LAN-kaapeloinnin suunnittelu ja asennus neljään huoneeseen, yhteyksien testaus (noin 600–900 Mbps per piste), sekä verkon toimivuuden varmistaminen.',
    'Toimistoon on asennettu verkkokytkin (TP-Link TL-SG105), joka mahdollistaa useiden laitteiden (mm. Konica MFP) liittämisen samaan verkkoon ilman nopeuden heikkenemistä.',
    'Kaikki kaapelit on merkitty huonekohtaisesti selkeyden ja jatkokäytön helpottamiseksi.',
    'Läpiviennit on tiivistetty PU-vaahdolla ja viimeistelty akryylimassalla siistin ja huomaamattoman lopputuloksen saavuttamiseksi.',
    'Kohteeseen on toimitettu ja asennettu 2 kpl valvontakameroita.',
    'Lisäksi on suoritettu aiempia teknisiä tukikäyntejä (puhelin, tulostin ja muiden laitteiden asetukset), joista on myönnetty asiakaskohtainen alennus.',
    'ALV 0% – vähäinen liiketoiminta (AVL 3 §).',
    'Läpikuluerä sisältää ulkopuolisen toimittajan laskun mukaisen arvonlisäveron.',
    'Lasku on laadittu sovellettavien verosääntöjen mukaisesti. ALV näytetään rivikohtaisesti (0 % tai 25,5 %).',
]);

$data = [
    'document_id' => 'INV-DOC-TEST',
    'invoice_id' => 'FV-2026-0001',
    'date_created' => '2026-04-07 22:39:00',
    'date_updated' => '2026-04-14 22:28:00',
    'due_date' => '2026-04-21',
    'payment_terms' => '14 days',
    'service_object' => 'Verkkokaapeloinnin ja valvontakameroiden asennus',
    'service_address' => 'Hankasuontie 4, Helsinki.',
    'status' => 'sent_to_client',
    'payment_method' => 'bank_transfer',
    'payment_date' => '',
    'client_name' => 'West-Cast Ltd / Länsi-Valu OY',
    'client_phone' => '358400181888',
    'client_email' => 'mauri@westcast.fi',
    'note' => $longNote,
    'items' => [
        ['name' => 'Internet-kaapelin reititys ja yhteys', 'qty' => 1, 'price' => 755.90, 'vat' => 0],
        ['name' => 'Ulkopuolinen työ', 'qty' => 1, 'price' => 250.00, 'vat' => 25.5],
    ],
    'language' => 'fi',
    'display_mode' => 'detailed',
];

$html = dt_render_document_html('invoice', $data, 'fi');

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$pages = method_exists($canvas, 'get_page_count') ? $canvas->get_page_count() : -1;

$out = $root . '/generated_pdfs/_verify_invoice_test.pdf';
@mkdir(dirname($out), 0755, true);
file_put_contents($out, $dompdf->output());

echo "Dompdf invoice test PDF pages: {$pages}\n";
echo "Written: {$out}\n";
if ($pages > 0 && $pages <= 2) {
    echo "OK (ожидалось не более 2 страниц при типичном счёте).\n";
    exit(0);
}
if ($pages > 2) {
    echo "WARN: много страниц — смотрите макет/HTML.\n";
    exit(1);
}
exit(0);
