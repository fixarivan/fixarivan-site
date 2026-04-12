/**
 * АВТОМАТИЧЕСКАЯ СИНХРОНИЗАЦИЯ СКЛАДА (legacy MySQL sync_inventory.php)
 * По умолчанию ВЫКЛЮЧЕНО: установите window.FIXARIVAN_ENABLE_LEGACY_MYSQL_SYNC = true до загрузки скрипта.
 */
(function () {
    if (typeof window !== 'undefined' && window.FIXARIVAN_ENABLE_LEGACY_MYSQL_SYNC !== true) {
        window.AutoInventorySync = {
            init: function () {},
            autoSync: async function () {},
            forceSync: async function () {}
        };
        return;
    }

const AutoInventorySync = {
    isOnline: navigator.onLine,
    syncInProgress: false,
    lastSync: 0,
    syncInterval: 30000, // 30 секунд
    
    // Инициализация автосинхронизации
    init() {
        console.log('🔄 Автосинхронизация склада запущена');
        
        // Слушаем изменения в localStorage
        this.watchLocalStorage();
        
        // Периодическая синхронизация
        this.startPeriodicSync();
        
        // Синхронизация при изменении онлайн статуса
        this.watchOnlineStatus();
        
        // Синхронизация при загрузке страницы
        this.syncOnLoad();
    },
    
    // Отслеживание изменений в localStorage
    watchLocalStorage() {
        const originalSetItem = localStorage.setItem;
        const self = this;
        
        localStorage.setItem = function(key, value) {
            originalSetItem.apply(this, arguments);
            
            // Если изменился склад - синхронизируем
            if (key === 'fixarivan_inventory') {
                console.log('📦 Обнаружены изменения в складе, синхронизируем...');
                self.autoSync();
            }
        };
    },
    
    // Автоматическая синхронизация
    async autoSync() {
        if (this.syncInProgress || !this.isOnline) {
            return;
        }
        
        this.syncInProgress = true;
        
        try {
            const inventory = JSON.parse(localStorage.getItem('fixarivan_inventory') || '[]');
            
            if (inventory.length === 0) {
                return;
            }
            
            console.log('🔄 Автосинхронизация склада...');
            
            const response = await fetch('./api/sync_inventory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ inventory: inventory })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.lastSync = Date.now();
                console.log('✅ Склад автоматически синхронизирован');
                
                // Обновляем статистику на дашборде если он открыт
                if (window.loadInventoryStats) {
                    window.loadInventoryStats();
                }
            } else {
                console.error('❌ Ошибка автосинхронизации:', result.message);
            }
            
        } catch (error) {
            console.error('❌ Ошибка автосинхронизации:', error);
        } finally {
            this.syncInProgress = false;
        }
    },
    
    // Периодическая синхронизация
    startPeriodicSync() {
        setInterval(() => {
            if (this.isOnline && !this.syncInProgress) {
                this.autoSync();
            }
        }, this.syncInterval);
        
        console.log(`🔄 Периодическая синхронизация каждые ${this.syncInterval/1000} секунд`);
    },
    
    // Отслеживание онлайн статуса
    watchOnlineStatus() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            console.log('🌐 Интернет восстановлен, синхронизируем склад...');
            this.autoSync();
        });
        
        window.addEventListener('offline', () => {
            this.isOnline = false;
            console.log('📴 Интернет потерян, синхронизация приостановлена');
        });
    },
    
    // Синхронизация при загрузке страницы
    async syncOnLoad() {
        // Ждём 2 секунды после загрузки
        setTimeout(async () => {
            const inventory = JSON.parse(localStorage.getItem('fixarivan_inventory') || '[]');
            if (inventory.length > 0) {
                console.log('📦 Загружен склад, синхронизируем с БД...');
                await this.autoSync();
            }
        }, 2000);
    },
    
    // Принудительная синхронизация (для кнопки)
    async forceSync() {
        console.log('🔄 Принудительная синхронизация...');
        await this.autoSync();
    }
};

// Запускаем автосинхронизацию при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    AutoInventorySync.init();
});

window.AutoInventorySync = AutoInventorySync;
})();
