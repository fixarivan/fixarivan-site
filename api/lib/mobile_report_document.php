<?php
declare(strict_types=1);

/**
 * Единый формат «отчёта» для UI/PDF: строка mobile_reports + merge с raw_json (camelCase из формы).
 */

require_once __DIR__ . '/document_templates.php';

/**
 * @return array<string,mixed>|null
 */
function fixarivan_mobile_report_row_to_document(array $row): ?array {
    if (empty($row['report_id'])) {
        return null;
    }

    $base = [
        'document_id' => (string) $row['report_id'],
        'client_name' => (string) ($row['client_name'] ?? ''),
        'client_phone' => (string) ($row['phone'] ?? ''),
        'client_email' => '',
        'device_model' => (string) ($row['model'] ?? ''),
        'device_serial' => (string) ($row['serial_number'] ?? ''),
        'device_type' => (string) ($row['device_type'] ?? ''),
        'diagnosis' => (string) ($row['diagnosis'] ?? ''),
        'recommendations' => (string) ($row['recommendations'] ?? ''),
        'date_created' => (string) ($row['created_at'] ?? ''),
        'date_updated' => (string) ($row['created_at'] ?? ''),
        'technician_name' => (string) ($row['master_name'] ?? ''),
        'work_date' => (string) ($row['work_date'] ?? ''),
        'unique_code' => (string) ($row['verification_code'] ?? ''),
        'place_of_acceptance' => 'Turku, Finland',
        'status' => 'completed',
        'priority' => 'normal',
        'problem_description' => '',
        'device_password' => '',
        'device_condition' => '',
        'accessories' => '',
        'repair_cost' => '',
        'repair_time' => '',
        'warranty' => '',
        'language' => 'ru',
    ];

    $raw = json_decode((string) ($row['raw_json'] ?? '{}'), true);
    if (!is_array($raw)) {
        $raw = [];
    }

    return dt_merge_data($base, $raw);
}

/**
 * @return array<string,mixed>|null
 */
function fixarivan_load_mobile_report_by_id(PDO $pdo, string $reportId): ?array {
    $reportId = trim($reportId);
    if ($reportId === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM mobile_reports WHERE report_id = :id LIMIT 1');
    $stmt->execute([':id' => $reportId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        return null;
    }
    return fixarivan_mobile_report_row_to_document($row);
}

/**
 * snake_case поля формы редактирования → camelCase в raw_json (как при save_report_fixed).
 *
 * @return array<string,string>
 */
function fixarivan_report_update_field_map(): array {
    return [
        'client_name' => 'clientName',
        'client_phone' => 'clientPhone',
        'client_email' => 'clientEmail',
        'device_model' => 'deviceModel',
        'device_serial' => 'deviceSerial',
        'device_type' => 'deviceType',
        'diagnosis' => 'diagnosis',
        'recommendations' => 'recommendations',
        'repair_cost' => 'repairCost',
        'repair_time' => 'repairTime',
        'warranty' => 'warranty',
        'priority' => 'priority',
        'status' => 'status',
        'place_of_acceptance' => 'placeOfAcceptance',
        'date_of_acceptance' => 'dateOfAcceptance',
        'unique_code' => 'uniqueCode',
        'technician_name' => 'technicianName',
        'work_date' => 'workDate',
        'language' => 'language',
        'problem_description' => 'problemDescription',
        'device_password' => 'devicePassword',
        'device_condition' => 'deviceCondition',
        'accessories' => 'accessories',
    ];
}

/**
 * @param array<string,mixed> $updates
 */
function fixarivan_update_mobile_report(PDO $pdo, string $reportId, array $updates): void {
    $reportId = trim($reportId);
    if ($reportId === '') {
        throw new InvalidArgumentException('Пустой report_id');
    }

    $stmt = $pdo->prepare('SELECT * FROM mobile_reports WHERE report_id = :id LIMIT 1');
    $stmt->execute([':id' => $reportId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        throw new RuntimeException('Отчёт не найден');
    }

    $raw = json_decode((string) ($row['raw_json'] ?? '{}'), true);
    if (!is_array($raw)) {
        $raw = [];
    }

    foreach ($updates as $key => $value) {
        if (!isset(FIXARIVAN_REPORT_UPDATE_TO_RAW[$key])) {
            continue;
        }
        $camel = FIXARIVAN_REPORT_UPDATE_TO_RAW[$key];
        $raw[$camel] = $value;
    }

    $newClientName = array_key_exists('client_name', $filtered) ? (string) $filtered['client_name'] : (string) ($row['client_name'] ?? '');
    $newPhone = array_key_exists('client_phone', $filtered) ? (string) $filtered['client_phone'] : (string) ($row['phone'] ?? '');
    $newModel = array_key_exists('device_model', $filtered) ? (string) $filtered['device_model'] : (string) ($row['model'] ?? '');
    $newSerial = array_key_exists('device_serial', $filtered) ? (string) $filtered['device_serial'] : (string) ($row['serial_number'] ?? '');
    $newDevType = array_key_exists('device_type', $filtered) ? (string) $filtered['device_type'] : (string) ($row['device_type'] ?? '');
    $newDiag = array_key_exists('diagnosis', $filtered) ? (string) $filtered['diagnosis'] : (string) ($row['diagnosis'] ?? '');
    $newRec = array_key_exists('recommendations', $filtered) ? (string) $filtered['recommendations'] : (string) ($row['recommendations'] ?? '');
    $newMaster = array_key_exists('technician_name', $filtered) ? (string) $filtered['technician_name'] : (string) ($row['master_name'] ?? '');
    $newWork = array_key_exists('work_date', $filtered) ? (string) $filtered['work_date'] : (string) ($row['work_date'] ?? '');
    $newVer = array_key_exists('unique_code', $filtered) ? (string) $filtered['unique_code'] : (string) ($row['verification_code'] ?? '');

    $encRaw = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encRaw === false) {
        throw new RuntimeException('Ошибка сериализации raw_json');
    }

    $u = $pdo->prepare(
        'UPDATE mobile_reports SET
            client_name = :client_name,
            phone = :phone,
            device_type = :device_type,
            model = :model,
            serial_number = :serial_number,
            diagnosis = :diagnosis,
            recommendations = :recommendations,
            master_name = :master_name,
            work_date = :work_date,
            verification_code = :verification_code,
            raw_json = :raw_json
         WHERE report_id = :rid'
    );
    $u->execute([
        ':client_name' => $newClientName,
        ':phone' => $newPhone,
        ':device_type' => $newDevType,
        ':model' => $newModel,
        ':serial_number' => $newSerial,
        ':diagnosis' => $newDiag,
        ':recommendations' => $newRec,
        ':master_name' => $newMaster,
        ':work_date' => $newWork,
        ':verification_code' => $newVer,
        ':raw_json' => $encRaw,
        ':rid' => $reportId,
    ]);
}
