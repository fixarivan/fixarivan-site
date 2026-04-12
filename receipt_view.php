<?php
require_once __DIR__ . '/api/sqlite.php';
require_once __DIR__ . '/api/lib/document_templates.php';
require_once __DIR__ . '/api/lib/viewer_i18n.php';
require_once __DIR__ . '/api/lib/viewer_token_guard.php';

if (ob_get_length()) ob_end_clean();
header('Content-Type: text/html; charset=utf-8');

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$lang = isset($_GET['lang']) ? trim((string)$_GET['lang']) : '';
$viewerLangEarly = in_array(strtolower($lang), ['ru', 'en', 'fi'], true) ? strtolower($lang) : 'ru';

if ($token === '' && (!empty($_GET['id']) || !empty($_GET['document_id']))) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

if (!fixarivan_viewer_rate_allowed('receipt_view')) {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

function isProbablyToken(string $t): bool {
    return $t !== '' && preg_match('/^[a-fA-F0-9]{16,128}$/', $t) === 1;
}

function receiptsTokenJsonPath(string $token): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'receipts_tokens' . DIRECTORY_SEPARATOR . $token . '.json';
}

function detectLanguage(string $fallback): string {
    $fallback = trim($fallback);
    if ($fallback === '') $fallback = 'ru';
    $l = strtolower($fallback);
    return $l === 'en' || $l === 'fi' || $l === 'ru' ? $l : 'ru';
}

