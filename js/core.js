/**
 * ЕДИНЫЙ JS ФАЙЛ ДЛЯ FIXARIVAN
 * Исправляет проблемы с дублированием кода из отчёта
 */

// ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ
window.FixariVan = {
    config: {
        apiUrl: '/api/',
        timeout: 30000,
        retryAttempts: 3
    },
    utils: {},
    ui: {},
    api: {}
};

// УТИЛИТЫ
FixariVan.utils = {
    // Проверка авторизации: только PHP-сессия (admin_session_status), не localStorage.
    checkAuth: async function() {
        if (typeof AuthManager !== 'undefined' && AuthManager.checkAuth) {
            return AuthManager.checkAuth();
        }
        try {
            const r = await fetch('./api/admin_session_status.php', { credentials: 'same-origin', cache: 'no-store' });
            const j = await r.json();
            if (!j || !j.ok) {
                window.location.href = 'admin/login.php?next=' + encodeURIComponent('../' + (window.location.pathname.split('/').pop() || 'index.php'));
                return false;
            }
            return true;
        } catch (e) {
            window.location.href = 'admin/login.php?next=' + encodeURIComponent('../index.php');
            return false;
        }
    },

    // Валидация email
    validateEmail: function(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    },

    // Валидация телефона
    validatePhone: function(phone) {
        const re = /^[\+]?[0-9\s\-\(\)]{7,20}$/;
        return re.test(phone);
    },

    // Валидация суммы
    validateAmount: function(amount) {
        return !isNaN(amount) && parseFloat(amount) >= 0;
    },

    // Очистка данных
    sanitizeInput: function(input) {
        if (typeof input === 'string') {
            return input.trim().replace(/[<>]/g, '');
        }
        return input;
    },

    // Форматирование даты
    formatDate: function(date) {
        const d = new Date(date);
        return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'});
    },

    // Генерация ID документа
    generateDocumentId: function(prefix = 'DOC') {
        const timestamp = Date.now().toString(36);
        const random = Math.random().toString(36).substr(2, 5);
        return `${prefix}-${timestamp}-${random}`.toUpperCase();
    }
};

// UI КОМПОНЕНТЫ
FixariVan.ui = {
    // Показать уведомление
    showNotification: function(message, type = 'info', duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-icon">${this.getIcon(type)}</span>
                <span class="notification-message">${message}</span>
                <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        
        // Добавляем стили если их нет
        if (!document.getElementById('notification-styles')) {
            const styles = document.createElement('style');
            styles.id = 'notification-styles';
            styles.textContent = `
                .notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 10000;
                    max-width: 400px;
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    animation: slideIn 0.3s ease-out;
                }
                .notification-success { border-left: 4px solid #28a745; }
                .notification-error { border-left: 4px solid #dc3545; }
                .notification-warning { border-left: 4px solid #ffc107; }
                .notification-info { border-left: 4px solid #17a2b8; }
                .notification-content {
                    display: flex;
                    align-items: center;
                    padding: 15px;
                }
                .notification-icon { margin-right: 10px; font-size: 18px; }
                .notification-message { flex: 1; }
                .notification-close {
                    background: none;
                    border: none;
                    font-size: 20px;
                    cursor: pointer;
                    color: #666;
                }
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            `;
            document.head.appendChild(styles);
        }
        
        document.body.appendChild(notification);
        
        // Автоматическое удаление
        if (duration > 0) {
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, duration);
        }
    },

    // Получить иконку для уведомления
    getIcon: function(type) {
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        return icons[type] || icons.info;
    },

    // Показать загрузку
    showLoading: function(element, text = 'Загрузка...') {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) {
            element.disabled = true;
            element.dataset.originalText = element.textContent;
            element.innerHTML = `<span class="spinner"></span> ${text}`;
        }
    },

    // Скрыть загрузку
    hideLoading: function(element) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element && element.dataset.originalText) {
            element.disabled = false;
            element.textContent = element.dataset.originalText;
            delete element.dataset.originalText;
        }
    },

    // Валидация формы
    validateForm: function(form) {
        const errors = [];
        const requiredFields = form.querySelectorAll('[required]');
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                errors.push(`Поле "${field.getAttribute('data-label') || field.name}" обязательно для заполнения`);
                field.classList.add('error');
            } else {
                field.classList.remove('error');
            }
        });
        
        // Валидация email
        const emailFields = form.querySelectorAll('input[type="email"]');
        emailFields.forEach(field => {
            if (field.value && !FixariVan.utils.validateEmail(field.value)) {
                errors.push(`Некорректный email в поле "${field.getAttribute('data-label') || field.name}"`);
                field.classList.add('error');
            }
        });
        
        // Валидация телефона
        const phoneFields = form.querySelectorAll('input[name*="phone"]');
        phoneFields.forEach(field => {
            if (field.value && !FixariVan.utils.validatePhone(field.value)) {
                errors.push(`Некорректный номер телефона в поле "${field.getAttribute('data-label') || field.name}"`);
                field.classList.add('error');
            }
        });
        
        return errors;
    }
};

