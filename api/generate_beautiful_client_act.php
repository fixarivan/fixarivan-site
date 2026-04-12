<?php
/**
 * КРАСИВЫЙ КЛИЕНТСКИЙ АКТ С ДАННЫМИ КОМПАНИИ
 * Создает полноценный акт с всеми необходимыми полями
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

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

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_json_storage.php';

function logDocumentError($context, $message)
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

function loadOrderData(string $documentId): array {
    // 1) Try SQLite
    try {
        $pdo = getSqliteConnection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE document_id = :id LIMIT 1');
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch();
        if (is_array($row) && $row !== []) {
            return $row;
        }
    } catch (Throwable $e) {
        // 2) Fallback to JSON backup
    }

    $jsonPath = fixarivan_orders_storage_dir() . DIRECTORY_SEPARATOR . $documentId . '.json';
    if (!is_file($jsonPath)) {
        return [];
    }
    $raw = file_get_contents($jsonPath);
    if ($raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['documentId'])) {
        echo json_encode(['success' => false, 'message' => 'Некорректные данные для генерации акта']);
        exit;
    }

    $documentId = $input['documentId'];
    $payloadData = $input['data'] ?? [];

    $language = dt_normalize_language($input['language'] ?? $payloadData['language'] ?? 'ru');

    try {
        $dbData = loadOrderData($documentId);

        if (!$dbData && empty($payloadData)) {
            echo json_encode(['success' => false, 'message' => 'Документ не найден']);
            exit;
        }

        $mergedData = dt_merge_data($dbData, $payloadData);
        $mergedData['document_id'] = $mergedData['document_id'] ?? $documentId;
        $mergedData['language'] = $language;
        $mergedData['date_created'] = $mergedData['date_created'] ?? $dbData['date_created'] ?? date('Y-m-d H:i:s');
        if (empty($mergedData['place_of_acceptance'])) {
            $mergedData['place_of_acceptance'] = 'Turku, Finland';
        }
        if (empty($mergedData['date_of_acceptance'])) {
            $mergedData['date_of_acceptance'] = date('Y-m-d');
        }
        if (empty($mergedData['contact_phone'])) {
            $mergedData['contact_phone'] = '+358 40 123 4567';
        }
        if (empty($mergedData['contact_email'])) {
            $mergedData['contact_email'] = 'info@fixarivan.space';
        }

        $html = dt_render_document_html('order', $mergedData, $language);
        
        // Сохраняем HTML файл
        $htmlFilename = "client_act_{$documentId}.html";
        $htmlDir = __DIR__ . "/../generated_pdfs/";
        $htmlFilePath = $htmlDir . $htmlFilename;

        if (!is_dir($htmlDir)) {
            if (!mkdir($htmlDir, 0755, true) && !is_dir($htmlDir)) {
                throw new Exception('Не удалось создать директорию для актов');
            }
        }

        if (file_put_contents($htmlFilePath, $html) === false) {
            throw new Exception('Не удалось сохранить HTML-версию акта');
        }

        // Генерируем PDF с тем же оформлением
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfFilename = "client_act_{$documentId}.pdf";
        $pdfFilePath = $htmlDir . $pdfFilename;
        $pdfBinary = $dompdf->output();
        if ($pdfBinary === false || file_put_contents($pdfFilePath, $pdfBinary) === false) {
            throw new Exception('Не удалось сохранить PDF-версию акта');
        }

        if (ob_get_level() > 0) {
            @ob_clean();
        }
        echo json_encode([
            'success' => true,
            'message' => 'Красивый клиентский акт создан',
            'filename' => $pdfFilename,
            'html_url' => "/generated_pdfs/" . $htmlFilename,
            'pdf_url' => "/generated_pdfs/" . $pdfFilename,
            'download_url' => "/generated_pdfs/" . $pdfFilename
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            @ob_clean();
        }
        logDocumentError('ClientAct', $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Ошибка генерации акта: ' . $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>
