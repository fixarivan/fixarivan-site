<?php
/**
 * ПРИМЕР PDF АКТА МАСТЕРА
 * Создаёт пример заполненного акта для предварительного просмотра
 */

// Тестовые данные
$exampleData = [
    'documentId' => 'ORD-2025-001',
    'clientName' => 'Иванов Иван Иванович',
    'clientPhone' => '+7 (999) 123-45-67',
    'clientEmail' => 'ivanov@example.com',
    'deviceModel' => 'iPhone 14 Pro Max',
    'serialNumber' => 'F2LQ3LL/A',
    'problemDescription' => 'Не включается, не заряжается. При подключении к зарядке экран мигает, но устройство не включается.',
    'devicePassword' => '123456',
    'accessories' => 'Зарядное устройство, кабель Lightning, чехол',
    'deviceCondition' => 'Хорошее состояние, небольшие царапины на задней панели',
    'signatureData' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
];

// Генерируем PNG → PDF
require_once 'api/generate_png_to_pdf.php';

// Вызываем функцию генерации
$result = generatePNGToPDF('order', $exampleData);

if ($result['success']) {
    echo "<h1>✅ ПРИМЕР PDF АКТА МАСТЕРА СОЗДАН!</h1>";
    echo "<p><strong>PNG файл:</strong> " . basename($result['png_file']) . "</p>";
    echo "<p><strong>PDF файл:</strong> " . basename($result['pdf_file']) . "</p>";
    echo "<p><strong>Размер PDF:</strong> " . round($result['size'] / 1024, 2) . " KB</p>";
    echo "<br>";
    echo "<a href='" . $result['pdf_file'] . "' target='_blank' style='background: #1C5FDD; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; display: inline-block;'>📄 СКАЧАТЬ ПРИМЕР PDF</a>";
    echo "<br><br>";
    echo "<a href='" . $result['png_file'] . "' target='_blank' style='background: #28a745; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; display: inline-block; margin-left: 10px;'>🖼️ ПРОСМОТРЕТЬ PNG</a>";
} else {
    echo "<h1>❌ ОШИБКА ГЕНЕРАЦИИ</h1>";
    echo "<p>" . $result['message'] . "</p>";
}
?>
