/**
 * ВРЕМЕННО ОТКЛЮЧЕННАЯ система авторизации
 * Для тестирования без авторизации
 */

// AuthManager объект для управления авторизацией
const AuthManager = {
    // Проверка авторизации - ОТКЛЮЧЕНА
    checkAuth() {
        console.log('Auth check disabled for testing');
        return true; // Всегда разрешаем доступ
    },
    
    // Вход в систему
    login(username, password) {
        console.log('Login disabled for testing');
        return true;
    },
    
    // Выход из системы
    logout() {
        console.log('Logout disabled for testing');
        // Не перенаправляем
    },
    
    // Получить имя пользователя
    getUsername() {
        return 'Test User';
    },

    getAuthToken() {
        return null;
    },
    
    // Проверить время сессии
    checkSession() {
        return true;
    }
};

// НЕ проверяем авторизацию при загрузке
console.log('Auth system disabled for testing');

// Экспорт для использования в других скриптах
window.AuthManager = AuthManager;
