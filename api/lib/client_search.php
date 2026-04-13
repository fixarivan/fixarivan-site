<?php
declare(strict_types=1);

/**
 * Единый поиск клиентов в SQLite для подсказок (suggest) и списка клиентов (full).
 *
 * suggest — только таблица clients, нормализация телефона, безопасный LIKE (без %/_ во вводе).
 * full — DISTINCT + LEFT JOIN orders (модель, проблема, номера заказа/акта), плюс те же правила телефона.
 */

require_once __DIR__ . '/order_center.php';

/**
 * @param array{mode?:'suggest'|'full', limit?:int} $options
 * @return list<array<string,mixed>>
 */
function fixarivan_client_search_clients(PDO $pdo, string $q, array $options = []): array
{
    $mode = $options['mode'] ?? 'suggest';
    if (!in_array($mode, ['suggest', 'full'], true)) {
        $mode = 'suggest';
    }

    $limit = isset($options['limit']) ? (int)$options['limit'] : ($mode === 'full' ? 100 : 12);
    if ($limit < 1) {
        $limit = 1;
    }
    $maxLimit = $mode === 'full' ? 500 : 20;
    if ($limit > $maxLimit) {
        $limit = $maxLimit;
    }

    if ($mode === 'full') {
        return fixarivan_client_search_clients_full($pdo, $q, $limit);
    }

    return fixarivan_client_search_clients_suggest($pdo, $q, $limit);
}

/**
 * Подсказки в формах заказа: от 2 символов; только clients.
 *
 * @return list<array<string,mixed>>
 */
function fixarivan_client_search_clients_suggest(PDO $pdo, string $q, int $limit): array
{
    $q = trim($q);
    $len = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
    if ($len < 2) {
        return [];
    }

    $qFrag = str_replace(['%', '_'], '', $q);
    $phoneSearch = fixarivan_normalize_phone($q);
    if ($phoneSearch === '') {
        $phoneSearch = preg_replace('/\D+/', '', $q) ?? '';
    }

    $params = [];
    $parts = [];

    if ($qFrag !== '') {
        $params[':qlike'] = '%' . $qFrag . '%';
        $parts[] = 'c.full_name LIKE :qlike';
        $parts[] = 'IFNULL(c.client_id, \'\') LIKE :qlike';
        $parts[] = 'IFNULL(c.email, \'\') LIKE :qlike';
    }

    if (strlen($phoneSearch) >= 2) {
        $params[':phlike'] = '%' . $phoneSearch . '%';
        $parts[] = '(c.phone IS NOT NULL AND c.phone LIKE :phlike)';
    }

    if ($parts === []) {
        return [];
    }

    $sql = 'SELECT c.client_id, c.full_name, c.phone, c.email,
                   COALESCE(NULLIF(TRIM(c.updated_at), \'\'), \'\') AS updated_at
            FROM clients c
            WHERE (' . implode(' OR ', $parts) . ')
            ORDER BY c.updated_at DESC
            LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Список / поиск на странице клиентов: при пустом q — последние по updated_at; иначе JOIN с заказами.
 *
 * @return list<array<string,mixed>>
 */
function fixarivan_client_search_clients_full(PDO $pdo, string $q, int $limit): array
{
    $q = trim($q);
    if ($q === '') {
        $stmt = $pdo->query(
            'SELECT c.client_id, c.full_name, c.phone, c.email, c.updated_at,
                    (SELECT COUNT(*) FROM orders o WHERE o.client_id = c.id) AS orders_count
             FROM clients c
             ORDER BY c.updated_at DESC
             LIMIT ' . (int)$limit
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $qFrag = str_replace(['%', '_'], '', $q);
    $phoneSearch = fixarivan_normalize_phone($q);
    if ($phoneSearch === '') {
        $phoneSearch = preg_replace('/\D+/', '', $q) ?? '';
    }

    $params = [];
    $parts = [];

    if ($qFrag !== '') {
        $params[':qlike'] = '%' . $qFrag . '%';
        $parts[] = 'c.full_name LIKE :qlike';
        $parts[] = 'IFNULL(c.client_id, \'\') LIKE :qlike';
        $parts[] = 'IFNULL(c.email, \'\') LIKE :qlike';
        $parts[] = 'IFNULL(c.phone, \'\') LIKE :qlike';
        $parts[] = 'IFNULL(o.device_model, \'\') LIKE :qlike';
        $parts[] = 'IFNULL(o.problem_description, \'\') LIKE :qlike';
        $parts[] = 'IFNULL(o.order_id, \'\') LIKE :qlike';
        $parts[] = 'IFNULL(o.document_id, \'\') LIKE :qlike';
    }

    if (strlen($phoneSearch) >= 2) {
        $params[':phlike'] = '%' . $phoneSearch . '%';
        $parts[] = '(c.phone IS NOT NULL AND c.phone LIKE :phlike)';
    }

    if ($parts === []) {
        return [];
    }

    $sql = 'SELECT DISTINCT c.client_id, c.full_name, c.phone, c.email,
                   COALESCE(NULLIF(TRIM(c.updated_at), \'\'), \'\') AS updated_at,
                   (SELECT COUNT(*) FROM orders o2 WHERE o2.client_id = c.id) AS orders_count
            FROM clients c
            LEFT JOIN orders o ON o.client_id = c.id
            WHERE (' . implode(' OR ', $parts) . ')
            ORDER BY c.updated_at DESC
            LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
