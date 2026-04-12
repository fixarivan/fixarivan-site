console.warn('js/universal.js устарел и будет удалён. Используйте обновлённые формы и API.');

/**
 * Универсальный JavaScript для всех форм FixariVan
 * Обеспечивает правильную работу с API и кодировкой UTF-8
 * Поддерживает многоязычность и улучшенную обработку ошибок
 */

// Универсальная функция для отправки данных
async function submitForm(formData, apiEndpoint) {
    try {
        const response = await fetch(apiEndpoint, {
            method: "POST",
            headers: {
                "Content-Type": "application/json; charset=utf-8"
            },
            body: JSON.stringify(formData, null, 0)
        });
        
        const text = await response.text();
        let data;
        
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error("JSON Parse Error:", e, text);
            return {
                success: false,
                message: "Ошибка парсинга ответа сервера"
            };
        }
        
        return data;
    } catch (error) {
        console.error("Fetch Error:", error);
        return {
            success: false,
            message: "Ошибка соединения с сервером"
        };
    }
}

// Функция для показа уведомлений
function showNotification(message, type = "success") {
    // Удаляем предыдущие уведомления
    const existingNotifications = document.querySelectorAll('.fixarivan-notification');
    existingNotifications.forEach(notification => notification.remove());
    
    const notification = document.createElement("div");
    notification.className = "fixarivan-notification";
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 5px;
        color: white;
        font-weight: bold;
        z-index: 10000;
        max-width: 300px;
        word-wrap: break-word;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        animation: slideIn 0.3s ease-out;
    `;
    
    if (type === "success") {
        notification.style.backgroundColor = "#4CAF50";
    } else if (type === "error") {
        notification.style.backgroundColor = "#f44336";
    } else if (type === "warning") {
        notification.style.backgroundColor = "#ff9800";
    } else {
        notification.style.backgroundColor = "#2196F3";
    }
    
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Добавляем CSS анимацию
    if (!document.querySelector('#fixarivan-styles')) {
        const style = document.createElement('style');
        style.id = 'fixarivan-styles';
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    }
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-in';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Функция для валидации форм
function validateForm(form) {
    const requiredFields = form.querySelectorAll("[required]");
    let isValid = true;
    let firstInvalidField = null;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = "#f44336";
            field.style.backgroundColor = "#ffebee";
            if (!firstInvalidField) firstInvalidField = field;
            isValid = false;
        } else {
            field.style.borderColor = "";
            field.style.backgroundColor = "";
        }
    });
    
    if (!isValid && firstInvalidField) {
        firstInvalidField.focus();
        showNotification("Пожалуйста, заполните все обязательные поля", "error");
    }
    
    return isValid;
}

// Функция для очистки стилей валидации
function clearValidationStyles(form) {
    const fields = form.querySelectorAll("input, textarea, select");
    fields.forEach(field => {
        field.style.borderColor = "";
        field.style.backgroundColor = "";
    });
}

// Функция для определения типа документа по форме
function getDocumentType(form) {
    if (form.id === "receipt-form" || form.classList.contains("receipt-form")) {
        return "receipt";
    } else if (form.id === "report-form" || form.classList.contains("report-form")) {
        return "report";
    } else if (form.id === "order-form" || form.classList.contains("order-form")) {
        return "order";
    } else {
        // Пытаемся определить по URL или другим признакам
        if (window.location.pathname.includes("receipt")) return "receipt";
        if (window.location.pathname.includes("report")) return "report";
        return "order";
    }
}

// Функция для подготовки данных формы
function prepareFormData(form) {
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    // Добавляем тип документа
    data.document_type = getDocumentType(form);
    
    // Очищаем пустые значения
    Object.keys(data).forEach(key => {
        if (data[key] === "" || data[key] === null || data[key] === undefined) {
            delete data[key];
        }
    });
    
    return data;
}

// Функция для обновления кнопки отправки
function updateSubmitButton(button, isLoading, originalText) {
    if (isLoading) {
        button.textContent = "Отправка...";
        button.disabled = true;
        button.style.opacity = "0.7";
    } else {
        button.textContent = originalText;
        button.disabled = false;
        button.style.opacity = "1";
    }
}

// Автоматическое обновление всех форм на странице
document.addEventListener("DOMContentLoaded", function() {
    console.log("FixariVan Universal JS загружен");
    
    const forms = document.querySelectorAll("form");
    console.log(`Найдено форм: ${forms.length}`);
    
    forms.forEach((form, index) => {
        console.log(`Обрабатываем форму ${index + 1}:`, form.id || form.className);
        
        form.addEventListener("submit", async function(e) {
            e.preventDefault();
            
            console.log("Отправка формы:", form.id || form.className);
            
            // Очищаем предыдущие стили валидации
            clearValidationStyles(form);
            
            if (!validateForm(form)) {
                return;
            }
            
            const formData = prepareFormData(form);
            console.log("Данные формы:", formData);
            
            // Определяем API endpoint
            let apiEndpoint = "./api/save_document_simple.php";
            if (window.location.pathname.includes("/pages/")) {
                apiEndpoint = "../api/save_document_simple.php";
            }
            
            const submitButton = form.querySelector("button[type=submit], input[type=submit]");
            const originalText = submitButton ? submitButton.textContent || submitButton.value : "Отправить";
            
            if (submitButton) {
                updateSubmitButton(submitButton, true, originalText);
            }
            
            try {
                const result = await submitForm(formData, apiEndpoint);
                console.log("Результат API:", result);
                
                if (result.success) {
                    showNotification("Данные успешно сохранены! ID: " + (result.id || result.document_id || ""), "success");
                    form.reset();
                    clearValidationStyles(form);
                } else {
                    showNotification("Ошибка: " + (result.message || "Неизвестная ошибка"), "error");
                }
            } catch (error) {
                console.error("Ошибка отправки:", error);
                showNotification("Произошла ошибка при отправке данных", "error");
            } finally {
                if (submitButton) {
                    updateSubmitButton(submitButton, false, originalText);
                }
            }
        });
    });
});

// Функция для загрузки данных склада
async function loadInventory() {
    try {
        let apiEndpoint = "./api/inventory_simple.php?action=get_inventory";
        if (window.location.pathname.includes("/pages/")) {
            apiEndpoint = "../api/inventory_simple.php?action=get_inventory";
        }
        
        const response = await fetch(apiEndpoint);
        const data = await response.json();
        
        if (data.success) {
            return data.items;
        } else {
            console.error("Ошибка загрузки склада:", data.message);
            return [];
        }
    } catch (error) {
        console.error("Ошибка загрузки склада:", error);
        return [];
    }
}

// Функция для синхронизации склада
async function syncInventory() {
    try {
        let apiEndpoint = "./api/inventory_simple.php?action=sync_inventory";
        if (window.location.pathname.includes("/pages/")) {
            apiEndpoint = "../api/inventory_simple.php?action=sync_inventory";
        }
        
        const response = await fetch(apiEndpoint);
        const data = await response.json();
        
        if (data.success) {
            showNotification("Склад синхронизирован успешно! Позиций: " + (data.total || 0), "success");
        } else {
            showNotification("Ошибка синхронизации: " + data.message, "error");
        }
    } catch (error) {
        showNotification("Ошибка синхронизации склада", "error");
    }
}

// Функция для генерации PDF
async function generatePDF(documentId, documentType) {
    try {
        let apiEndpoint = `./api/generate_pdf_universal.php?document_id=${documentId}&type=${documentType}`;
        if (window.location.pathname.includes("/pages/")) {
            apiEndpoint = `../api/generate_pdf_universal.php?document_id=${documentId}&type=${documentType}`;
        }
        
        const response = await fetch(apiEndpoint);
        
        if (response.ok) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${documentType}_${documentId}.pdf`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            showNotification("PDF успешно сгенерирован и загружен", "success");
        } else {
            showNotification("Ошибка генерации PDF", "error");
        }
    } catch (error) {
        console.error("Ошибка генерации PDF:", error);
        showNotification("Ошибка генерации PDF", "error");
    }
}

// Функция для проверки сессии пользователя
async function checkUserSession() {
    try {
        let apiEndpoint = "./api/check_session.php";
        if (window.location.pathname.includes("/pages/")) {
            apiEndpoint = "../api/check_session.php";
        }
        
        const response = await fetch(apiEndpoint);
        const data = await response.json();
        
        return data.logged_in || false;
    } catch (error) {
        console.error("Ошибка проверки сессии:", error);
        return false;
    }
}

// Экспорт функций для использования в других скриптах
window.FixariVan = {
    submitForm,
    showNotification,
    validateForm,
    loadInventory,
    syncInventory,
    generatePDF,
    checkUserSession
};

console.log("FixariVan Universal JavaScript загружен успешно");