// API ФУНКЦИИ
FixariVan.api = {
    request: async function() {
        console.warn('FixariVan.api.request() устарел и не должен использоваться. Обновите вызов на актуальные API помощники.');
        return { success: false, message: 'Deprecated client helper' };
    },

    saveDocument: async function() {
        console.warn('FixariVan.api.saveDocument() устарел. Используйте актуальные формы и API (save_order_fixed.php и т.д.).');
        return { success: false, message: 'Deprecated client helper' };
    },

    generatePDF: async function() {
        console.warn('FixariVan.api.generatePDF() устарел. Используйте generate_dompdf_fixed.php напрямую.');
        return { success: false, message: 'Deprecated client helper' };
    },

    getDocuments: async function() {
        console.warn('FixariVan.api.getDocuments() устарел.');
        return { success: false, message: 'Deprecated client helper' };
    },

    searchDocuments: async function() {
        console.warn('FixariVan.api.searchDocuments() устарел.');
        return { success: false, message: 'Deprecated client helper' };
    },

    getStatistics: async function() {
        console.warn('FixariVan.api.getStatistics() устарел.');
        return { success: false, message: 'Deprecated client helper' };
    }
};

FixariVan.api.generatePDF = FixariVan.api.generatePDF;

FixariVan.utils.checkUserSession = async function() {
    if (typeof AuthManager !== 'undefined' && AuthManager.checkAuth) {
        return AuthManager.checkAuth();
    }
    try {
        const r = await fetch('./api/admin_session_status.php', { credentials: 'same-origin', cache: 'no-store' });
        const j = await r.json();
        return Boolean(j && j.ok);
    } catch (e) {
        return false;
    }
};

// ИНИЦИАЛИЗАЦИЯ ПРИ ЗАГРУЗКЕ СТРАНИЦЫ
document.addEventListener('DOMContentLoaded', function() {
    // Проверяем авторизацию на защищённых страницах
    const protectedPages = ['/admin_panel.html', '/inventory.html', '/reports.html'];
    const currentPath = window.location.pathname;
    
    if (protectedPages.some(page => currentPath.includes(page))) {
        FixariVan.utils.checkAuth();
    }
    
    // Добавляем стили для ошибок валидации
    if (!document.getElementById('validation-styles')) {
        const styles = document.createElement('style');
        styles.id = 'validation-styles';
        styles.textContent = `
            .error {
                border-color: #dc3545 !important;
                box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
            }
            .spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid #f3f3f3;
                border-top: 2px solid #007bff;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(styles);
    }
});

// ЭКСПОРТ ДЛЯ ГЛОБАЛЬНОГО ИСПОЛЬЗОВАНИЯ
window.showNotification = FixariVan.ui.showNotification;
window.validateForm = FixariVan.ui.validateForm;
window.generateDocumentId = FixariVan.utils.generateDocumentId;
