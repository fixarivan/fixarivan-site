<?php
declare(strict_types=1);

require_once __DIR__ . '/order_center.php';
require_once __DIR__ . '/order_supply.php';
require_once __DIR__ . '/invoice_center.php';
require_once __DIR__ . '/crm_audit.php';
require_once __DIR__ . '/security_settings.php';
require_once __DIR__ . '/bot_lead.php';

/** @return array<string,mixed>|null */
function fixarivan_order_ops_fetch(PDO $pdo, string $documentId): ?array
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE document_id = :d LIMIT 1');
    $stmt->execute([':d' => $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function fixarivan_order_ops_is_archived(?array $row): bool
{
    if (!is_array($row)) {
        return false;
    }
    $deletedAt = trim((string) ($row['deleted_at'] ?? ''));

    return $deletedAt !== '';
}

/** @return list<string> */
function fixarivan_order_ops_id_variants(PDO $pdo, array $row): array
{
    $documentId = trim((string) ($row['document_id'] ?? ''));
    $orderId = trim((string) ($row['order_id'] ?? ''));
    if (function_exists('fixarivan_order_id_variants_for_pdo')) {
        $variants = fixarivan_order_id_variants_for_pdo($pdo, $documentId, $orderId);
        if ($variants !== []) {
            return $variants;
        }
    }
    $out = array_values(array_unique(array_filter([$orderId, $documentId])));

    return $out !== [] ? $out : [$documentId];
}

/**
 * @return array{invoices:int,receipts:int,reports:int,invoice_ids:list<string>,blocking:bool,reasons:list<string>}
 */
function fixarivan_order_ops_linked_summary(PDO $pdo, array $row): array
{
    $variants = fixarivan_order_ops_id_variants($pdo, $row);
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $invoiceIds = [];
    $invCount = 0;
    $recCount = 0;
    $repCount = 0;
    if ($placeholders !== '') {
        $invStmt = $pdo->prepare("SELECT document_id, status FROM invoices WHERE order_id IN ($placeholders)");
        $invStmt->execute($variants);
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $inv) {
            $invCount++;
            $invoiceIds[] = (string) ($inv['document_id'] ?? '');
        }
        $recStmt = $pdo->prepare("SELECT COUNT(*) FROM receipts WHERE order_id IN ($placeholders)");
        $recStmt->execute($variants);
        $recCount = (int) $recStmt->fetchColumn();
        $repStmt = $pdo->prepare("SELECT COUNT(*) FROM mobile_reports WHERE order_id IN ($placeholders)");
        $repStmt->execute($variants);
        $repCount = (int) $repStmt->fetchColumn();
    }
    $reasons = [];
    if ($invCount > 0) {
        $reasons[] = 'Связанные счета: ' . $invCount;
    }
    if ($recCount > 0) {
        $reasons[] = 'Связанные квитанции: ' . $recCount;
    }
    if ($repCount > 0) {
        $reasons[] = 'Связанные отчёты: ' . $repCount;
    }
    $signedAt = trim((string) ($row['signed_at'] ?? ''));
    if ($signedAt !== '') {
        $reasons[] = 'Акт подписан клиентом';
    }
    $status = fixarivan_normalize_public_status($row['order_status'] ?? $row['public_status'] ?? null);
    if (in_array($status, ['delivered'], true) && ($invCount > 0 || $recCount > 0)) {
        $reasons[] = 'Заказ выдан с финансовыми документами';
    }

    return [
        'invoices' => $invCount,
        'receipts' => $recCount,
        'reports' => $repCount,
        'invoice_ids' => $invoiceIds,
        'blocking' => $reasons !== [],
        'reasons' => $reasons,
    ];
}

/**
 * @return array{allowed:bool,code:string,message:string,is_lead:bool,requires_password:bool,linked:array<string,mixed>,client_empty_after:bool}
 */
function fixarivan_order_ops_delete_check(PDO $pdo, array $row): array
{
    if (fixarivan_order_ops_is_archived($row)) {
        return [
            'allowed' => false,
            'code' => 'already_archived',
            'message' => 'Заявка уже архивирована',
            'is_lead' => false,
            'requires_password' => false,
            'linked' => [],
            'client_empty_after' => false,
        ];
    }
    $linked = fixarivan_order_ops_linked_summary($pdo, $row);
    $status = fixarivan_normalize_public_status($row['order_status'] ?? $row['public_status'] ?? null);
    $isLead = in_array($status, ['pending_review', 'lead_collecting'], true)
        || fixarivan_is_pending_review_order($row);

    if ($linked['blocking']) {
        return [
            'allowed' => false,
            'code' => 'linked_documents',
            'message' => implode('. ', $linked['reasons']),
            'is_lead' => $isLead,
            'requires_password' => false,
            'linked' => $linked,
            'client_empty_after' => false,
        ];
    }

    $requiresPassword = !$isLead;
    $clientId = (int) ($row['client_id'] ?? 0);
    $clientEmptyAfter = false;
    if ($clientId > 0) {
        $cntStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM orders WHERE client_id = :cid AND document_id != :doc AND (deleted_at IS NULL OR deleted_at = \'\')'
        );
        $cntStmt->execute([':cid' => $clientId, ':doc' => (string) ($row['document_id'] ?? '')]);
        $otherOrders = (int) $cntStmt->fetchColumn();
        $clientEmptyAfter = $otherOrders === 0;
    }

    return [
        'allowed' => true,
        'code' => 'ok',
        'message' => $isLead ? 'Можно удалить предварительную заявку' : 'Можно архивировать заказ',
        'is_lead' => $isLead,
        'requires_password' => $requiresPassword,
        'linked' => $linked,
        'client_empty_after' => $clientEmptyAfter,
    ];
}

function fixarivan_order_ops_cleanup_json_backup(string $documentId): void
{
    $p = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'orders' . DIRECTORY_SEPARATOR . $documentId . '.json';
    if (is_file($p)) {
        @unlink($p);
    }
}

/**
 * @return array{success:bool,code:string,message:string,data?:array<string,mixed>}
 */
function fixarivan_order_ops_archive(PDO $pdo, string $documentId, ?string $deletePassword = null, ?string $requestId = null): array
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        return ['success' => false, 'code' => 'invalid_id', 'message' => 'Не указан document_id'];
    }

    if ($requestId !== null && trim($requestId) !== '') {
        $prev = fixarivan_audit_find_by_request_id($pdo, $requestId);
        if (is_array($prev)) {
            $decoded = json_decode((string) ($prev['result_json'] ?? ''), true);

            return is_array($decoded) ? $decoded : ['success' => true, 'code' => 'idempotent', 'message' => 'Уже выполнено'];
        }
    }

    $row = fixarivan_order_ops_fetch($pdo, $documentId);
    if ($row === null) {
        return ['success' => false, 'code' => 'not_found', 'message' => 'Заявка не найдена'];
    }

    $check = fixarivan_order_ops_delete_check($pdo, $row);
    if (!$check['allowed']) {
        $result = ['success' => false, 'code' => $check['code'], 'message' => $check['message'], 'data' => $check];
        fixarivan_audit_log($pdo, 'order_delete_denied', 'order', $documentId, ['check' => $check], $result, $requestId);

        return $result;
    }

    if ($check['requires_password']) {
        if (!fixarivan_verify_delete_password(trim((string) ($deletePassword ?? '')))) {
            return ['success' => false, 'code' => 'bad_password', 'message' => 'Неверный пароль удаления'];
        }
    }

    try {
        $pdo->beginTransaction();
        $now = date('c');
        $variants = fixarivan_order_ops_id_variants($pdo, $row);
        fixarivan_cleanup_order_supply_on_order_delete($pdo, $variants);
        if ($variants !== []) {
            $placeholders = implode(',', array_fill(0, count($variants), '?'));
            $pdo->prepare("DELETE FROM inventory_movements WHERE ref_kind = 'order' AND ref_id IN ($placeholders)")->execute($variants);
        }
        $upd = $pdo->prepare(
            "UPDATE orders SET
                deleted_at = :now,
                deleted_by = :by,
                archive_reason = :reason,
                order_status = 'archived',
                public_status = 'cancelled',
                status = 'cancelled',
                date_updated = :now
             WHERE document_id = :d AND (deleted_at IS NULL OR deleted_at = '')"
        );
        $upd->execute([
            ':now' => $now,
            ':by' => fixarivan_audit_actor(),
            ':reason' => $check['is_lead'] ? 'lead_removed' : 'manual_archive',
            ':d' => $documentId,
        ]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();

            return ['success' => false, 'code' => 'already_archived', 'message' => 'Заявка уже удалена'];
        }
        fixarivan_order_ops_cleanup_json_backup($documentId);
        $pdo->commit();

        $clientId = (int) ($row['client_id'] ?? 0);
        $clientEmpty = false;
        if ($clientId > 0) {
            $cntStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM orders WHERE client_id = :cid AND (deleted_at IS NULL OR deleted_at = \'\')'
            );
            $cntStmt->execute([':cid' => $clientId]);
            $clientEmpty = ((int) $cntStmt->fetchColumn()) === 0;
        }

        $result = [
            'success' => true,
            'code' => 'archived',
            'message' => $check['is_lead'] ? 'Предварительная заявка удалена' : 'Заказ архивирован',
            'data' => [
                'document_id' => $documentId,
                'order_id' => (string) ($row['order_id'] ?? ''),
                'client_id' => $clientId,
                'client_empty' => $clientEmpty,
                'is_lead' => $check['is_lead'],
            ],
        ];
        fixarivan_audit_log($pdo, 'order_archive', 'order', $documentId, ['is_lead' => $check['is_lead']], $result, $requestId);

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fixarivan_order_ops_archive: ' . $e->getMessage());

        return ['success' => false, 'code' => 'error', 'message' => 'Ошибка удаления: ' . $e->getMessage()];
    }
}

