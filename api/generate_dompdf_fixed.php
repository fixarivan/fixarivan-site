<?php
/**
 * ИСПРАВЛЕННЫЙ API ДЛЯ ГЕНЕРАЦИИ PDF С DOMPDF
 * Использует локально установленную библиотеку Dompdf
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (ob_get_length()) {
    ob_end_clean();
}
if (ob_get_level() === 0) {
    ob_start();
}

// Dompdf/FontLib/Svg вызывают mb_* без «\» из своего namespace; без mbstring — полифиллы.
require_once __DIR__ . '/lib/dompdf_mb_polyfills.php';

$dompdfAutoload = __DIR__ . '/dompdf/autoload.inc.php';
if (!file_exists($dompdfAutoload)) {
    echo json_encode([
        'success' => false,
        'message' => 'Библиотека Dompdf не установлена. Загрузите папку api/dompdf.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $dompdfAutoload;

$templatesPath = __DIR__ . '/lib/document_templates.php';
if (!file_exists($templatesPath)) {
    echo json_encode([
        'success' => false,
        'message' => 'Отсутствует файл шаблонов документов (api/lib/document_templates.php)'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $templatesPath;
require_once __DIR__ . '/lib/pdf_receipt_pipeline.php'; // fixarivan_pdf_save_html_snapshot (debug HTML)

// PDF генерируется без загрузки корневого config.php — фиксируем TZ для date() в шаблонах.
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Europe/Helsinki');
}

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/mobile_report_document.php';
require_once __DIR__ . '/lib/pdf_request_auth.php';
require_once __DIR__ . '/lib/company_profile.php';

function logPdfGenerationError($context, $message)
{
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/pdf_errors.log';
    $entry = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $context, $message);
    @file_put_contents($logFile, $entry, FILE_APPEND);
    error_log("{$context}: {$message}");
}

function loadDocumentFromSqliteOrJson(string $documentType, string $documentId): array {
    // For acts & receipts we store data in SQLite + JSON backup
    $storageBase = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
    try {
        $pdo = getSqliteConnection();
        if ($documentType === 'order') {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE document_id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            if (is_array($row) && $row !== []) return $row;
        }
        if ($documentType === 'receipt') {
            $stmt = $pdo->prepare('SELECT * FROM receipts WHERE document_id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            if (is_array($row) && $row !== []) return $row;
        }
        if ($documentType === 'invoice') {
            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE document_id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            if (is_array($row) && $row !== []) return $row;
        }
    } catch (Throwable $e) {
        // fallback to JSON
    }

    if ($documentType === 'order') {
        $jsonPath = $storageBase . 'orders' . DIRECTORY_SEPARATOR . $documentId . '.json';
    } elseif ($documentType === 'receipt') {
        $jsonPath = $storageBase . 'receipts' . DIRECTORY_SEPARATOR . $documentId . '.json';
    } elseif ($documentType === 'invoice') {
        $jsonPath = $storageBase . 'invoices' . DIRECTORY_SEPARATOR . $documentId . '.json';
    } else {
        return [];
    }

    if (!is_file($jsonPath)) return [];
    $raw = file_get_contents($jsonPath);
    if ($raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function loadReportFromSqlite(string $documentId): array {
    try {
        $pdo = getSqliteConnection();
        $doc = fixarivan_load_mobile_report_by_id($pdo, $documentId);
        return $doc !== null ? $doc : [];
    } catch (Throwable $e) {
        return [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Некорректные данные для генерации PDF']);
        exit;
    }

    $payloadData = $input['data'] ?? [];
    $documentType = $input['documentType'] ?? $input['type'] ?? null;
    $documentId = $input['documentId'] ?? ($payloadData['documentId'] ?? null);

    if (!$documentId || !$documentType) {
        echo json_encode(['success' => false, 'message' => 'Не переданы идентификатор или тип документа']);
        exit;
    }

    if (!fixarivan_pdf_generation_allowed($input)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Нет доступа к генерации PDF (нужна админ-сессия или clientToken документа).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Receipt UI sends `services` as array of objects.
    // The document template expects `services_rendered` as a string.
    if (isset($payloadData['services']) && is_array($payloadData['services'])) {
        $payloadData['servicesRendered'] = implode("\n", array_map(static function ($item): string {
            if (is_array($item)) {
                $name = (string)($item['name'] ?? '');
                $desc = (string)($item['description'] ?? '');
                $price = $item['price'] ?? null;
                $priceText = $price === null || $price === '' ? '' : (' - ' . (string)$price);
                $line = trim($name . ($desc !== '' ? ' (' . $desc . ')' : '') . $priceText);
                return $line !== '' ? $line : '—';
            }
            return (string)$item;
        }, $payloadData['services']));
        unset($payloadData['services']);
    }

    $dbData = [];

    if ($documentType === 'order' || $documentType === 'receipt' || $documentType === 'invoice') {
        $dbData = loadDocumentFromSqliteOrJson($documentType, (string) $documentId);
    } elseif ($documentType === 'report') {
        $dbData = loadReportFromSqlite((string) $documentId);
    } else {
        echo json_encode(['success' => false, 'message' => 'Неизвестный тип документа'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if (!$dbData && empty($payloadData)) {
            echo json_encode(['success' => false, 'message' => 'Документ не найден']);
            exit;
        }

        $mergedData = dt_merge_data($dbData, $payloadData);
        $mergedData['document_id'] = $mergedData['document_id'] ?? $documentId;
        $mergedData['date_created'] = $mergedData['date_created'] ?? $dbData['date_created'] ?? date('Y-m-d H:i:s');
        if (empty($mergedData['place_of_acceptance'])) {
            $mergedData['place_of_acceptance'] = 'Turku, Finland';
        }
        $companyProfile = fixarivan_company_profile_load();
        $cpPhone = trim((string)($companyProfile['company_phone'] ?? ''));
        $cpEmail = trim((string)($companyProfile['company_email'] ?? ''));
        if (empty($mergedData['contact_phone'])) {
            $mergedData['contact_phone'] = $cpPhone !== '' ? $cpPhone : '+358 40 123 4567';
        }
        if (empty($mergedData['contact_email'])) {
            $mergedData['contact_email'] = $cpEmail !== '' ? $cpEmail : 'info@fixarivan.space';
        }

        $language = dt_normalize_language($input['language'] ?? $payloadData['language'] ?? ($mergedData['language'] ?? 'ru'));
        $mergedData['language'] = $language;

        if ($documentType === 'receipt') {
            if (!empty($mergedData['receipt_number'])) {
                $mergedData['document_id'] = (string) $mergedData['receipt_number'];
            }
            if (isset($mergedData['total_amount']) && is_numeric($mergedData['total_amount'])) {
                $mergedData['total_amount'] = (float) $mergedData['total_amount'];
            }
        }

        $html = dt_render_document_html($documentType, $mergedData, $language);
        
        // Если на сервере нет ext-dom, Dompdf не сможет работать.
        // В этом случае отдаем HTML fallback для печати в PDF из браузера.
        if (!class_exists('DOMImplementation') || !class_exists('DOMDocument')) {
            $debug = fixarivan_pdf_save_html_snapshot($documentType, (string)$documentId, $html);
            if (ob_get_level() > 0) {
                @ob_clean();
            }
            echo json_encode([
                'success' => true,
                'fallback' => 'html',
                'message' => 'PDF-движок недоступен (ext-dom). Открыт HTML для печати/сохранения в PDF.',
                'filename' => null,
                'pdf_url' => null,
                'download_url' => $debug['public_path'] ?: null,
                'pdf_file' => null,
                'debug_html_url' => $debug['public_path'] ?: null
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Настройка Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isPhpEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        // Сохраняем PDF
        $pdfFilename = "{$documentType}_{$documentId}.pdf";
        $outputDir = __DIR__ . "/../generated_pdfs/";
        $pdfFilePath = $outputDir . $pdfFilename;

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                throw new Exception('Не удалось создать директорию для PDF документов');
            }
        }

        $pdfBinary = $dompdf->output();
        if ($pdfBinary === false || file_put_contents($pdfFilePath, $pdfBinary) === false) {
            throw new Exception('Не удалось сохранить PDF документ');
        }

        $publicPath = "/generated_pdfs/" . $pdfFilename;
        $debug = fixarivan_pdf_save_html_snapshot($documentType, (string)$documentId, $html);

        if (ob_get_level() > 0) {
            @ob_clean();
        }
        echo json_encode([
            'success' => true,
            'message' => 'PDF успешно создан',
            'filename' => $pdfFilename,
            'pdf_url' => $publicPath,
            'download_url' => $publicPath,
            'pdf_file' => $publicPath,
            'debug_html_url' => $debug['public_path'] ?: null
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        logPdfGenerationError('Dompdf', $e->getMessage());
        if (ob_get_level() > 0) {
            @ob_clean();
        }
        $fallback = fixarivan_pdf_save_html_snapshot($documentType, (string)$documentId, $html ?? '<html><body>PDF error</body></html>');
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка генерации PDF: ' . $e->getMessage(),
            'debug_html_url' => $fallback['public_path'] ?: null
        ], JSON_UNESCAPED_UNICODE);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>
