<?php
require_once __DIR__ . '/api/sqlite.php';
require_once __DIR__ . '/api/lib/document_templates.php';
require_once __DIR__ . '/api/lib/viewer_token_guard.php';

if (ob_get_length()) ob_end_clean();
header('Content-Type: text/html; charset=utf-8');

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$lang = isset($_GET['lang']) ? trim((string)$_GET['lang']) : '';
$viewerLangEarly = in_array(strtolower($lang), ['ru', 'en', 'fi'], true) ? strtolower($lang) : 'ru';

if (!fixarivan_viewer_rate_allowed('invoice_view')) {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

function iv_is_token(string $t): bool {
    return $t !== '' && preg_match('/^[a-fA-F0-9]{16,128}$/', $t) === 1;
}
function iv_detect_lang(string $fallback): string {
    $l = strtolower(trim($fallback) ?: 'ru');
    return in_array($l, ['ru', 'en', 'fi'], true) ? $l : 'ru';
}
function iv_load_invoice_by_token(string $token): array {
    if (!iv_is_token($token)) return [];
    try {
        $pdo = getSqliteConnection();
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE client_token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if (is_array($row) && $row !== []) {
            $items = json_decode((string)($row['items_json'] ?? '[]'), true);
            $row['items'] = is_array($items) ? $items : [];
            return $row;
        }
    } catch (Throwable $e) {
    }
    $path = __DIR__ . '/storage/invoices/';
    if (!is_dir($path)) return [];
    foreach (glob($path . '*.json') ?: [] as $f) {
        $raw = @file_get_contents($f);
        $doc = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($doc) && (($doc['client_token'] ?? '') === $token)) return $doc;
    }
    return [];
}

if ($token === '' || !iv_is_token($token)) {
    fixarivan_viewer_rate_failure('invoice_view');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

$invoice = iv_load_invoice_by_token($token);
$storedLang = iv_detect_lang((string)($invoice['language'] ?? 'ru'));
$viewerLang = iv_detect_lang($lang !== '' ? $lang : $storedLang);
$dictView = dt_translations($viewerLang);
/** @var array<string,string> $ui — из dt_translations + fixarivan_invoice_i18n_merge_into_dict (invoice.html / PDF счёта) */
$ui = $dictView['ui'] ?? [];
if (empty($invoice)) {
    fixarivan_viewer_rate_failure('invoice_view');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}
fixarivan_viewer_rate_success('invoice_view');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($viewerLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars((string)($ui['page_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></title>
    <style><?= dt_css() ?></style>
    <style>
        body{font-family:Segoe UI,Tahoma,sans-serif;background:#eef2ff;margin:0;padding:20px;}
        .box{max-width:980px;margin:0 auto;}
        .actions{margin:10px 0 18px;}
        .btn{padding:10px 14px;border-radius:10px;border:0;background:#4f46e5;color:#fff;cursor:pointer;}
        .btn-print{background:#0f766e;margin-right:8px;}
        .lang-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.06);}
        .lang-bar label{font-size:13px;font-weight:600;color:#334155;}
        .lang-bar select{padding:6px 10px;border-radius:8px;border:1px solid #cbd5e1;font-size:14px;}
        .lang-bar .muted{font-size:12px;color:#64748b;}
        @media print {
            body { background:#fff; padding:0; }
            .actions { display:none !important; }
            .lang-bar { display:none !important; }
        }
        @media (max-width: 768px) {
            body {
                padding: 12px;
                padding-bottom: max(12px, env(safe-area-inset-bottom, 0px));
                box-sizing: border-box;
                overflow-x: hidden;
            }
            .box {
                max-width: 100%;
                padding: 0 2px;
            }
            .lang-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            .actions {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .actions .btn {
                width: 100%;
                box-sizing: border-box;
                min-height: 44px;
                font-size: 16px;
            }
            .lang-bar select {
                font-size: 16px;
                min-height: 44px;
                padding: 10px 12px;
            }
        }
    </style>
</head>
<body>
<div class="box">
    <div class="lang-bar">
        <label for="viewerDocLang"><?= htmlspecialchars((string)($ui['doc_language_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="viewerDocLang" aria-label="<?= htmlspecialchars((string)($ui['doc_language_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars((string)($ui['doc_language_saved'] ?? '') . ': ' . strtoupper($storedLang), ENT_QUOTES, 'UTF-8') ?>">
            <option value="ru"<?= $viewerLang === 'ru' ? ' selected' : '' ?>>RU</option>
            <option value="fi"<?= $viewerLang === 'fi' ? ' selected' : '' ?>>FI</option>
            <option value="en"<?= $viewerLang === 'en' ? ' selected' : '' ?>>EN</option>
        </select>
        <span class="muted"><?= htmlspecialchars((string)($ui['doc_language_saved'] ?? ''), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars(strtoupper($storedLang), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?= dt_render_document_html('invoice', $invoice, $viewerLang) ?>
    <div class="actions">
        <button class="btn btn-print" type="button" id="printBtn"><?= htmlspecialchars((string)($ui['btn_print'] ?? ''), ENT_QUOTES, 'UTF-8') ?></button>
        <button class="btn" id="pdfBtn"><?= htmlspecialchars((string)($ui['btn_pdf'] ?? ''), ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</div>
<script>
const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
const id = <?= json_encode((string)($invoice['document_id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
const lang = <?= json_encode($viewerLang, JSON_UNESCAPED_UNICODE) ?>;
document.getElementById('printBtn').addEventListener('click', () => window.print());
document.getElementById('viewerDocLang').addEventListener('change', function () {
  const u = new URL(location.href);
  u.searchParams.set('lang', this.value);
  location.href = u.toString();
});
document.getElementById('pdfBtn').addEventListener('click', async () => {
  const r = await fetch('./api/generate_dompdf_fixed.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ documentType:'invoice', documentId:id, language:lang, clientToken:token })
  });
  const j = await r.json();
  if (!j.success) { alert(j.message || <?= json_encode((string)($ui['pdf_error'] ?? ''), JSON_UNESCAPED_UNICODE) ?>); return; }
  const url = j.download_url || j.pdf_url || j.debug_html_url || '';
  if (url) window.open(url, '_blank');
});
</script>
</body>
</html>