/**
 * @param list<string> $documentIds
 * @return array{success:bool,deleted:int,skipped:int,results:list<array<string,mixed>>}
 */
function fixarivan_order_ops_bulk_archive(PDO $pdo, array $documentIds, ?string $deletePassword = null, ?string $requestId = null): array
{
    $documentIds = array_values(array_unique(array_filter(array_map('trim', $documentIds))));
    $results = [];
    $deleted = 0;
    $skipped = 0;
    foreach ($documentIds as $i => $docId) {
        $subRequest = $requestId !== null && $requestId !== '' ? $requestId . ':' . $i . ':' . $docId : null;
        $one = fixarivan_order_ops_archive($pdo, $docId, $deletePassword, $subRequest);
        $results[] = array_merge(['document_id' => $docId], $one);
        if (!empty($one['success'])) {
            $deleted++;
        } else {
            $skipped++;
        }
    }
    $summary = [
        'success' => $deleted > 0,
        'deleted' => $deleted,
        'skipped' => $skipped,
        'results' => $results,
        'message' => "Удалено: {$deleted}, пропущено: {$skipped}",
    ];
    fixarivan_audit_log($pdo, 'order_bulk_archive', 'order', implode(',', $documentIds), ['count' => count($documentIds)], $summary, $requestId);

    return $summary;
}

