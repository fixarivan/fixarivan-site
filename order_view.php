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

// Доступ только по токену: не обслуживать подбор по id из URL.
if ($token === '' && (!empty($_GET['id']) || !empty($_GET['document_id']))) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

if (!fixarivan_viewer_rate_allowed('order_view')) {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

function isProbablyToken(string $t): bool {
    // Токены: hex, в т.ч. legacy 32 символа и новые ~48 (24 байта random_bytes).
    return $t !== '' && preg_match('/^[a-fA-F0-9]{16,128}$/', $t) === 1;
}

function ordersTokenJsonPath(string $token): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'orders_tokens' . DIRECTORY_SEPARATOR . $token . '.json';
}

function loadOrderByToken(string $token): array {
    if (!isProbablyToken($token)) return [];

    // SQLite-first (when available), fallback to token JSON.
    try {
        $pdo = getSqliteConnection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE client_token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if (is_array($row) && $row !== []) return $row;
    } catch (Throwable $e) {
        // Ignore sqlite issues.
    }

    $path = ordersTokenJsonPath($token);
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function detectLanguage(string $fallback): string {
    $fallback = trim($fallback);
    if ($fallback === '') $fallback = 'ru';
    $l = strtolower($fallback);
    return $l === 'en' || $l === 'fi' || $l === 'ru' ? $l : 'ru';
}

if ($token === '') {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

if (!isProbablyToken($token)) {
    fixarivan_viewer_rate_failure('order_view');
    fixarivan_viewer_log_line('order_view', 'invalid_token_format', fixarivan_viewer_token_fingerprint($token), '');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

$order = loadOrderByToken($token);
$viewerLang = detectLanguage($lang !== '' ? $lang : ($order['language'] ?? 'ru'));
$orderStatus = isset($order['status']) ? (string)$order['status'] : 'pending';

if (empty($order)) {
    fixarivan_viewer_rate_failure('order_view');
    fixarivan_viewer_log_line('order_view', 'not_found', fixarivan_viewer_token_fingerprint($token), '');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

fixarivan_viewer_rate_success('order_view');

// Status/viewed labels shown below the dt-document.
$statusLabel = (string)($orderStatus === 'sent_to_client' ? 'Отправлен клиенту' :
    ($orderStatus === 'viewed' ? 'Просмотрен' :
    ($orderStatus === 'signed' ? 'Подписан' : 'Черновик')));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($viewerLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($vu['page_title'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: radial-gradient(circle at 15% 20%, #7c8dff 0%, #667eea 32%, #764ba2 100%); margin: 0; padding: 0; color: #333; min-height: 100vh; }
        .client-top { max-width: 980px; margin: 22px auto 0; padding: 0 16px max(20px, env(safe-area-inset-bottom, 16px)); }
        .hero { background: rgba(255,255,255,0.16); border: 1px solid rgba(255,255,255,0.3); backdrop-filter: blur(8px); border-radius: 20px; padding: 18px 18px 14px; margin-bottom: 14px; color: #f8fbff; box-shadow: 0 16px 45px rgba(13, 23, 56, 0.25); }
        .hero-title { font-size: 24px; font-weight: 800; letter-spacing: 0.02em; margin: 0 0 8px 0; }
        .hero-sub { font-size: 13px; opacity: 0.95; }
        .chip-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .status-chip { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.95); color: #1f2a44; padding: 9px 13px; border-radius: 999px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); font-weight: 700; }
        .chip-soft { background: rgba(255,255,255,0.22); color: #f8fbff; border: 1px solid rgba(255,255,255,0.35); }
        .viewer-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .btn { padding: 12px 16px; border-radius: 12px; border: none; cursor: pointer; font-weight: 700; transition: transform .15s ease, box-shadow .2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); color: #fff; box-shadow: 0 8px 18px rgba(67,56,202,0.35); }
        .btn-primary:disabled { background: #b8c0e6; cursor: not-allowed; box-shadow: none; transform: none; }
        .btn-secondary { background: rgba(255,255,255,0.95); color: #334; border: 1px solid rgba(102,126,234,0.25); }
        .signature-wrap { background: rgba(255,255,255,0.98); border-radius: 20px; max-width: 880px; margin: 16px auto 28px; padding: 20px; box-shadow: 0 16px 42px rgba(22, 28, 60, 0.17); }
        canvas { width: 100%; max-width: 740px; height: 220px; background: #fff; border-radius: 14px; border: 2px dashed rgba(102,126,234,0.6); touch-action: none; }
        .consent { display: flex; gap: 10px; align-items: flex-start; margin-top: 10px; font-weight: 600; }
        .muted { color: #6b7280; font-size: 13px; margin-top: 8px; }
        .langnote { font-size: 12px; color: rgba(255,255,255,0.92); margin-bottom: 8px; }
        .dt-document { border: 1px solid rgba(99, 102, 241, 0.18) !important; }
        .dt-header { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 55%, #3730a3 100%) !important; }
        @media (max-width: 760px) {
            body {
                overflow-x: hidden;
            }
            .client-top {
                padding: 0 12px max(16px, env(safe-area-inset-bottom, 12px));
                max-width: 100%;
                box-sizing: border-box;
            }
            .hero-title {
                font-size: 20px;
            }
            .signature-wrap {
                margin: 12px 12px 24px;
                max-width: calc(100% - 24px);
                padding: 16px;
                box-sizing: border-box;
            }
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
    <style><?= dt_css() ?></style>
</head>
<body>
    <div class="client-top">
        <div class="hero">
            <h1 class="hero-title"><?= htmlspecialchars($vu['hero_title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="hero-sub"><?= htmlspecialchars($vu['hero_sub'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="chip-row">
                <div class="status-chip">📄 <?= htmlspecialchars($vu['status_prefix'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($statusLabel) ?></div>
                <div class="status-chip chip-soft">🔒 <?= htmlspecialchars($vu['token_badge'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="status-chip chip-soft"><?= strtoupper(htmlspecialchars($viewerLang)) ?></div>
            </div>
        </div>
        <div class="langnote"><?= htmlspecialchars($vu['lang_note'], ENT_QUOTES, 'UTF-8') ?></div>
        <?= dt_render_document_html('order', $order, $viewerLang) ?>
    </div>

    <div class="signature-wrap">
        <h2 style="margin:0 0 10px 0;"><?= htmlspecialchars($vu['sign_title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="muted">
            <?= htmlspecialchars($vu['sign_hint'], ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div>
            <canvas id="signatureCanvas" width="900" height="220"></canvas>
        </div>

        <div class="consent">
            <input id="consentCheckbox" type="checkbox" <?= ($orderStatus === 'signed') ? 'disabled' : 'checked' ?> />
            <label for="consentCheckbox">
                <?= htmlspecialchars($vu['consent_label'], ENT_QUOTES, 'UTF-8') ?>
            </label>
        </div>

        <div class="viewer-actions">
            <button id="signBtn" class="btn btn-primary" type="button" <?= ($orderStatus === 'signed') ? 'disabled' : '' ?>>
                <?= htmlspecialchars($vu['btn_sign'], ENT_QUOTES, 'UTF-8') ?>
            </button>

            <button id="pdfBtn" class="btn btn-secondary" type="button">
                <?= htmlspecialchars($vu['btn_pdf'], ENT_QUOTES, 'UTF-8') ?>
            </button>

            <div id="msg" style="width:100%; display:none; font-weight:700;"></div>
        </div>
    </div>

    <script>
        const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
        let orderStatus = <?= json_encode($orderStatus, JSON_UNESCAPED_UNICODE) ?>;
        const viewerLang = <?= json_encode($viewerLang, JSON_UNESCAPED_UNICODE) ?>;
        const vuMsg = <?= json_encode([
            'viewed' => fixarivan_viewer_order_workflow_status_label($viewerLang, 'viewed'),
            'statusPrefix' => $vu['status_prefix'],
            'consentRequired' => $vu['js_consent_required'],
            'signedOk' => $vu['js_signed_ok'],
            'signErrPrefix' => $vu['js_sign_error_prefix'],
            'pdfErr' => $vu['js_pdf_error'],
            'pdfLinkMissing' => $vu['js_pdf_link_missing'],
        ], JSON_UNESCAPED_UNICODE) ?>;

        const canvas = document.getElementById('signatureCanvas');
        const ctx = canvas.getContext('2d');
        let drawing = false;

        function resizeFix() {
            // Keep crisp signature on different screens.
            // We use canvas internal size as configured in HTML (900x220) and scale with CSS.
        }

        ctx.lineWidth = 2;
        ctx.strokeStyle = '#111827';
        ctx.lineCap = 'round';

        function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left) * (canvas.width / rect.width);
            const y = (e.clientY - rect.top) * (canvas.height / rect.height);
            return { x, y };
        }

        function start(e) {
            if (orderStatus === 'signed') return;
            drawing = true;
            const p = getPos(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        }
        function move(e) {
            if (!drawing) return;
            const p = getPos(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
        }
        function stop() {
            drawing = false;
        }

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        canvas.addEventListener('mouseup', stop);
        canvas.addEventListener('mouseout', stop);

        canvas.addEventListener('touchstart', (e) => { e.preventDefault(); start(e.touches[0]); }, { passive: false });
        canvas.addEventListener('touchmove', (e) => { e.preventDefault(); move(e.touches[0]); }, { passive: false });
        canvas.addEventListener('touchend', (e) => { e.preventDefault(); stop(); }, { passive: false });

        function setMsg(text, ok) {
            const el = document.getElementById('msg');
            el.textContent = text;
            el.style.display = 'block';
            el.style.color = ok ? '#14532d' : '#7f1d1d';
        }

        async function markViewed() {
            if (orderStatus === 'signed') return;
            try {
                await fetch('./api/save_order_fixed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        clientToken: token,
                        status: 'viewed',
                        isMasterForm: false,
                        language: viewerLang
                    })
                });

                // Update UI without full reload.
                orderStatus = 'viewed';
                const statusChip = document.querySelector('.status-chip');
                if (statusChip) {
                    statusChip.textContent = `${vuMsg.statusPrefix} ${vuMsg.viewed}`;
                }
            } catch (e) {
                // Viewer must never break because of viewed update.
            }
        }

        async function signAct() {
            if (orderStatus === 'signed') return;

            const consent = document.getElementById('consentCheckbox').checked;
            if (!consent) {
                setMsg(vuMsg.consentRequired, false);
                return;
            }

            const signatureData = canvas.toDataURL('image/png');
            document.getElementById('signBtn').disabled = true;

            try {
                const res = await fetch('./api/save_order_fixed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        clientToken: token,
                        status: 'signed',
                        signatureData,
                        isMasterForm: false,
                        language: viewerLang
                    })
                });

                const result = await res.json();
                if (!result.success) throw new Error(result.message || 'sign failed');

                setMsg(vuMsg.signedOk, true);
                orderStatus = 'signed';
                document.getElementById('signBtn').disabled = true;
                document.getElementById('consentCheckbox').disabled = true;
                setTimeout(() => window.location.reload(), 400);
            } catch (e) {
                setMsg(vuMsg.signErrPrefix + e.message, false);
                document.getElementById('signBtn').disabled = false;
            }
        }

        async function downloadPdf() {
            try {
                const payload = {
                    documentType: 'order',
                    documentId: <?= json_encode((string)($order['document_id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
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
                else setMsg(vuMsg.pdfLinkMissing, false);
            } catch (e) {
                // PDF errors must not break viewer.
                setMsg(vuMsg.pdfErr + e.message, false);
            }
        }

        document.getElementById('signBtn').addEventListener('click', signAct);
        document.getElementById('pdfBtn').addEventListener('click', downloadPdf);

        markViewed();
    </script>
</body>
</html>

