/**
 * СИНХРОНИЗАЦИЯ СКЛАДА МЕЖДУ УСТРОЙСТВАМИ
 * Решает проблему разных localStorage на разных устройствах
 */

// Функции синхронизации склада
const InventorySync = {
    
    // Синхронизация склада с БД
    async syncToDB(inventory) {
        try {
            const response = await fetch('./api/sync_inventory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ inventory: inventory })
            });
            
            const result = await response.json();
            if (result.success) {
                console.log('✅ Склад синхронизирован с БД');
                return true;
            } else {
                console.error('❌ Ошибка синхронизации:', result.message);
                return false;
            }
        } catch (error) {
            console.error('❌ Ошибка синхронизации с БД:', error);
            return false;
        }
    },
    
    // Загрузка склада из БД
    async loadFromDB() {
        try {
            const response = await fetch('./api/sync_inventory.php');
            const result = await response.json();
            
            if (result.success && result.inventory && result.inventory.length > 0) {
                console.log('✅ Склад загружен из БД:', result.inventory.length, 'позиций');
                return result.inventory;
            } else {
                console.log('ℹ️ В БД нет данных склада');
                return null;
            }
        } catch (error) {
            console.error('❌ Ошибка загрузки из БД:', error);
            return null;
        }
    },
    
    // Получение статистики склада из БД
    async getStatsFromDB() {
        try {
            const response = await fetch('./api/get_inventory_stats.php');
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Статистика склада загружена из БД:', result.data);
                return result.data;
            } else {
                console.error('❌ Ошибка загрузки статистики:', result.message);
                return null;
            }
        } catch (error) {
            console.error('❌ Ошибка загрузки статистики:', error);
            return null;
        }
    },
    
    // Автоматическая синхронизация при загрузке страницы
    async autoSync() {
        console.log('🔄 Автоматическая синхронизация склада...');
        
        // 1. Загружаем из БД
        const dbInventory = await this.loadFromDB();
        
        if (dbInventory) {
            // Если в БД есть данные, используем их
            localStorage.setItem('fixarivan_inventory', JSON.stringify(dbInventory));
            console.log('✅ Склад синхронизирован с БД');
            return dbInventory;
        } else {
            // Если в БД нет данных, синхронизируем localStorage в БД
            const localInventory = JSON.parse(localStorage.getItem('fixarivan_inventory') || '[]');
            if (localInventory.length > 0) {
                await this.syncToDB(localInventory);
                console.log('✅ localStorage синхронизирован с БД');
            }
            return localInventory;
        }
    },
    
    // Периодическая синхронизация
    startPeriodicSync(intervalMs = 30000) {
        setInterval(async () => {
            const localInventory = JSON.parse(localStorage.getItem('fixarivan_inventory') || '[]');
            if (localInventory.length > 0) {
                await this.syncToDB(localInventory);
            }
        }, intervalMs);
        
        console.log(`🔄 Периодическая синхронизация каждые ${intervalMs/1000} секунд`);
    }
};

// Экспорт для использования
window.InventorySync = InventorySync;