/**
 * @return array{success:bool,code:string,message:string,data?:array<string,mixed>,need_confirm?:bool}
 */
function fixarivan_order_ops_complete_paid(PDO $pdo, string $documentId, bool $confirmUnpaid = false, ?string $requestId = null): array
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        return ['success' => false, 'code' => 'invalid_id', 'message' => 'Не указан document_id'];
    }

    if ($requestId !== null && trim($requestId) !== '') {
        $prev = fixarivan_audit_find_by_request_id($pdo, $requestId);
        if (is_array($prev)) {
            $decoded = json_decode((string) ($prev['result_json'] ?? ''), true);

            return is_array($decoded) ? $decoded : ['success' => true, 'code' => 'idempotent', 'message' => 'Уже выполнено'];
        }
    }

    $row = fixarivan_order_ops_fetch($pdo, $documentId);
    if ($row === null || fixarivan_order_ops_is_archived($row)) {
        return ['success' => false, 'code' => 'not_found', 'message' => 'Заказ не найден'];
    }

    $variants = fixarivan_order_ops_id_variants($pdo, $row);
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    if ($placeholders === '') {
        return ['success' => false, 'code' => 'no_order', 'message' => 'Нет идентификатора заказа'];
    }

    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE order_id IN ($placeholders) AND status != 'cancelled' ORDER BY id DESC");
    $invStmt->execute($variants);
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($invoices === []) {
        return ['success' => false, 'code' => 'no_invoice', 'message' => 'Нет связанного счёта для завершения'];
    }

    $allPaid = true;
    foreach ($invoices as $inv) {
        $st = strtolower(trim((string) ($inv['status'] ?? '')));
        if ($st !== 'paid') {
            $allPaid = false;
            break;
        }
    }
    if (!$allPaid && !$confirmUnpaid) {
        return [
            'success' => false,
            'code' => 'confirm_unpaid',
            'message' => 'Счёт ещё не отмечен как оплаченный. Подтвердить оплату и завершить заказ?',
            'need_confirm' => true,
        ];
    }

    try {
        $pdo->beginTransaction();
        $now = date('c');
        $today = date('Y-m-d');
        foreach ($invoices as $inv) {
            $st = strtolower(trim((string) ($inv['status'] ?? '')));
            if ($st === 'paid') {
                continue;
            }
            $record = $inv;
            $record['status'] = 'paid';
            $record = fixarivan_invoice_finalize_payment_fields($record, new DateTimeImmutable('now'));
            $updInv = $pdo->prepare(
                'UPDATE invoices SET status = :st, payment_date = :pd, date_updated = :u WHERE document_id = :d'
            );
            $updInv->execute([
                ':st' => 'paid',
                ':pd' => (string) ($record['payment_date'] ?? $today),
                ':u' => $now,
                ':d' => (string) ($inv['document_id'] ?? ''),
            ]);
        }

        $updOrder = $pdo->prepare(
            "UPDATE orders SET
                order_status = 'delivered',
                public_status = 'delivered',
                status = 'completed',
                public_completed_at = COALESCE(NULLIF(TRIM(public_completed_at), ''), :today),
                date_updated = :u
             WHERE document_id = :d"
        );
        $updOrder->execute([':today' => $today, ':u' => $now, ':d' => $documentId]);

        $pdo->commit();

        $result = [
            'success' => true,
            'code' => 'completed',
            'message' => 'Заказ завершён, счёт отмечен оплаченным',
            'data' => [
                'document_id' => $documentId,
                'order_id' => (string) ($row['order_id'] ?? ''),
                'public_status' => 'delivered',
                'invoices_updated' => count($invoices),
            ],
        ];
        fixarivan_audit_log($pdo, 'order_complete_paid', 'order', $documentId, ['confirm_unpaid' => $confirmUnpaid], $result, $requestId);

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fixarivan_order_ops_complete_paid: ' . $e->getMessage());

        return ['success' => false, 'code' => 'error', 'message' => 'Ошибка завершения: ' . $e->getMessage()];
    }
}

