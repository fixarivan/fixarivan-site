<?php
/**
 * Исправление HTML форм для использования правильных API endpoints
 * Решает проблемы с JSON ошибками и подключением к серверу
 */

header("Content-Type: text/html; charset=utf-8");
error_reporting(E_ALL);
ini_set("display_errors", 1);

echo "<h1>🔧 Исправление HTML форм FixariVan</h1>";
echo "<p>Обновляем формы для использования правильных API endpoints...</p>";

$forms_updated = [];
$errors = [];

// 1. Исправление receipt.html
echo "<h2>1. 📄 Исправление receipt.html</h2>";

$receipt_html = '<!-- Обновленный JavaScript для receipt.html -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.querySelector("form");
    const submitBtn = document.querySelector("button[type=submit]");
    
    if (form && submitBtn) {
        form.addEventListener("submit", async function(e) {
            e.preventDefault();
            
            // Показываем индикатор загрузки
            submitBtn.textContent = "Отправка...";
            submitBtn.disabled = true;
            
            // Собираем данные формы
            const formData = new FormData(form);
            const data = {
                document_type: "receipt",
                client_name: formData.get("client_name") || "Не указано",
                client_phone: formData.get("client_phone") || "",
                client_email: formData.get("client_email") || "",
                device_model: formData.get("device_model") || "Не указано",
                total_amount: parseFloat(formData.get("total_amount")) || 0,
                payment_method: formData.get("payment_method") || "Наличные",
                services_rendered: formData.get("services_rendered") || "",
                notes: formData.get("notes") || "",
                client_signature: formData.get("client_signature") || "",
                master_signature: formData.get("master_signature") || "",
                status: "completed"
            };
            
            try {
                const response = await fetch("./api/document_api_fixed.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert("✅ Квитанция успешно сохранена! ID: " + result.document_id);
                    form.reset();
                } else {
                    alert("❌ Ошибка: " + result.message);
                }
                
            } catch (error) {
                console.error("Ошибка:", error);
                alert("❌ Ошибка подключения к серверу: " + error.message);
            } finally {
                submitBtn.textContent = "Отправить квитанцию";
                submitBtn.disabled = false;
            }
        });
    }
});
</script>';

if (file_put_contents("receipt_form_fixed.js", $receipt_html)) {
    echo "<p style='color: green;'>✅ JavaScript для receipt.html создан</p>";
    $forms_updated[] = "receipt.html";
} else {
    $errors[] = "❌ Ошибка создания JavaScript для receipt.html";
}

// 2. Исправление diagnostic_pc.html
echo "<h2>2. 📋 Исправление diagnostic_pc.html</h2>";

$diagnostic_pc_js = '<!-- Обновленный JavaScript для diagnostic_pc.html -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.querySelector("form");
    const submitBtn = document.querySelector("button[type=submit]");
    
    if (form && submitBtn) {
        form.addEventListener("submit", async function(e) {
            e.preventDefault();
            
            submitBtn.textContent = "Отправка...";
            submitBtn.disabled = true;
            
            const formData = new FormData(form);
            const data = {
                document_type: "report",
                client_name: formData.get("client_name") || "Не указано",
                client_phone: formData.get("client_phone") || "",
                client_email: formData.get("client_email") || "",
                device_model: formData.get("device_model") || "Не указано",
                device_serial: formData.get("device_serial") || "",
                problem_description: formData.get("problem_description") || "Диагностика",
                diagnosis: formData.get("diagnosis") || "Диагностика завершена",
                recommendations: formData.get("recommendations") || "Рекомендации мастера",
                warranty: parseInt(formData.get("warranty")) || 0,
                report_type: "pc_diagnostic",
                priority: formData.get("priority") || "normal",
                status: "completed",
                technician: formData.get("technician") || "Мастер"
            };
            
            try {
                const response = await fetch("./api/document_api_fixed.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert("✅ Отчёт успешно сохранён! ID: " + result.document_id);
                    form.reset();
                } else {
                    alert("❌ Ошибка: " + result.message);
                }
                
            } catch (error) {
                console.error("Ошибка:", error);
                alert("❌ Ошибка подключения к серверу: " + error.message);
            } finally {
                submitBtn.textContent = "Отправить отчёт";
                submitBtn.disabled = false;
            }
        });
    }
});
</script>';

if (file_put_contents("diagnostic_pc_form_fixed.js", $diagnostic_pc_js)) {
    echo "<p style='color: green;'>✅ JavaScript для diagnostic_pc.html создан</p>";
    $forms_updated[] = "diagnostic_pc.html";
} else {
    $errors[] = "❌ Ошибка создания JavaScript для diagnostic_pc.html";
}

