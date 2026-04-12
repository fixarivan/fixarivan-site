<?php
/**
 * ИНДЕКСНЫЙ ФАЙЛ API
 * Показывает все доступные API endpoints
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$apiEndpoints = [
    'documents' => [
        'list_sqlite' => 'documents_list_sqlite.php',
        'clients' => 'clients.php',
        'client_lookup' => 'client_lookup.php',
        'save_order' => 'save_order_fixed.php',
        'save_order_supply' => 'save_order_supply.php',
        'save_receipt' => 'save_receipt_ultimate.php',
        'save_invoice' => 'save_invoice.php',
        'save_report_pc' => 'save_report_fixed.php',
        'save_report_mobile' => 'save_mobile_report_fixed.php',
        'save_report_universal' => 'save_report_ultimate.php',
        'get_all' => 'get_all_documents.php',
        'get_recent' => 'get_recent_documents.php',
        'get_single' => 'get_document.php',
        'get_invoice' => 'get_invoice.php',
        'delete_safe' => 'delete_document_safe_fixed.php',
        'update' => 'update_document.php'
    ],
    'pdf' => [
        'generate_client_act' => 'generate_beautiful_client_act.php',
        'generate_document' => 'generate_dompdf_fixed.php'
    ],
    'statistics' => [
        'dashboard_fast' => 'get_fast_stats.php',
        'inventory_overview' => 'get_inventory_stats.php',
        'reports_overview' => 'get_statistics_optimized.php'
    ],
    'inventory' => [
        'clear' => 'clear_inventory_ultimate.php',
        'sync_safe' => 'safe_sync_inventory.php',
        'sync_direct' => 'sync_inventory.php',
        'list_sqlite' => 'inventory_list.php',
        'movement_sqlite' => 'inventory_movement.php',
        'history_sqlite' => 'inventory_history.php',
        'purchase_queue' => 'order_purchase_queue.php',
        'purchase_queue_action' => 'order_purchase_queue_action.php'
    ],
    'calendar' => [
        'events_sqlite' => 'calendar_events.php'
    ],
    'company' => [
        'profile' => 'company_profile.php'
    ],
    'notes' => [
        'sqlite' => 'notes.php'
    ],
    'system' => [
        'db_status' => 'db_status.php',
        'clear_database' => 'clear_database.php',
        'migrate_order_center' => 'migrate_order_center.php'
    ]
];

echo json_encode([
    'success' => true,
    'api_version' => '2.6',
    'total_endpoints' => array_sum(array_map('count', $apiEndpoints)),
    'endpoints' => $apiEndpoints,
    'last_updated' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