function loadReceiptByToken(string $token): array {
    if (!isProbablyToken($token)) return [];

    try {
        $pdo = getSqliteConnection();
        $stmt = $pdo->prepare('SELECT * FROM receipts WHERE client_token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if (is_array($row) && $row !== []) return $row;
    } catch (Throwable $e) {
        // Ignore sqlite issues.
    }

    $path = receiptsTokenJsonPath($token);
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

if ($token === '') {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

if (!isProbablyToken($token)) {
    fixarivan_viewer_rate_failure('receipt_view');
    fixarivan_viewer_log_line('receipt_view', 'invalid_token_format', fixarivan_viewer_token_fingerprint($token), '');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

$receipt = loadReceiptByToken($token);
$internalDocumentId = (string)($receipt['document_id'] ?? '');
$viewerLang = detectLanguage($lang !== '' ? $lang : ($receipt['language'] ?? 'ru'));

if (empty($receipt)) {
    fixarivan_viewer_rate_failure('receipt_view');
    fixarivan_viewer_log_line('receipt_view', 'not_found', fixarivan_viewer_token_fingerprint($token), '');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

fixarivan_viewer_rate_success('receipt_view');

$rv = fixarivan_viewer_receipt_document_ui($viewerLang);

// Ensure "document_id" shown in templates is the human-readable receipt number.
if (!empty($receipt['receipt_number'])) {
    $receipt['document_id'] = (string)$receipt['receipt_number'];
}

$pdRaw = trim((string)($receipt['payment_date'] ?? ''));
$paymentDateChip = $pdRaw !== '' ? dt_format_date($pdRaw, $viewerLang) : '—';
$payMethodChip = dt_payment_method_label((string)($receipt['payment_method'] ?? ''), $viewerLang);
$payStatusChip = dt_receipt_payment_status_label((string)($receipt['payment_status'] ?? ''), $viewerLang);
$partialPaidChip = '';
if (strtolower(trim((string)($receipt['payment_status'] ?? ''))) === 'partial' && isset($receipt['amount_paid']) && is_numeric($receipt['amount_paid'])) {
    $partialPaidChip = dt_format_currency((float)$receipt['amount_paid'], $viewerLang);
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($viewerLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($rv['page_title'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: radial-gradient(circle at 10% 12%, #7c8cff 0%, #667eea 34%, #764ba2 100%); margin: 0; padding: 0; color: #333; min-height: 100vh; min-height: 100dvh; }
        .client-top { max-width: 980px; margin: 20px auto 0; padding: 0 16px max(24px, env(safe-area-inset-bottom, 16px)); }
        .hero { background: rgba(255,255,255,0.16); border: 1px solid rgba(255,255,255,0.26); backdrop-filter: blur(10px); border-radius: 22px; padding: 18px 20px 16px; margin-bottom: 14px; color: #f8fbff; box-shadow: 0 18px 42px rgba(13, 23, 56, 0.22); }
        .hero-title { font-size: 24px; font-weight: 800; letter-spacing: 0.02em; margin: 0 0 6px 0; }
        .hero-sub { font-size: 13px; opacity: 0.95; }
        .chip-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .status-chip { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.96); color: #1f2a44; padding: 9px 13px; border-radius: 999px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); font-weight: 700; }
        .chip-soft { background: rgba(255,255,255,0.22); color: #f8fbff; border: 1px solid rgba(255,255,255,0.3); }
        .langnote { font-size: 12px; color: rgba(255,255,255,0.96); margin: 0 0 10px; }
        .receipt-stage { background: rgba(255,255,255,0.96); border-radius: 28px; padding: 18px; box-shadow: 0 28px 60px rgba(15,23,42,0.14); border: 1px solid rgba(255,255,255,0.55); }
        .viewer-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 14px 0 6px 0; }
        .btn { padding: 12px 16px; border-radius: 12px; border: none; cursor: pointer; font-weight: 700; transition: transform .15s ease, box-shadow .2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); color: #fff; box-shadow: 0 8px 18px rgba(67,56,202,0.35); }
        .btn-secondary { background: rgba(255,255,255,0.95); color: #334; border: 1px solid rgba(102,126,234,0.25); }
        .muted { color: #6b7280; font-size: 13px; margin-top: 8px; font-weight: 700; }
    </style>
    <style><?= dt_css() ?></style>
    <style>
        .receipt-stage .dt-document--receipt { max-width: 100%; border-radius: 0; }
        .receipt-stage .dt-receipt-header { margin-bottom: 14px; border-radius: 20px; padding: 16px 18px; }
        .receipt-stage .dt-receipt-brand { width: 62%; }
        .receipt-stage .dt-receipt-head-meta { width: 36%; }
        .receipt-stage .dt-receipt-brand-logo { max-width: 48px; max-height: 48px; margin-right: 12px; border-radius: 14px; }
        .receipt-stage .dt-receipt-brand-fallback { width: 46px; height: 46px; line-height: 46px; margin-right: 12px; border-radius: 14px; font-size: 18px; }
        .receipt-stage .dt-receipt-brand-name { font-size: 19pt; }
        .receipt-stage .dt-receipt-brand-sub { font-size: 10pt; margin-top: 3px; }
        .receipt-stage .dt-receipt-brand-meta { font-size: 8.1pt; margin-top: 5px; }
        .receipt-stage .dt-receipt-doc-title { font-size: 16pt; }
        .receipt-stage .dt-receipt-doc-meta { font-size: 8.3pt; margin-top: 5px; }
        .receipt-stage .dt-receipt-hero { display: flex; justify-content: space-between; gap: 18px; padding: 16px 18px; margin-bottom: 12px; border-radius: 18px; background: #f7f8ff; border-color: #dfe4ff; box-shadow: 0 12px 24px rgba(99,102,241,0.08); }
        .receipt-stage .dt-receipt-hero-left,
        .receipt-stage .dt-receipt-hero-right { display: block; width: auto; }
        .receipt-stage .dt-receipt-hero-left { flex: 1; min-width: 0; }
        .receipt-stage .dt-receipt-hero-right { width: 220px; text-align: right; }
        .receipt-stage .dt-receipt-hero-title { font-size: 15pt; line-height: 1.12; }
        .receipt-stage .dt-receipt-hero-desc { margin-top: 5px; font-size: 9pt; line-height: 1.35; }
        .receipt-stage .dt-receipt-hero-note { margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,0.8); border: 1px solid #e5e7eb; font-size: 8.4pt; line-height: 1.38; }
        .receipt-stage .dt-receipt-hero-amount { font-size: 24pt; line-height: 1; }
        .receipt-stage .dt-receipt-status-pill { margin-top: 8px; padding: 5px 10px; font-size: 8.4pt; }
        .receipt-stage .dt-receipt-card { padding: 14px 16px; margin-bottom: 12px; border-radius: 16px; background: #fff; box-shadow: 0 6px 18px rgba(15,23,42,0.04); }
        .receipt-stage .dt-receipt-customer-card { background: #fbfcff; }
        .receipt-stage .dt-receipt-customer-line { font-size: 9.2pt; line-height: 1.5; margin-top: 4px; }
        .receipt-stage .dt-receipt-detail-table { table-layout: fixed; }
        .receipt-stage .dt-receipt-detail-cell { width: 50%; padding-right: 14px; }
        .receipt-stage .dt-receipt-detail-row { margin-bottom: 8px; }
        .receipt-stage .dt-receipt-detail-label { display: block; margin: 0 0 3px; font-size: 7.9pt; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: #667085; }
        .receipt-stage .dt-receipt-detail-value { display: block; font-size: 9.2pt; color: #0f172a; line-height: 1.35; }
        .receipt-stage .dt-receipt-table { font-size: 9pt; }
        .receipt-stage .dt-receipt-table th,
        .receipt-stage .dt-receipt-table td { padding: 8px 0; }
        .receipt-stage .dt-receipt-table th:nth-child(2),
        .receipt-stage .dt-receipt-table td:nth-child(2) { text-align: center; }
        .receipt-stage .dt-receipt-table th:nth-child(3),
        .receipt-stage .dt-receipt-table td:nth-child(3) { text-align: right; }
        .receipt-stage .dt-receipt-comment { font-size: 8.9pt; line-height: 1.45; }
        .receipt-stage .dt-receipt-totals-card { background: #f8faff; border-color: #dfe4ff; }
        .receipt-stage .dt-receipt-totals-card .dt-receipt-detail-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .receipt-stage .dt-receipt-totals-card .dt-receipt-detail-label,
        .receipt-stage .dt-receipt-totals-card .dt-receipt-detail-value { display: inline-block; font-size: 12pt; font-weight: 800; color: #0f172a; }
        .receipt-stage .dt-receipt-vat-note { margin-top: 8px; padding-top: 8px; }
        .receipt-stage .dt-receipt-signature-wrap { margin-top: 12px; }
        .receipt-stage .dt-receipt-signature-inline { display: flex; align-items: center; gap: 10px; }
        .receipt-stage .dt-receipt-signature-line { flex: 1; width: auto; margin-left: 0; }
        @media (max-width: 760px) {
            body { overflow-x: hidden; }
            .client-top { padding: 0 12px 24px; max-width: 100%; box-sizing: border-box; }
            .receipt-stage { padding: 14px; border-radius: 22px; }
            .receipt-stage .dt-receipt-brand,
            .receipt-stage .dt-receipt-head-meta,
            .receipt-stage .dt-receipt-hero-left,
            .receipt-stage .dt-receipt-hero-right { display: block; width: 100%; text-align: left; }
            .receipt-stage .dt-receipt-header { padding: 14px 16px; }
            .receipt-stage .dt-receipt-head-meta { margin-top: 12px; }
            .receipt-stage .dt-receipt-hero { display: block; }
            .receipt-stage .dt-receipt-hero-right { margin-top: 14px; }
            .receipt-stage .dt-receipt-detail-cell { display: block; width: 100%; padding-right: 0; }
            .receipt-stage .dt-receipt-table th:nth-child(2),
            .receipt-stage .dt-receipt-table td:nth-child(2) { text-align: center; }
            .receipt-stage .dt-receipt-signature-inline { display: block; }
            .receipt-stage .dt-receipt-signature-line { display: block; margin-top: 8px; }
            .viewer-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .viewer-actions .btn {
                width: 100%;
                box-sizing: border-box;
                min-height: 44px;
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="client-top">
        <div class="hero">
            <h1 class="hero-title"><?= htmlspecialchars($rv['hero_title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="hero-sub"><?= htmlspecialchars($rv['hero_sub'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="chip-row">
                <div class="status-chip">🧾 <?= htmlspecialchars((string)($receipt['receipt_number'] ?? $internalDocumentId)) ?></div>
                <div class="status-chip chip-soft">🔒 <?= htmlspecialchars($rv['token_badge'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="status-chip chip-soft"><?= strtoupper(htmlspecialchars($viewerLang)) ?></div>
            </div>
        </div>
        <div class="langnote"><?= htmlspecialchars($rv['lang_note'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="chip-row" style="margin-bottom:10px;">
            <div class="status-chip"><?= htmlspecialchars($rv['pay_method_prefix'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($payMethodChip) ?></div>
            <div class="status-chip"><?= htmlspecialchars($rv['pay_status_prefix'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($payStatusChip) ?></div>
            <div class="status-chip"><?= htmlspecialchars($rv['pay_date_prefix'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($paymentDateChip) ?></div>
            <?php if ($partialPaidChip !== ''): ?>
            <div class="status-chip chip-soft"><?= htmlspecialchars($rv['paid_partial_prefix'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($partialPaidChip) ?></div>
            <?php endif; ?>
        </div>
        <div class="receipt-stage">
            <?= dt_render_document_html('receipt', $receipt, $viewerLang) ?>
        </div>

        <div class="viewer-actions">
            <button id="pdfBtn" class="btn btn-secondary" type="button">
                <?= htmlspecialchars($rv['btn_pdf'], ENT_QUOTES, 'UTF-8') ?>
            </button>
            <div id="msg" class="muted" style="display:none;"></div>
        </div>
    </div>

    <script>
        const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
        const viewerLang = <?= json_encode($viewerLang, JSON_UNESCAPED_UNICODE) ?>;
        const receiptPdfErr = <?= json_encode($rv['js_pdf_error'], JSON_UNESCAPED_UNICODE) ?>;

        document.getElementById('pdfBtn').addEventListener('click', async () => {
            try {
                // Backend expects "documentId" of the internal document (SQLite/JSON file name).
                const internalId = <?= json_encode($internalDocumentId, JSON_UNESCAPED_UNICODE) ?>;

                const payload = {
                    documentType: 'receipt',
                    documentId: internalId,
                    language: viewerLang,
                    clientToken: token
                };

                const res = await fetch('./api/generate_dompdf_fixed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (!result.success) throw new Error(result.message || 'pdf failed');
                const link = result.pdf_url || result.download_url;
                if (link) window.open(link, '_blank');
                else throw new Error('PDF link not found');
            } catch (e) {
                const el = document.getElementById('msg');
                el.textContent = receiptPdfErr + e.message;
                el.style.display = 'block';
            }
        });
    </script>
</body>
</html>