// 3. Исправление diagnostic_mobile.html
echo "<h2>3. 📱 Исправление diagnostic_mobile.html</h2>";

$diagnostic_mobile_js = '<!-- Обновленный JavaScript для diagnostic_mobile.html -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.querySelector("form");
    const submitBtn = document.querySelector("button[type=submit]");
    
    if (form && submitBtn) {
        form.addEventListener("submit", async function(e) {
            e.preventDefault();
            
            submitBtn.textContent = "Отправка...";
            submitBtn.disabled = true;
            
            const formData = new FormData(form);
            const data = {
                document_type: "report",
                client_name: formData.get("client_name") || "Не указано",
                client_phone: formData.get("client_phone") || "",
                client_email: formData.get("client_email") || "",
                device_model: formData.get("device_model") || "Не указано",
                device_serial: formData.get("device_serial") || "",
                problem_description: formData.get("problem_description") || "Диагностика мобильного устройства",
                diagnosis: formData.get("diagnosis") || "Диагностика завершена",
                recommendations: formData.get("recommendations") || "Рекомендации мастера",
                warranty: parseInt(formData.get("warranty")) || 0,
                report_type: "mobile_diagnostic",
                priority: formData.get("priority") || "normal",
                status: "completed",
                technician: formData.get("technician") || "Мастер"
            };
            
            try {
                const response = await fetch("./api/document_api_fixed.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert("✅ Отчёт успешно сохранён! ID: " + result.document_id);
                    form.reset();
                } else {
                    alert("❌ Ошибка: " + result.message);
                }
                
            } catch (error) {
                console.error("Ошибка:", error);
                alert("❌ Ошибка подключения к серверу: " + error.message);
            } finally {
                submitBtn.textContent = "Отправить отчёт";
                submitBtn.disabled = false;
            }
        });
    }
});
</script>';

if (file_put_contents("diagnostic_mobile_form_fixed.js", $diagnostic_mobile_js)) {
    echo "<p style='color: green;'>✅ JavaScript для diagnostic_mobile.html создан</p>";
    $forms_updated[] = "diagnostic_mobile.html";
} else {
    $errors[] = "❌ Ошибка создания JavaScript для diagnostic_mobile.html";
}

// 4. Исправление master_form.html
echo "<h2>4. 🔧 Исправление master_form.html</h2>";

$master_form_js = '<!-- Обновленный JavaScript для master_form.html -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.querySelector("form");
    const submitBtn = document.querySelector("button[type=submit]");
    
    if (form && submitBtn) {
        form.addEventListener("submit", async function(e) {
            e.preventDefault();
            
            submitBtn.textContent = "Сохранение...";
            submitBtn.disabled = true;
            
            const formData = new FormData(form);
            const data = {
                document_type: "order",
                client_name: formData.get("client_name") || "Не указано",
                client_phone: formData.get("client_phone") || "",
                client_email: formData.get("client_email") || "",
                device_model: formData.get("device_model") || "Не указано",
                device_serial: formData.get("device_serial") || "",
                device_type: formData.get("device_type") || "",
                problem_description: formData.get("problem_description") || "Приём устройства",
                device_password: formData.get("device_password") || "",
                device_condition: formData.get("device_condition") || "",
                accessories: formData.get("accessories") || "",
                client_signature: formData.get("client_signature") || "",
                master_signature: formData.get("master_signature") || "",
                priority: formData.get("priority") || "normal",
                status: "pending"
            };
            
            try {
                const response = await fetch("./api/document_api_fixed.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert("✅ Акт приёма успешно сохранён! ID: " + result.document_id);
                    form.reset();
                } else {
                    alert("❌ Ошибка: " + result.message);
                }
                
            } catch (error) {
                console.error("Ошибка:", error);
                alert("❌ Ошибка подключения к серверу: " + error.message);
            } finally {
                submitBtn.textContent = "Сохранение...";
                submitBtn.disabled = false;
            }
        });
    }
});
</script>';

if (file_put_contents("master_form_fixed.js", $master_form_js)) {
    echo "<p style='color: green;'>✅ JavaScript для master_form.html создан</p>";
    $forms_updated[] = "master_form.html";
} else {
    $errors[] = "❌ Ошибка создания JavaScript для master_form.html";
}

// 5. Создание универсального скрипта для обновления форм
echo "<h2>5. 🔄 Создание универсального скрипта обновления</h2>";

$update_forms_script = '<?php
/**
 * Универсальный скрипт для обновления HTML форм
 * Автоматически обновляет все формы для использования новых API
 */

header("Content-Type: text/html; charset=utf-8");
error_reporting(E_ALL);
ini_set("display_errors", 1);

echo "<h1>🔄 Обновление HTML форм FixariVan</h1>";

