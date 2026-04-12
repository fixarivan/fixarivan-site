<?php
declare(strict_types=1);

/**
 * Отладочный снимок HTML при генерации PDF (все типы документов).
 *
 * Раньше здесь был отдельный HTML/CSS для квитанции; теперь квитанция PDF
 * строится через dt_render_document_html('receipt', …) + dt_css() в generate_dompdf_fixed.php.
 */

/**
 * @return array{full_path:string,public_path:string}
 */
function fixarivan_pdf_save_html_snapshot(string $prefix, string $documentId, string $html): array
{
    $safeDoc = preg_replace('/[^A-Za-z0-9._-]+/', '_', $documentId) ?? 'doc';
    $name = $prefix . '_' . $safeDoc . '_' . date('YmdHis') . '.html';
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'generated_pdfs' . DIRECTORY_SEPARATOR . 'debug_html';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['full_path' => '', 'public_path' => ''];
    }
    $fullPath = $dir . DIRECTORY_SEPARATOR . $name;
    if (@file_put_contents($fullPath, $html) === false) {
        return ['full_path' => '', 'public_path' => ''];
    }

    return [
        'full_path' => $fullPath,
        'public_path' => '/generated_pdfs/debug_html/' . $name,
    ];
}