/**
 * @return array{success:bool,code:string,message:string,data?:array<string,mixed>}
 */
function fixarivan_order_ops_reopen(PDO $pdo, string $documentId, ?string $requestId = null): array
{
    $documentId = trim($documentId);
    $row = fixarivan_order_ops_fetch($pdo, $documentId);
    if ($row === null || fixarivan_order_ops_is_archived($row)) {
        return ['success' => false, 'code' => 'not_found', 'message' => 'Заказ не найден'];
    }
    $status = fixarivan_normalize_public_status($row['order_status'] ?? $row['public_status'] ?? null);
    if (!in_array($status, ['delivered', 'done', 'cancelled'], true)) {
        return ['success' => false, 'code' => 'not_closed', 'message' => 'Заказ не в завершённом статусе'];
    }

    if ($requestId !== null && trim($requestId) !== '') {
        $prev = fixarivan_audit_find_by_request_id($pdo, $requestId);
        if (is_array($prev)) {
            $decoded = json_decode((string) ($prev['result_json'] ?? ''), true);

            return is_array($decoded) ? $decoded : ['success' => true, 'code' => 'idempotent', 'message' => 'Уже выполнено'];
        }
    }

    try {
        $now = date('c');
        $upd = $pdo->prepare(
            "UPDATE orders SET
                order_status = 'in_progress',
                public_status = 'in_progress',
                status = 'pending',
                date_updated = :u
             WHERE document_id = :d"
        );
        $upd->execute([':u' => $now, ':d' => $documentId]);
        $result = [
            'success' => true,
            'code' => 'reopened',
            'message' => 'Заказ возвращён в работу',
            'data' => ['document_id' => $documentId, 'public_status' => 'in_progress'],
        ];
        fixarivan_audit_log($pdo, 'order_reopen', 'order', $documentId, [], $result, $requestId);

        return $result;
    } catch (Throwable $e) {
        error_log('fixarivan_order_ops_reopen: ' . $e->getMessage());

        return ['success' => false, 'code' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
    }
}

/** @return array{success:bool,data:array<string,mixed>} */
function fixarivan_order_ops_preview(PDO $pdo, string $documentId): array
{
    $row = fixarivan_order_ops_fetch($pdo, $documentId);
    if ($row === null) {
        return ['success' => false, 'data' => ['message' => 'Не найдено']];
    }
    $check = fixarivan_order_ops_delete_check($pdo, $row);
    $invoices = [];
    $variants = fixarivan_order_ops_id_variants($pdo, $row);
    if ($variants !== []) {
        $ph = implode(',', array_fill(0, count($variants), '?'));
        $st = $pdo->prepare("SELECT document_id, status, total_amount FROM invoices WHERE order_id IN ($ph)");
        $st->execute($variants);
        $invoices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return [
        'success' => true,
        'data' => [
            'document_id' => $documentId,
            'order_id' => (string) ($row['order_id'] ?? ''),
            'client_name' => (string) ($row['client_name'] ?? ''),
            'client_phone' => (string) ($row['client_phone'] ?? ''),
            'order_status' => fixarivan_normalize_public_status($row['order_status'] ?? $row['public_status'] ?? null),
            'delete_check' => $check,
            'invoices' => $invoices,
            'can_complete_paid' => $invoices !== [] && !fixarivan_order_ops_is_archived($row),
            'is_closed' => in_array(
                fixarivan_normalize_public_status($row['order_status'] ?? $row['public_status'] ?? null),
                ['delivered', 'done', 'cancelled'],
                true
            ),
        ],
    ];
}