$html_files = [
    "receipt.html" => "Квитанции",
    "diagnostic_pc.html" => "Диагностика PC",
    "diagnostic_mobile.html" => "Диагностика мобильных",
    "master_form.html" => "Акт мастера",
    "client_form.html" => "Форма клиента"
];

$updated_files = [];
$errors = [];

foreach ($html_files as $file => $description) {
    if (file_exists("../$file")) {
        echo "<h2>Обновление $description ($file)</h2>";
        
        $content = file_get_contents("../$file");
        
        // Заменяем старые API endpoints на новые
        $content = str_replace(
            "save_receipt_fixed.php",
            "api/document_api_fixed.php",
            $content
        );
        
        $content = str_replace(
            "save_report_fixed.php",
            "api/document_api_fixed.php",
            $content
        );
        
        $content = str_replace(
            "save_mobile_report_fixed.php",
            "api/document_api_fixed.php",
            $content
        );
        
        $content = str_replace(
            "save_order_fixed.php",
            "api/document_api_fixed.php",
            $content
        );
        
        // Добавляем правильные заголовки для JSON
        $content = str_replace(
            "Content-Type: application/x-www-form-urlencoded",
            "Content-Type: application/json",
            $content
        );
        
        // Обновляем fetch запросы
        $content = preg_replace(
            "/fetch\\(['\"]([^'\"]+)['\"],\\s*\\{([^}]+)\\}/",
            "fetch(\"$1\", {$2}",
            $content
        );
        
        if (file_put_contents("../$file", $content)) {
            echo "<p style='color: green;'>✅ $description обновлён</p>";
            $updated_files[] = $file;
        } else {
            echo "<p style='color: red;'>❌ Ошибка обновления $description</p>";
            $errors[] = "Ошибка обновления $file";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Файл $file не найден</p>";
    }
}

echo "<h2>📊 Результат обновления</h2>";
echo "<p><strong>Обновлено файлов:</strong> " . count($updated_files) . "</p>";
echo "<p><strong>Ошибок:</strong> " . count($errors) . "</p>";

if (count($errors) === 0) {
    echo "<p style='color: green; font-size: 18px;'><strong>🎉 Все формы успешно обновлены!</strong></p>";
} else {
    echo "<p style='color: red; font-size: 18px;'><strong>⚠️ Некоторые формы не удалось обновить</strong></p>";
    foreach ($errors as $error) {
        echo "<p style='color: red;'>$error</p>";
    }
}

echo "<hr>";
echo "<h2>🚀 Следующие шаги:</h2>";
echo "<ol>";
echo "<li><strong>Протестируйте формы:</strong> Убедитесь, что все формы отправляют данные корректно</li>";
echo "<li><strong>Проверьте JSON ответы:</strong> Убедитесь, что сервер возвращает JSON, а не HTML</li>";
echo "<li><strong>Проверьте сохранение данных:</strong> Убедитесь, что данные сохраняются в БД</li>";
echo "</ol>";
?>';

if (file_put_contents("update_forms.php", $update_forms_script)) {
    echo "<p style='color: green;'>✅ Универсальный скрипт обновления создан</p>";
    echo "<p>🔗 Скрипт: <a href='update_forms.php' target='_blank'>update_forms.php</a></p>";
}

// Итоговый отчет
echo "<h2>📊 Итоговый отчет</h2>";

if (empty($errors)) {
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3 style='color: #155724; margin-top: 0;'>🎉 ВСЕ ФОРМЫ ИСПРАВЛЕНЫ!</h3>";
    echo "<p><strong>Обновлено форм:</strong> " . count($forms_updated) . "</p>";
    echo "<ul>";
    foreach ($forms_updated as $form) {
        echo "<li>✅ $form</li>";
    }
    echo "</ul>";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3 style='color: #721c24; margin-top: 0;'>⚠️ НЕКОТОРЫЕ ФОРМЫ НЕ УДАЛОСЬ ИСПРАВИТЬ</h3>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li style='color: red;'>$error</li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "<hr>";
echo "<h2>🚀 Следующие шаги:</h2>";
echo "<ol>";
echo "<li><strong>Запустите диагностику:</strong> <a href='diagnose_all_problems.php' target='_blank'>diagnose_all_problems.php</a></li>";
echo "<li><strong>Создайте тестовые данные:</strong> <a href='create_test_data.php' target='_blank'>create_test_data.php</a></li>";
echo "<li><strong>Обновите HTML формы:</strong> <a href='update_forms.php' target='_blank'>update_forms.php</a></li>";
echo "<li><strong>Протестируйте все формы:</strong> Убедитесь, что все формы работают корректно</li>";
echo "</ol>";

echo "<p><em>Исправление форм завершено: " . date("Y-m-d H:i:s") . "</em></p>";
?>
