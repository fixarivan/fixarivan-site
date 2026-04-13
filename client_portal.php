<?php
/**
 * Клиентский портал (TZ v4): по token → заказ → client_id → все заказы клиента.
 */
require_once __DIR__ . '/api/sqlite.php';
require_once __DIR__ . '/api/lib/order_center.php';
require_once __DIR__ . '/api/lib/order_client_portal.php';
require_once __DIR__ . '/api/lib/order_document_load.php';
require_once __DIR__ . '/api/lib/company_profile.php';
require_once __DIR__ . '/api/lib/viewer_i18n.php';
require_once __DIR__ . '/api/lib/viewer_token_guard.php';

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: text/html; charset=utf-8');

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$lang = isset($_GET['lang']) ? trim((string)$_GET['lang']) : '';
$viewerLangEarly = in_array(strtolower($lang), ['ru', 'en', 'fi'], true) ? strtolower($lang) : 'ru';
$hasExplicitLang = in_array(strtolower(trim($lang)), ['ru', 'en', 'fi'], true);

if ($token === '') {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

if (!fixarivan_viewer_rate_allowed('client_portal')) {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

function client_portal_probable_token(string $t): bool {
    return $t !== '' && preg_match('/^[a-fA-F0-9]{16,128}$/', $t) === 1;
}

if (!client_portal_probable_token($token)) {
    fixarivan_viewer_rate_failure('client_portal');
    fixarivan_viewer_log_line('client_portal', 'invalid_token_format', fixarivan_viewer_token_fingerprint($token), '');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

function client_portal_load_order_by_token(PDO $pdo, string $token): array {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE client_token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function client_portal_sort_orders(array $rows, string $token): array {
    usort($rows, static function ($a, $b) use ($token): int {
        $ta = trim((string)($a['client_token'] ?? '')) === $token ? 0 : 1;
        $tb = trim((string)($b['client_token'] ?? '')) === $token ? 0 : 1;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        $psA = fixarivan_normalize_public_status($a['public_status'] ?? $a['order_status'] ?? null);
        $psB = fixarivan_normalize_public_status($b['public_status'] ?? $b['order_status'] ?? null);
        $termA = in_array($psA, ['done', 'delivered'], true) ? 1 : 0;
        $termB = in_array($psB, ['done', 'delivered'], true) ? 1 : 0;
        if ($termA !== $termB) {
            return $termA <=> $termB;
        }
        $da = strtotime((string)($a['date_updated'] ?? $a['date_created'] ?? '')) ?: 0;
        $db = strtotime((string)($b['date_updated'] ?? $b['date_created'] ?? '')) ?: 0;

        return $db <=> $da;
    });

    return $rows;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

$order = client_portal_load_order_by_token($pdo, $token);

if ($order === []) {
    fixarivan_viewer_rate_failure('client_portal');
    fixarivan_viewer_log_line('client_portal', 'not_found', fixarivan_viewer_token_fingerprint($token), '');
    fixarivan_viewer_render_neutral_unavailable($viewerLangEarly);
    exit;
}

fixarivan_viewer_rate_success('client_portal');

$clientId = (int)($order['client_id'] ?? 0);
$clientName = trim((string)($order['client_name'] ?? ''));
$clientPhone = trim((string)($order['client_phone'] ?? ''));
$clientEmail = trim((string)($order['client_email'] ?? ''));

if ($clientId > 0) {
    $st = $pdo->prepare('SELECT full_name, phone, email FROM clients WHERE id = :id LIMIT 1');
    $st->execute([':id' => $clientId]);
    $crow = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($crow) && $crow !== []) {
        if (trim((string)($crow['full_name'] ?? '')) !== '') {
            $clientName = trim((string)$crow['full_name']);
        }
        if (trim((string)($crow['phone'] ?? '')) !== '') {
            $clientPhone = trim((string)$crow['phone']);
        }
        if (trim((string)($crow['email'] ?? '')) !== '') {
            $clientEmail = trim((string)$crow['email']);
        }
    }
}

$allOrders = [];
if ($clientId > 0) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE client_id = :cid ORDER BY date_updated DESC');
    $st->execute([':cid' => $clientId]);
    $allOrders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $allOrders = client_portal_sort_orders($allOrders, $token);
} else {
    $allOrders = [$order];
}

$orderIds = [];
foreach ($allOrders as $o) {
    $oid = trim((string)($o['order_id'] ?? ''));
    if ($oid !== '') {
        $orderIds[$oid] = true;
    }
}

$receipts = [];
$invoices = [];
$reports = [];
if ($orderIds !== []) {
    $ids = array_keys($orderIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $sr = $pdo->prepare("SELECT document_id, receipt_number, client_token, total_amount, order_id FROM receipts WHERE order_id IN ($ph) ORDER BY date_created DESC LIMIT 80");
        $sr->execute($ids);
        $receipts = $sr->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $si = $pdo->prepare("SELECT invoice_id, document_id, client_token, total_amount, status, order_id FROM invoices WHERE order_id IN ($ph) ORDER BY date_created DESC LIMIT 80");
        $si->execute($ids);
        $invoices = $si->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mr = $pdo->prepare("SELECT report_id, token, model, order_id FROM mobile_reports WHERE order_id IN ($ph) ORDER BY created_at DESC LIMIT 80");
        $mr->execute($ids);
        $reports = $mr->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $receipts = [];
        $invoices = [];
        $reports = [];
    }
    $receipts = client_portal_dedupe_by_key($receipts, 'document_id');
    $invoices = client_portal_dedupe_by_key($invoices, 'document_id');
    $reports = client_portal_dedupe_by_key($reports, 'report_id');
}

$reportIdsForExclude = [];
foreach ($reports as $r) {
    $rid = trim((string) ($r['report_id'] ?? ''));
    if ($rid !== '') {
        $reportIdsForExclude[] = $rid;
    }
}
$orphanReports = [];
try {
    $orphanReports = fixarivan_portal_orphan_mobile_reports($pdo, fixarivan_normalize_phone($clientPhone), $reportIdsForExclude);
} catch (Throwable $e) {
    $orphanReports = [];
}
$orphanReports = client_portal_dedupe_by_key($orphanReports, 'report_id');

$focus = trim((string)($_GET['focus_order'] ?? $_GET['order'] ?? ''));
$featured = null;
if ($focus !== '') {
    foreach ($allOrders as $o) {
        if (trim((string)($o['order_id'] ?? '')) === $focus || trim((string)($o['document_id'] ?? '')) === $focus) {
            $featured = $o;
            break;
        }
    }
}
if ($featured === null) {
    foreach ($allOrders as $o) {
        $ps = fixarivan_normalize_public_status($o['public_status'] ?? $o['order_status'] ?? null);
        if (!in_array($ps, ['done', 'delivered'], true)) {
            $featured = $o;
            break;
        }
    }
}
if ($featured === null) {
    foreach ($allOrders as $o) {
        if (trim((string)($o['client_token'] ?? '')) === $token) {
            $featured = $o;
            break;
        }
    }
}
if ($featured === null && $allOrders !== []) {
    $featured = $allOrders[0];
}
if ($featured === null) {
    $featured = $order;
}

$featDocId = trim((string) ($featured['document_id'] ?? ''));
if ($featDocId !== '') {
    $rowUnified = fixarivan_load_order_from_sqlite_or_json($pdo, $featDocId);
    if ($rowUnified !== []) {
        $featured = $rowUnified;
    }
}

$viewerLang = 'ru';
$qLang = strtolower(trim((string)($_GET['lang'] ?? '')));
if ($qLang === 'en' || $qLang === 'fi' || $qLang === 'ru') {
    $viewerLang = $qLang;
} else {
    $l = strtolower(trim((string)($featured['language'] ?? '')));
    if ($l === 'en' || $l === 'fi' || $l === 'ru') {
        $viewerLang = $l;
    }
}

$clientPhoneDisplay = fixarivan_format_phone_fi_display(fixarivan_normalize_phone($clientPhone));
if ($clientPhoneDisplay === '') {
    $clientPhoneDisplay = $clientPhone;
}

$featOrderId = trim((string)($featured['order_id'] ?? ''));
if ($featOrderId === '') {
    $featOrderId = (string)($featured['document_id'] ?? '');
}
$otherOrders = [];
foreach ($allOrders as $o) {
    $oid = trim((string)($o['order_id'] ?? '')) !== '' ? trim((string)$o['order_id']) : (string)($o['document_id'] ?? '');
    if ($oid === '' || $oid === $featOrderId) {
        continue;
    }
    $otherOrders[] = $o;
}

$featPs = fixarivan_normalize_public_status($featured['public_status'] ?? $featured['order_status'] ?? null);
$featLines = fixarivan_portal_public_order_lines((string)($featured['order_lines_json'] ?? '[]'));
$featPartsSt = fixarivan_normalize_parts_status($featured['parts_status'] ?? null);

$orderTerminal = in_array($featPs, ['done', 'delivered'], true);
$publicCompletedRaw = trim((string)($featured['public_completed_at'] ?? ''));
if ($orderTerminal && $publicCompletedRaw === '') {
    $du = trim((string)($featured['date_updated'] ?? ''));
    if ($du !== '') {
        $ts = strtotime($du);
        if ($ts !== false) {
            $publicCompletedRaw = date('Y-m-d', $ts);
        }
    }
}
$publicCompletedDisplay = $orderTerminal ? fixarivan_portal_format_completion_date($viewerLang, $publicCompletedRaw) : '';

$linesSum = fixarivan_orders_estimate_from_lines_json((string)($featured['order_lines_json'] ?? '[]')) ?? 0.0;

$publicComment = trim((string)($featured['public_comment'] ?? ''));
$publicExpected = trim((string)($featured['public_expected_date'] ?? ''));
$publicEstimatedCost = trim((string)($featured['public_estimated_cost'] ?? ''));
$orderType = trim((string)($featured['order_type'] ?? 'repair'));
$orderTypeMeta = fixarivan_portal_order_type_meta($viewerLang, $orderType);
$featDevice = trim((string)($featured['device_model'] ?? ''));
$featDisplayName = fixarivan_portal_order_display_name($featured);
$featProblem = trim((string)($featured['problem_description'] ?? ''));

$workAmountNum = null;
if ($publicEstimatedCost !== '') {
    $norm = str_replace(["\xc2\xa0", ' '], '', $publicEstimatedCost);
    if (preg_match('/([\d]+(?:[.,]\d+)?)/u', $norm, $wm)) {
        $workAmountNum = (float)str_replace(',', '.', $wm[1]);
    }
}
$grandTotalNum = $linesSum + ($workAmountNum !== null ? $workAmountNum : 0.0);

$publicEstimatedCostDisplay = $publicEstimatedCost !== '' ? fixarivan_portal_format_money_line($publicEstimatedCost) : '';
$pubStatusMeta = fixarivan_portal_client_public_status_meta($viewerLang, $orderType, $featPs);
$partsStatusMeta = fixarivan_portal_client_parts_status_meta($viewerLang, $orderType, $featPartsSt);
$showPartsStatusChip = trim((string)($partsStatusMeta['label'] ?? '')) !== '';

$featuredDocs = fixarivan_filter_documents_for_order($receipts, $invoices, $reports, $featured);
$receiptsForOrder = $featuredDocs['receipts'];
$invoicesForOrder = $featuredDocs['invoices'];
$reportsForOrder = $featuredDocs['reports'];

$pubChipSlug = fixarivan_portal_status_class_slug((string)($pubStatusMeta['slug'] ?? 'unknown'));
$partsChipSlug = fixarivan_portal_status_class_slug((string)($partsStatusMeta['slug'] ?? 'unknown'));

$tr = fixarivan_viewer_portal_ui($viewerLang);
$companyProfile = fixarivan_company_profile_load();
$companyName = trim((string)($companyProfile['company_name'] ?? 'FixariVan')) ?: 'FixariVan';
$companyAddress = trim((string)($companyProfile['company_address'] ?? 'Turku, Finland')) ?: 'Turku, Finland';
$companyPhone = trim((string)($companyProfile['company_phone'] ?? '')) ?: '+358 44 954 5263';
$companyPhoneDigits = preg_replace('/\D+/', '', $companyPhone) ?? '';
$whatsAppUrl = $companyPhoneDigits !== '' ? 'https://wa.me/' . $companyPhoneDigits : '';
$clientAvatarText = trim((string)mb_strtoupper(mb_substr($clientName !== '' ? $clientName : $companyName, 0, 1)));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($viewerLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($tr['page_title']) ?></title>
    <style>
        html {
            -webkit-text-size-adjust: 100%;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at 18% 8%, rgba(59,130,246,0.12) 0%, transparent 28%),
                radial-gradient(circle at 82% 12%, rgba(139,92,246,0.12) 0%, transparent 26%),
                radial-gradient(circle at 50% 72%, rgba(99,102,241,0.10) 0%, transparent 24%),
                radial-gradient(circle at 14% 24%, rgba(255,255,255,0.12) 0, rgba(255,255,255,0.0) 2px),
                radial-gradient(circle at 77% 20%, rgba(255,255,255,0.10) 0, rgba(255,255,255,0.0) 2px),
                radial-gradient(circle at 66% 84%, rgba(255,255,255,0.10) 0, rgba(255,255,255,0.0) 2px),
                radial-gradient(circle at 31% 88%, rgba(255,255,255,0.14) 0, rgba(255,255,255,0.0) 2px),
                #0b1220;
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            color: #e5e7eb;
            overflow-x: hidden;
            padding-bottom: max(16px, env(safe-area-inset-bottom, 0px));
        }
        .wrap {
            max-width: 760px;
            margin: 0 auto;
            padding: 20px 14px max(40px, env(safe-area-inset-bottom, 16px));
            padding-left: max(14px, env(safe-area-inset-left, 0px));
            padding-right: max(14px, env(safe-area-inset-right, 0px));
            width: 100%;
            box-sizing: border-box;
            min-width: 0;
        }
        .card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            padding: 18px 16px;
            margin-bottom: 14px;
            backdrop-filter: blur(6px);
            min-width: 0;
        }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        h2 { font-size: 1.1rem; margin: 0 0 10px; }
        .muted { color: #a5b4fc; font-size: 0.9rem; }
        .row { margin: 8px 0; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            min-height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            margin: 4px 8px 4px 0;
            border: none;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(79,70,229,0.18);
            box-sizing: border-box;
            line-height: 1.25;
        }
        .btn-secondary { background: rgba(255,255,255,0.09); border: 1px solid rgba(255,255,255,0.12); }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.95rem; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid rgba(255,255,255,0.12); }
        th { color: #c7d2fe; font-weight: 600; }
        .chip-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.86rem;
            font-weight: 600;
            border: 1px solid transparent;
            line-height: 1.25;
        }
        .chip-ico { font-size: 1.05rem; line-height: 1; flex-shrink: 0; }
        .chip-pub--accepted { background: rgba(59, 130, 246, 0.22); border-color: rgba(147, 197, 253, 0.38); color: #dbeafe; box-shadow: 0 0 10px rgba(59,130,246,0.12); }
        .chip-pub--waiting { background: rgba(245, 158, 11, 0.22); border-color: rgba(251, 191, 36, 0.38); color: #fef3c7; box-shadow: 0 0 10px rgba(245,158,11,0.10); }
        .chip-pub--ordered { background: rgba(6, 182, 212, 0.20); border-color: rgba(103, 232, 249, 0.34); color: #cffafe; box-shadow: 0 0 10px rgba(6,182,212,0.10); }
        .chip-pub--processing { background: rgba(59, 130, 246, 0.22); border-color: rgba(147, 197, 253, 0.38); color: #dbeafe; box-shadow: 0 0 10px rgba(59,130,246,0.12); }
        .chip-pub--ready { background: rgba(34, 197, 94, 0.24); border-color: rgba(74, 222, 128, 0.4); color: #dcfce7; box-shadow: 0 0 10px rgba(34,197,94,0.12); }
        .chip-pub--received { background: rgba(45, 212, 191, 0.20); border-color: rgba(94, 234, 212, 0.34); color: #ccfbf1; box-shadow: 0 0 10px rgba(45,212,191,0.10); }
        .chip-pub--delivered { background: rgba(139, 92, 246, 0.22); border-color: rgba(196, 181, 253, 0.38); color: #ede9fe; box-shadow: 0 0 10px rgba(139,92,246,0.12); }
        .chip-pub--unknown { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.2); color: #e5e7eb; }
        .chip-parts--ordered { background: rgba(100, 116, 139, 0.24); border-color: rgba(148, 163, 184, 0.34); color: #e2e8f0; box-shadow: 0 0 10px rgba(148,163,184,0.10); }
        .chip-parts--waiting { background: rgba(245, 158, 11, 0.22); border-color: rgba(251, 191, 36, 0.38); color: #fef3c7; box-shadow: 0 0 10px rgba(245,158,11,0.10); }
        .chip-parts--processing { background: rgba(59, 130, 246, 0.22); border-color: rgba(147, 197, 253, 0.38); color: #dbeafe; box-shadow: 0 0 10px rgba(59,130,246,0.12); }
        .chip-parts--received { background: rgba(45, 212, 191, 0.20); border-color: rgba(94, 234, 212, 0.34); color: #ccfbf1; box-shadow: 0 0 10px rgba(45,212,191,0.10); }
        .chip-parts--ready { background: rgba(34, 197, 94, 0.24); border-color: rgba(74, 222, 128, 0.4); color: #dcfce7; box-shadow: 0 0 10px rgba(34,197,94,0.12); }
        .chip-parts--unknown { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.18); color: #e5e7eb; }
        .chip-kind--repair { background: rgba(99, 102, 241, 0.18); border-color: rgba(165, 180, 252, 0.28); color: #e0e7ff; }
        .chip-kind--sale { background: rgba(20, 184, 166, 0.18); border-color: rgba(94, 234, 212, 0.28); color: #ccfbf1; }
        .chip-kind--custom { background: rgba(245, 158, 11, 0.18); border-color: rgba(253, 186, 116, 0.30); color: #fef3c7; }
        .order-mini { border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 14px; margin-bottom: 10px; background: rgba(255,255,255,0.03); }
        .order-mini strong { font-size: 0.98rem; }
        .order-mini--repair { box-shadow: inset 0 0 0 1px rgba(99,102,241,0.08); }
        .order-mini--sale { box-shadow: inset 0 0 0 1px rgba(20,184,166,0.08); background: rgba(20,184,166,0.04); }
        .order-mini--custom { box-shadow: inset 0 0 0 1px rgba(245,158,11,0.08); background: rgba(245,158,11,0.04); }
        .portal-crumb { font-size: 0.82rem; color: #a5b4fc; margin-bottom: 10px; line-height: 1.4; }
        .portal-crumb span { opacity: 0.85; }
        .client-card { display:flex; align-items:center; gap:16px; }
        .client-avatar {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            display:flex;
            align-items:center;
            justify-content:center;
            background: linear-gradient(135deg, rgba(99,102,241,0.9), rgba(124,58,237,0.85));
            color:#fff;
            font-size: 1.2rem;
            font-weight: 800;
            box-shadow: 0 8px 22px rgba(99,102,241,0.18);
            flex-shrink: 0;
        }
        .client-name { font-size: 1.55rem; font-weight: 800; color: #f8fafc; margin: 0 0 6px; }
        .client-meta-line { color:#cbd5e1; font-size:0.94rem; }
        .orders-stack { display:grid; gap:10px; }
        .order-mini-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:10px; }
        .order-mini-title { font-size: 1rem; font-weight: 700; color: #f8fafc; }
        .order-mini-meta { color:#94a3b8; font-size:0.88rem; margin-top:4px; }
        .order-mini-actions { margin-top: 10px; }
        .order-mini-actions .btn { margin-right: 0; }
        .order-mini-current.order-mini--repair { box-shadow: inset 0 0 0 1px rgba(129,140,248,0.24); background: rgba(99,102,241,0.08); }
        .order-mini-current.order-mini--sale { box-shadow: inset 0 0 0 1px rgba(45,212,191,0.24); background: rgba(20,184,166,0.10); }
        .order-mini-current.order-mini--custom { box-shadow: inset 0 0 0 1px rgba(251,191,36,0.24); background: rgba(245,158,11,0.10); }
        .other-orders-details summary {
            list-style: none;
            cursor: pointer;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }
        .other-orders-details summary::-webkit-details-marker { display:none; }
        .other-orders-summary { display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; }
        .other-orders-title { font-size:1.02rem; font-weight:700; color:#f8fafc; }
        .other-orders-caret { color:#a5b4fc; font-size:0.9rem; transition: transform 0.2s ease; }
        .other-orders-details[open] .other-orders-caret { transform: rotate(180deg); }
        .other-orders-list { display:grid; gap:10px; margin-top:14px; }
        .other-order-item {
            display:grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap:12px;
            align-items:center;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px;
            padding:12px 14px;
            background: rgba(255,255,255,0.03);
        }
        .other-order-title { font-size:0.98rem; font-weight:700; color:#f8fafc; }
        .other-order-meta { color:#94a3b8; font-size:0.86rem; margin-top:4px; }
        .other-order-status { margin-top:8px; }
        .order-featured--repair { box-shadow: inset 0 0 0 1px rgba(99,102,241,0.12); }
        .order-featured--sale { box-shadow: inset 0 0 0 1px rgba(20,184,166,0.14); background: rgba(20,184,166,0.035); }
        .order-featured--custom { box-shadow: inset 0 0 0 1px rgba(245,158,11,0.14); background: rgba(245,158,11,0.03); }
        .order-hero-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:8px; }
        .order-hero-title { font-size:1.22rem; font-weight:800; color:#f8fafc; margin:0; }
        .order-hero-sub { color:#94a3b8; font-size:0.9rem; margin-top:4px; }
        .order-top-meta {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }
        .order-top-meta-item {
            color: #cbd5e1;
            font-size: 0.93rem;
            line-height: 1.45;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .money-pill {
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 12px;
            border-radius:999px;
            background: rgba(16,185,129,0.12);
            border:1px solid rgba(16,185,129,0.18);
            color:#d1fae5;
            font-size:1rem;
            font-weight:700;
            box-shadow: 0 0 12px rgba(16,185,129,0.08);
            white-space: nowrap;
            max-width: 100%;
            box-sizing: border-box;
        }
        .money-pill-amount { color:#f0fdf4; font-size:1.08rem; }
        .comment-card {
            margin-top: 14px;
            padding: 14px 16px;
            border-radius: 12px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .comment-title { margin:0 0 8px; font-size:0.95rem; font-weight:700; color:#e5e7eb; }
        .comment-body { color:#cbd5e1; line-height:1.5; font-size:0.94rem; word-break: break-word; overflow-wrap: anywhere; }
        .doc-btn-wrap { display:flex; flex-wrap:wrap; gap:8px; }
        .doc-btn-wrap .btn { margin:0; }
        .detail-shell {
            margin-top: 16px;
            display: grid;
            gap: 14px;
        }
        .detail-card {
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 14px;
            padding: 14px 16px;
            background: rgba(255,255,255,0.03);
            min-width: 0;
        }
        .portal-lines-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -4px;
            padding: 0 4px;
            max-width: 100%;
        }
        .detail-card-title {
            margin: 0 0 10px;
            font-size: 0.94rem;
            font-weight: 700;
            color: #c7d2fe;
        }
        .portal-lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            font-size: 0.92rem;
        }
        .portal-lines-table th,
        .portal-lines-table td {
            padding: 10px 6px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .portal-lines-table th {
            color: #94a3b8;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
        }
        .portal-lines-table tbody tr:last-child td {
            border-bottom: none;
        }
        .portal-lines-table td:nth-child(2),
        .portal-lines-table td:nth-child(3),
        .portal-lines-table td:nth-child(4),
        .portal-lines-table th:nth-child(2),
        .portal-lines-table th:nth-child(3),
        .portal-lines-table th:nth-child(4) {
            text-align: right;
            white-space: nowrap;
        }
        /* Наименование: перенос по словам; без hyphens:auto — меньше артефактов в брендах/артикулах */
        .line-name-main {
            color: #f8fafc;
            font-weight: 600;
            word-break: normal;
            overflow-wrap: break-word;
            line-height: 1.45;
        }
        .portal-lines-table .line-name-cell {
            vertical-align: top;
        }
        .totals-panel {
            display: grid;
            gap: 10px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
            color: #cbd5e1;
            font-size: 0.94rem;
        }
        .totals-row strong {
            color: #f8fafc;
        }
        .totals-grand {
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,0.08);
            font-size: 1.02rem;
            font-weight: 700;
        }
        .trust-card { text-align:center; }
        .trust-title { font-weight:700; color:#f8fafc; margin-bottom:8px; word-break: break-word; line-height: 1.35; }
        .trust-meta { color:#cbd5e1; font-size:0.92rem; line-height:1.6; }
        .trust-actions { margin-top:10px; display:flex; justify-content:center; flex-wrap:wrap; gap:8px; }
        @media (max-width: 768px) {
            .client-card, .order-hero-head, .order-mini-head { display:block; }
            .client-avatar { margin-bottom: 12px; }
            .order-hero-head .money-pill {
                margin-top: 12px;
                white-space: normal;
                justify-content: center;
                text-align: center;
            }
            .order-hero-title { word-break: break-word; hyphens: auto; }
            .totals-row { display:block; }
            .totals-row span:last-child,
            .totals-row strong:last-child { display:block; margin-top:4px; }
            .other-order-item { grid-template-columns: 1fr; }
            .other-order-item > div:last-child .btn { width: 100%; text-align: center; box-sizing: border-box; }
            .detail-card {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .portal-lines-table td:nth-child(1),
            .portal-lines-table th:nth-child(1) {
                white-space: normal;
                word-break: normal;
                overflow-wrap: break-word;
                max-width: min(58vw, 260px);
                vertical-align: top;
            }
            .comment-body {
                max-height: 11rem;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
            .doc-btn-wrap {
                display: grid;
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .doc-btn-wrap .btn {
                width: 100%;
                margin: 0;
                text-align: center;
                box-sizing: border-box;
                justify-content: center;
            }
            .card h2 { font-size: 1.08rem; word-break: break-word; }
            .row .btn { display: block; width: 100%; text-align: center; box-sizing: border-box; margin-left: 0; margin-right: 0; }
            .trust-actions { flex-direction: column; align-items: stretch; }
            .trust-actions .btn { width: 100%; text-align: center; }
            .chip-row { gap: 6px; }
        }
        @media (min-width: 769px) {
            .comment-body { max-height: none; overflow-y: visible; }
        }
        @media (max-width: 420px) {
            .wrap { padding: 16px 12px max(36px, env(safe-area-inset-bottom, 12px)); }
            .chip { font-size: 0.82rem; padding: 6px 10px; }
            .client-name { font-size: 1.38rem; }
            .order-hero-title { font-size: 1.1rem; }
        }

        /* Пасхалка: змейка (модалка) */
        .portal-snake-fab {
            position: fixed;
            z-index: 40;
            right: max(12px, env(safe-area-inset-right, 0px));
            bottom: max(14px, env(safe-area-inset-bottom, 0px));
            width: 48px;
            height: 48px;
            border-radius: 14px;
            border: 1px solid rgba(99, 102, 241, 0.45);
            background: rgba(99, 102, 241, 0.22);
            color: #e0e7ff;
            font-size: 1.35rem;
            cursor: pointer;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease, background 0.15s ease;
        }
        .portal-snake-fab:hover {
            background: rgba(99, 102, 241, 0.35);
            transform: scale(1.04);
        }
        .portal-snake-modal[hidden] {
            display: none !important;
        }
        .portal-snake-modal {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(12px, env(safe-area-inset-left)) max(12px, env(safe-area-inset-right))
                max(12px, env(safe-area-inset-bottom)) max(12px, env(safe-area-inset-left));
        }
        .portal-snake-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(2, 6, 23, 0.72);
            backdrop-filter: blur(4px);
        }
        .portal-snake-modal__panel {
            position: relative;
            width: 100%;
            max-width: 380px;
            max-height: min(92vh, 720px);
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            background: rgba(15, 23, 42, 0.96);
            border: 1px solid rgba(99, 102, 241, 0.35);
            border-radius: 18px;
            padding: 16px 16px 18px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
        }
        .portal-snake-modal__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .portal-snake-modal__head h2 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
            color: #f8fafc;
            line-height: 1.25;
        }
        .portal-snake-modal__close {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            color: #e2e8f0;
            font-size: 1.4rem;
            line-height: 1;
            cursor: pointer;
        }
        .portal-snake-modal__hint {
            margin: 0 0 10px;
            font-size: 0.78rem;
            color: #94a3b8;
            line-height: 1.4;
        }
        .portal-snake-modal__stats {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.82rem;
            color: #cbd5e1;
            margin-bottom: 8px;
        }
        .portal-snake-modal__stats strong {
            color: #a5b4fc;
        }
        .portal-snake-toast {
            margin-bottom: 8px;
            padding: 8px 10px;
            border-radius: 10px;
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid rgba(34, 197, 94, 0.45);
            color: #bbf7d0;
            font-size: 0.82rem;
            font-weight: 700;
            text-align: center;
        }
        .portal-snake-toast[hidden] {
            display: none !important;
        }
        .portal-snake-canvas-wrap {
            position: relative;
            width: 100%;
            max-width: min(100%, 320px);
            margin: 0 auto;
            aspect-ratio: 1 / 1;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: #070b14;
        }
        .portal-snake-canvas-wrap canvas {
            display: block;
            width: 100%;
            height: 100%;
        }
        .portal-snake-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            justify-content: center;
        }
        .portal-snake-toolbar label {
            font-size: 0.78rem;
            color: #94a3b8;
        }
        .portal-snake-toolbar select {
            font-size: 16px;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid rgba(99, 102, 241, 0.4);
            background: rgba(15, 23, 42, 0.9);
            color: #e2e8f0;
        }
        .portal-snake-dpad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            max-width: 220px;
            margin: 10px auto 0;
        }
        .portal-snake-dpad-spacer {
            visibility: hidden;
            pointer-events: none;
        }
        .portal-snake-dir-btn {
            min-height: 44px;
            border-radius: 10px;
            border: 1px solid rgba(99, 102, 241, 0.45);
            background: rgba(99, 102, 241, 0.2);
            color: #e0e7ff;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
        }
        .portal-snake-dir-btn:disabled {
            opacity: 0.45;
        }
        .portal-snake-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-top: 10px;
        }
        .portal-snake-actions button {
            min-height: 42px;
            padding: 0 14px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid rgba(34, 197, 94, 0.45);
            background: rgba(34, 197, 94, 0.18);
            color: #dcfce7;
        }
        .portal-snake-actions button.secondary {
            border-color: rgba(148, 163, 184, 0.4);
            background: rgba(148, 163, 184, 0.12);
            color: #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="client-card">
                <div class="client-avatar" aria-hidden="true"><?= htmlspecialchars($clientAvatarText !== '' ? $clientAvatarText : 'F') ?></div>
                <div>
                    <div class="muted"><?= htmlspecialchars($tr['client']) ?></div>
                    <div class="client-name"><?= htmlspecialchars($clientName !== '' ? $clientName : '—') ?></div>
                    <div class="client-meta-line"><?= htmlspecialchars($clientPhoneDisplay !== '' ? $clientPhoneDisplay : '—') ?><?= $clientEmail !== '' ? ' · ' . htmlspecialchars($clientEmail) : '' ?></div>
                </div>
            </div>
        </div>

        <div class="card order-featured--<?= htmlspecialchars($orderTypeMeta['slug']) ?>">
            <div class="order-hero-head">
                <div>
                    <h2 class="order-hero-title"><?= htmlspecialchars($featDisplayName) ?></h2>
                    <div class="order-hero-sub"><?= htmlspecialchars($featOrderId) ?></div>
                </div>
                <?php if ($featLines !== [] || $workAmountNum !== null) { ?>
                    <div class="money-pill"><span aria-hidden="true">💰</span><span class="money-pill-amount"><?= htmlspecialchars(number_format($grandTotalNum, 2, ',', ' ')) ?> €</span></div>
                <?php } ?>
            </div>
            <div class="chip-row">
                <span class="chip chip-kind chip-kind--<?= htmlspecialchars($orderTypeMeta['slug']) ?>">
                    <span class="chip-ico" aria-hidden="true"><?= htmlspecialchars($orderTypeMeta['icon']) ?></span>
                    <span><?= htmlspecialchars($orderTypeMeta['label']) ?></span>
                </span>
                <span class="chip chip-pub chip-pub--<?= htmlspecialchars($pubChipSlug) ?>">
                    <span class="chip-ico" aria-hidden="true"><?= htmlspecialchars($pubStatusMeta['icon']) ?></span>
                    <span><?= htmlspecialchars($pubStatusMeta['label']) ?></span>
                </span>
                <?php if ($showPartsStatusChip) { ?>
                    <span class="chip chip-parts chip-parts--<?= htmlspecialchars($partsChipSlug) ?>">
                        <span class="chip-ico" aria-hidden="true"><?= htmlspecialchars($partsStatusMeta['icon']) ?></span>
                        <span><?= htmlspecialchars($partsStatusMeta['label']) ?></span>
                    </span>
                <?php } ?>
            </div>
            <?php if ($featProblem !== '' || ($orderTerminal && $publicCompletedDisplay !== '') || (!$orderTerminal && $publicExpected !== '')) { ?>
                <div class="order-top-meta">
                    <?php if ($featProblem !== '') { ?>
                        <div class="order-top-meta-item"><?= nl2br(htmlspecialchars($featProblem)) ?></div>
                    <?php } ?>
                    <?php if ($orderTerminal && $publicCompletedDisplay !== '') { ?>
                        <div class="order-top-meta-item"><strong><?= htmlspecialchars($tr['completed_on']) ?>:</strong> <?= htmlspecialchars($publicCompletedDisplay) ?></div>
                    <?php } elseif (!$orderTerminal && $publicExpected !== '') { ?>
                        <div class="order-top-meta-item"><strong><?= htmlspecialchars($tr['expected']) ?>:</strong> <?= htmlspecialchars($publicExpected) ?></div>
                    <?php } ?>
                </div>
            <?php } ?>
            <?php if ($publicComment !== '') { ?>
                <div class="comment-card">
                    <div class="comment-title">💬 <?= htmlspecialchars($tr['comment_title']) ?></div>
                    <div class="comment-body"><?= nl2br(htmlspecialchars($publicComment)) ?></div>
                </div>
            <?php } ?>

            <?php if ($featLines !== [] || $publicEstimatedCost !== '') { ?>
                <div class="detail-shell">
                    <?php if ($featLines !== []) { ?>
                        <div class="detail-card">
                            <h3 class="detail-card-title"><?= htmlspecialchars($tr['detail_section']) ?></h3>
                            <div class="portal-lines-scroll">
                            <table class="portal-lines-table">
                                <thead>
                                    <tr>
                                        <th><?= htmlspecialchars($tr['name']) ?></th>
                                        <th><?= htmlspecialchars($tr['qty']) ?></th>
                                        <th><?= htmlspecialchars($tr['price']) ?> (€)</th>
                                        <th><?= htmlspecialchars($tr['line_sum']) ?> (€)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($featLines as $ln) {
                                        $lineTot = $ln['sale'] * $ln['qty'];
                                        ?>
                                        <tr>
                                            <td class="line-name-cell"><span class="line-name-main"><?= htmlspecialchars($ln['name']) ?></span></td>
                                            <td><?= htmlspecialchars((string)$ln['qty']) ?></td>
                                            <td><?= htmlspecialchars(number_format($ln['sale'], 2, ',', ' ')) ?></td>
                                            <td><?= htmlspecialchars(number_format($lineTot, 2, ',', ' ')) ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    <?php } ?>
                    <?php if ($featLines !== [] || $publicEstimatedCost !== '' || $workAmountNum !== null) { ?>
                        <div class="detail-card">
                            <h3 class="detail-card-title"><?= htmlspecialchars($tr['grand_total']) ?></h3>
                            <div class="totals-panel">
                                <?php if ($featLines !== []) { ?>
                                    <div class="totals-row">
                                        <span><?= htmlspecialchars($tr['lines_subtotal']) ?></span>
                                        <strong><?= htmlspecialchars(number_format($linesSum, 2, ',', ' ')) ?> €</strong>
                                    </div>
                                <?php } ?>
                                <?php if ($publicEstimatedCost !== '') { ?>
                                    <div class="totals-row">
                                        <span><?= htmlspecialchars($tr['work_cost']) ?></span>
                                        <strong><?= htmlspecialchars($publicEstimatedCostDisplay) ?></strong>
                                    </div>
                                <?php } ?>
                                <?php if ($featLines !== [] || $workAmountNum !== null) { ?>
                                    <div class="totals-row totals-grand">
                                        <span><span aria-hidden="true">💰</span> <?= htmlspecialchars($tr['grand_total']) ?></span>
                                        <strong><?= htmlspecialchars(number_format($grandTotalNum, 2, ',', ' ')) ?> €</strong>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>

            <?php if ($orderTypeMeta['code'] !== 'sale') { ?>
                <div class="row" style="margin-top:12px;">
                    <a class="btn" href="<?= htmlspecialchars('order_view.php?' . http_build_query(['token' => trim((string)($featured['client_token'] ?? $token)), 'lang' => $viewerLang])) ?>"><?= htmlspecialchars($tr['view_act']) ?></a>
                </div>
            <?php } ?>
        </div>

        <?php /* Текущий заказ → документы */ ?>
        <?php if ($receiptsForOrder !== [] || $invoicesForOrder !== [] || $reportsForOrder !== []) { ?>
            <div class="card">
                <h2><?= htmlspecialchars($tr['documents']) ?> · <?= htmlspecialchars($featOrderId) ?></h2>
                <p class="muted" style="margin:0 0 12px;font-size:0.88rem;"><?= htmlspecialchars($tr['documents_order_hint']) ?></p>
                <?php if ($receiptsForOrder !== []) { ?>
                    <h3 style="margin:12px 0 8px;font-size:1rem;color:#c7d2fe;"><?= htmlspecialchars($tr['receipts']) ?></h3>
                    <div class="doc-btn-wrap">
                    <?php foreach ($receiptsForOrder as $r) {
                        $rt = trim((string)($r['client_token'] ?? ''));
                        if ($rt === '') {
                            continue;
                        }
                        $url = 'receipt_view.php?' . http_build_query(['token' => $rt, 'lang' => $viewerLang]);
                        $label = (string)($r['receipt_number'] ?? $r['document_id'] ?? '—');
                        $sum = isset($r['total_amount']) ? (float)$r['total_amount'] : 0.0;
                        $sumTxt = ' · ' . number_format($sum, 2, ',', ' ') . ' €';
                        ?>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($url) ?>">🧾 <?= htmlspecialchars($tr['view_receipt']) ?> · <?= htmlspecialchars($label . $sumTxt) ?></a>
                    <?php } ?>
                    </div>
                <?php } ?>
                <?php if ($invoicesForOrder !== []) { ?>
                    <h3 style="margin:12px 0 8px;font-size:1rem;color:#c7d2fe;"><?= htmlspecialchars($tr['invoices']) ?></h3>
                    <div class="doc-btn-wrap">
                    <?php foreach ($invoicesForOrder as $inv) {
                        $it = trim((string)($inv['client_token'] ?? ''));
                        if ($it === '') {
                            continue;
                        }
                        $url = 'invoice_view.php?' . http_build_query(['token' => $it, 'lang' => $viewerLang]);
                        $label = (string)($inv['invoice_id'] ?? $inv['document_id'] ?? '—');
                        $sum = isset($inv['total_amount']) ? (float)$inv['total_amount'] : 0.0;
                        $sumTxt = ' · ' . number_format($sum, 2, ',', ' ') . ' €';
                        $stInv = trim((string)($inv['status'] ?? ''));
                        $stExtra = $stInv !== '' ? ' (' . $stInv . ')' : '';
                        $invBtn = $label . $sumTxt . $stExtra;
                        ?>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($url) ?>">📄 <?= htmlspecialchars($tr['view_invoice']) ?> · <?= htmlspecialchars($invBtn) ?></a>
                    <?php } ?>
                    </div>
                <?php } ?>
                <?php if ($reportsForOrder !== []) { ?>
                    <h3 style="margin:12px 0 8px;font-size:1rem;color:#c7d2fe;"><?= htmlspecialchars($tr['reports']) ?></h3>
                    <div class="doc-btn-wrap">
                    <?php foreach ($reportsForOrder as $rep) {
                        $rt = trim((string)($rep['token'] ?? ''));
                        if ($rt === '') {
                            continue;
                        }
                        $url = 'report_view.php?' . http_build_query(['token' => $rt, 'lang' => $viewerLang]);
                        $label = (string)($rep['report_id'] ?? '—');
                        $mod = trim((string)($rep['model'] ?? ''));
                        $modTxt = $mod !== '' ? ' · ' . $mod : '';
                        ?>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($url) ?>">🔬 <?= htmlspecialchars($tr['view_report']) ?> · <?= htmlspecialchars($label) ?><?= htmlspecialchars($modTxt) ?></a>
                    <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if ($orphanReports !== []) { ?>
            <div class="card">
                <h2><?= htmlspecialchars($tr['orphan_reports']) ?></h2>
                <p class="muted" style="margin:0 0 12px;"><?= htmlspecialchars($tr['orphan_reports_hint']) ?></p>
                <div class="doc-btn-wrap">
                <?php foreach ($orphanReports as $rep) {
                    $rt = trim((string)($rep['token'] ?? ''));
                    if ($rt === '') {
                        continue;
                    }
                    $url = 'report_view.php?' . http_build_query(['token' => $rt, 'lang' => $viewerLang]);
                    $label = (string)($rep['report_id'] ?? '—');
                    $mod = trim((string)($rep['model'] ?? ''));
                    $modTxt = $mod !== '' ? ' · ' . $mod : '';
                    ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($url) ?>">🔬 <?= htmlspecialchars($tr['view_report']) ?> · <?= htmlspecialchars($label) ?><?= htmlspecialchars($modTxt) ?></a>
                <?php } ?>
                </div>
            </div>
        <?php } ?>

        <div class="card trust-card">
            <div class="trust-title"><?= htmlspecialchars($companyName) ?> • <?= htmlspecialchars($companyAddress) ?></div>
            <div class="trust-meta">📞 <?= htmlspecialchars($companyPhone) ?></div>
            <div class="trust-actions">
                <?php if ($companyPhone !== '') { ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars('tel:' . preg_replace('/\s+/', '', $companyPhone)) ?>">📞 <?= htmlspecialchars($tr['trust_call']) ?></a>
                <?php } ?>
                <?php if ($whatsAppUrl !== '') { ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($whatsAppUrl) ?>" target="_blank" rel="noopener">💬 <?= htmlspecialchars($tr['trust_whatsapp']) ?></a>
                <?php } ?>
            </div>
        </div>

        <?php if ($otherOrders !== []) { ?>
            <div class="card">
                <details class="other-orders-details">
                    <summary>
                        <div class="other-orders-summary">
                            <div class="other-orders-title"><?= htmlspecialchars($tr['other_orders']) ?> (<?= count($otherOrders) ?>)</div>
                            <div class="other-orders-caret">▼</div>
                        </div>
                    </summary>
                    <div class="other-orders-list">
                        <?php foreach ($otherOrders as $o) {
                            $oid = trim((string)($o['order_id'] ?? '')) !== '' ? trim((string)$o['order_id']) : (string)($o['document_id'] ?? '');
                            if ($oid === '') {
                                continue;
                            }
                            $switchQuery = ['token' => $token, 'focus_order' => $oid];
                            if ($hasExplicitLang) {
                                $switchQuery['lang'] = strtolower(trim($lang));
                            }
                            $qs = http_build_query($switchQuery);
                            $miniTypeMeta = fixarivan_portal_order_type_meta($viewerLang, $o['order_type'] ?? 'repair');
                            $miniPs = fixarivan_normalize_public_status($o['public_status'] ?? $o['order_status'] ?? null);
                            $miniPubMeta = fixarivan_portal_client_public_status_meta($viewerLang, $o['order_type'] ?? 'repair', $miniPs);
                            $miniPubSlug = fixarivan_portal_status_class_slug((string)($miniPubMeta['slug'] ?? 'unknown'));
                            $miniDisplayName = fixarivan_portal_order_display_name($o);
                            ?>
                            <div class="other-order-item">
                                <div>
                                    <div class="other-order-title"><?= htmlspecialchars($miniDisplayName) ?></div>
                                    <div class="other-order-meta"><?= htmlspecialchars($oid) ?></div>
                                    <div class="chip-row other-order-status">
                                        <span class="chip chip-kind chip-kind--<?= htmlspecialchars($miniTypeMeta['slug']) ?>">
                                            <span class="chip-ico" aria-hidden="true"><?= htmlspecialchars($miniTypeMeta['icon']) ?></span>
                                            <span><?= htmlspecialchars($miniTypeMeta['label']) ?></span>
                                        </span>
                                        <span class="chip chip-pub chip-pub--<?= htmlspecialchars($miniPubSlug) ?>">
                                            <span class="chip-ico" aria-hidden="true"><?= htmlspecialchars($miniPubMeta['icon']) ?></span>
                                            <span><?= htmlspecialchars($miniPubMeta['label']) ?></span>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <a class="btn btn-secondary" href="client_portal.php?<?= htmlspecialchars($qs) ?>"><?= htmlspecialchars($tr['open_order']) ?></a>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </details>
            </div>
        <?php } ?>
    </div>

    <button type="button" class="portal-snake-fab" id="portalSnakeFab" aria-label="<?= htmlspecialchars($tr['snake_fab']) ?>">🎮</button>
    <div class="portal-snake-modal" id="portalSnakeModal" hidden>
        <div class="portal-snake-modal__backdrop" id="portalSnakeBackdrop"></div>
        <div class="portal-snake-modal__panel" role="dialog" aria-modal="true" aria-labelledby="portalSnakeTitle">
            <div class="portal-snake-modal__head">
                <h2 id="portalSnakeTitle"><?= htmlspecialchars($tr['snake_title']) ?></h2>
                <button type="button" class="portal-snake-modal__close" id="portalSnakeClose" aria-label="<?= htmlspecialchars($tr['snake_close']) ?>">&times;</button>
            </div>
            <p class="portal-snake-modal__hint"><?= htmlspecialchars($tr['snake_hint']) ?></p>
            <div class="portal-snake-modal__stats">
                <span><?= htmlspecialchars($tr['snake_global']) ?>: <strong id="portalSnakeGlobalBest">—</strong></span>
                <span><?= htmlspecialchars($tr['snake_yours']) ?>: <strong id="portalSnakeYourScore">0</strong></span>
            </div>
            <div class="portal-snake-toast" id="portalSnakeToast" hidden></div>
            <div class="portal-snake-canvas-wrap" id="portalSnakeCanvasWrap">
                <canvas id="portalSnakeCanvas" width="300" height="300" aria-hidden="true"></canvas>
            </div>
            <div class="portal-snake-toolbar">
                <label for="portalSnakeSpeed"><?= htmlspecialchars($tr['snake_speed']) ?></label>
                <select id="portalSnakeSpeed" aria-label="<?= htmlspecialchars($tr['snake_speed']) ?>">
                    <option value="slow"><?= htmlspecialchars($tr['snake_slow']) ?></option>
                    <option value="normal" selected><?= htmlspecialchars($tr['snake_normal']) ?></option>
                    <option value="fast"><?= htmlspecialchars($tr['snake_fast']) ?></option>
                </select>
            </div>
            <div class="portal-snake-dpad">
                <span class="portal-snake-dpad-spacer" aria-hidden="true"></span>
                <button type="button" class="portal-snake-dir-btn" id="portalSnakeUp" aria-label="↑">↑</button>
                <span class="portal-snake-dpad-spacer" aria-hidden="true"></span>
                <button type="button" class="portal-snake-dir-btn" id="portalSnakeLeft" aria-label="←">←</button>
                <span class="portal-snake-dpad-spacer" aria-hidden="true"></span>
                <button type="button" class="portal-snake-dir-btn" id="portalSnakeRight" aria-label="→">→</button>
                <span class="portal-snake-dpad-spacer" aria-hidden="true"></span>
                <button type="button" class="portal-snake-dir-btn" id="portalSnakeDown" aria-label="↓">↓</button>
                <span class="portal-snake-dpad-spacer" aria-hidden="true"></span>
            </div>
            <div class="portal-snake-actions">
                <button type="button" id="portalSnakeStart"><?= htmlspecialchars($tr['snake_start']) ?></button>
                <button type="button" class="secondary" id="portalSnakePause" disabled><?= htmlspecialchars($tr['snake_pause']) ?></button>
            </div>
        </div>
    </div>
    <script>
        window.__FIXARIVAN_PORTAL_SNAKE__ = <?= json_encode([
            'token' => $token,
            'api' => 'api/portal_snake_score.php',
            'i18n' => [
                'newRecord' => $tr['snake_new_record'],
                'loadError' => $tr['snake_load_error'],
                'start' => $tr['snake_start'],
                'again' => $tr['snake_again'],
                'pause' => $tr['snake_pause'],
                'resume' => $tr['snake_resume'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="js/portal_snake.js" defer></script>
</body>
</html>
