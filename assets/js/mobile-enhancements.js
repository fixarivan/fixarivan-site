// FixariVan Mobile Enhancements
// Advanced mobile interactions, offline support, and performance optimizations

class MobileEnhancements {
    constructor() {
        this.isOnline = navigator.onLine;
        this.touchStartY = 0;
        this.touchStartX = 0;
        this.isScrolling = false;
        this.offlineData = new Map();
        
        this.init();
    }
    
    init() {
        this.setupTouchInteractions();
        this.setupOfflineSupport();
        this.setupPerformanceOptimizations();
        this.setupAccessibility();
        this.setupAnimations();
    }
    
    // Touch interactions and gestures
    setupTouchInteractions() {
        // Prevent zoom on double tap
        let lastTouchEnd = 0;
        document.addEventListener('touchend', (e) => {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, false);
        
        // Touch feedback for buttons
        document.addEventListener('touchstart', (e) => {
            if (e.target.classList.contains('btn') || 
                e.target.classList.contains('submit-btn') ||
                e.target.classList.contains('device-type-btn') ||
                e.target.classList.contains('test-item')) {
                e.target.classList.add('touch-feedback');
            }
        });
        
        document.addEventListener('touchend', (e) => {
            setTimeout(() => {
                e.target.classList.remove('touch-feedback');
            }, 300);
        });
        
        // Swipe gestures for tabs
        this.setupSwipeGestures();
        
        // Pull to refresh
        this.setupPullToRefresh();
    }
    
    setupSwipeGestures() {
        const tabContainer = document.querySelector('.tab-navigation');
        if (!tabContainer) return;
        
        let startX = 0;
        let currentX = 0;
        let isDragging = false;
        
        tabContainer.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            isDragging = true;
        });
        
        tabContainer.addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            
            currentX = e.touches[0].clientX;
            const diff = startX - currentX;
            
            if (Math.abs(diff) > 50) {
                e.preventDefault();
                tabContainer.scrollLeft += diff * 0.5;
            }
        });
        
        tabContainer.addEventListener('touchend', () => {
            isDragging = false;
        });
    }
    
    setupPullToRefresh() {
        let startY = 0;
        let currentY = 0;
        let isPulling = false;
        
        document.addEventListener('touchstart', (e) => {
            if (window.scrollY === 0) {
                startY = e.touches[0].clientY;
                isPulling = true;
            }
        });
        
        document.addEventListener('touchmove', (e) => {
            if (!isPulling) return;
            
            currentY = e.touches[0].clientY;
            const diff = currentY - startY;
            
            if (diff > 100) {
                e.preventDefault();
                this.showPullToRefreshIndicator();
            }
        });
        
        document.addEventListener('touchend', () => {
            if (isPulling && currentY - startY > 100) {
                this.refreshData();
            }
            isPulling = false;
        });
    }
    
    // Offline support and data persistence
    setupOfflineSupport() {
        // Online/offline detection
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.showMessage('Соединение восстановлено', 'success');
            this.syncOfflineData();
        });
        
        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.showMessage('Работа в офлайн режиме', 'info');
        });
        
        // Auto-save form data
        this.setupAutoSave();
        
        // Service Worker registration
        this.registerServiceWorker();
    }
    
    setupAutoSave() {
        const forms = document.querySelectorAll('form');
        
        forms.forEach(form => {
            const formId = form.id || 'form_' + Math.random().toString(36).substr(2, 9);
            
            // Save form data on input
            form.addEventListener('input', (e) => {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                    this.saveFormData(formId, form);
                }
            });
            
            // Load saved data on page load
            this.loadFormData(formId, form);
        });
    }
    
    saveFormData(formId, form) {
        const formData = new FormData(form);
        const data = {};
        
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }
        
        // Save to localStorage
        localStorage.setItem(`form_${formId}`, JSON.stringify({
            data: data,
            timestamp: Date.now()
        }));
        
        // Save to offline storage if offline
        if (!this.isOnline) {
            this.offlineData.set(formId, data);
        }
    }
    
    loadFormData(formId, form) {
        const saved = localStorage.getItem(`form_${formId}`);
        if (!saved) return;
        
        try {
            const { data, timestamp } = JSON.parse(saved);
            
            // Only load if data is less than 24 hours old
            if (Date.now() - timestamp < 24 * 60 * 60 * 1000) {
                Object.entries(data).forEach(([key, value]) => {
                    const input = form.querySelector(`[name="${key}"]`);
                    if (input) {
                        input.value = value;
                    }
                });
            }
        } catch (e) {
            console.warn('Failed to load form data:', e);
        }
    }
    
    async syncOfflineData() {
        if (this.offlineData.size === 0) return;
        
        for (let [formId, data] of this.offlineData) {
            try {
                // Attempt to submit saved data
                await this.submitFormData(formId, data);
                this.offlineData.delete(formId);
            } catch (e) {
                console.warn('Failed to sync offline data:', e);
            }
        }
    }
    
    async submitFormData(formId, data) {
        // Implementation depends on specific form endpoints
        const endpoints = {
            'diagnosticForm': './api/save_report.php',
            'clientForm': './api/save_order.php',
            'receiptForm': './api/save_receipt.php'
        };
        
        const endpoint = endpoints[formId];
        if (!endpoint) return;
        
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        if (!response.ok) {
            throw new Error('Sync failed');
        }
    }
    
    // Performance optimizations
    setupPerformanceOptimizations() {
        // Lazy loading for images
        this.setupLazyLoading();
        
        // Debounced scroll events
        this.setupScrollOptimization();
        
        // Memory management
        this.setupMemoryManagement();
    }
    
    setupLazyLoading() {
        const images = document.querySelectorAll('img[data-src]');
        
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        imageObserver.unobserve(img);
                    }
                });
            });
            
            images.forEach(img => imageObserver.observe(img));
        }
    }
    
    setupScrollOptimization() {
        let scrollTimeout;
        
        window.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                this.handleScroll();
            }, 16); // ~60fps
        });
    }
    
    handleScroll() {
        // Hide/show header based on scroll direction
        const header = document.querySelector('.header');
        if (!header) return;
        
        const scrollY = window.scrollY;
        const lastScrollY = this.lastScrollY || 0;
        
        if (scrollY > lastScrollY && scrollY > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        
        this.lastScrollY = scrollY;
    }
    
    setupMemoryManagement() {
        // Clean up event listeners on page unload
        window.addEventListener('beforeunload', () => {
            this.cleanup();
        });
        
        // Periodic cleanup of old data
        setInterval(() => {
            this.cleanupOldData();
        }, 5 * 60 * 1000); // Every 5 minutes
    }
    
    cleanupOldData() {
        const keys = Object.keys(localStorage);
        const now = Date.now();
        
        keys.forEach(key => {
            if (key.startsWith('form_')) {
                try {
                    const data = JSON.parse(localStorage.getItem(key));
                    if (now - data.timestamp > 24 * 60 * 60 * 1000) { // 24 hours
                        localStorage.removeItem(key);
                    }
                } catch (e) {
                    localStorage.removeItem(key);
                }
            }
        });
    }
    
    // Accessibility enhancements
    setupAccessibility() {
        // Keyboard navigation
        this.setupKeyboardNavigation();
        
        // Screen reader support
        this.setupScreenReaderSupport();
        
        // High contrast mode
        this.setupHighContrast();
    }
    
    setupKeyboardNavigation() {
        document.addEventListener('keydown', (e) => {
            // Tab navigation for custom elements
            if (e.key === 'Tab') {
                this.handleTabNavigation(e);
            }
            
            // Enter key for custom buttons
            if (e.key === 'Enter' && e.target.classList.contains('device-type-btn')) {
                e.target.click();
            }
        });
    }
    
    handleTabNavigation(e) {
        const focusableElements = document.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        
        if (e.shiftKey && document.activeElement === firstElement) {
            e.preventDefault();
            lastElement.focus();
        } else if (!e.shiftKey && document.activeElement === lastElement) {
            e.preventDefault();
            firstElement.focus();
        }
    }
    
    setupScreenReaderSupport() {
        // Announce dynamic content changes
        const announcer = document.createElement('div');
        announcer.setAttribute('aria-live', 'polite');
        announcer.setAttribute('aria-atomic', 'true');
        announcer.style.position = 'absolute';
        announcer.style.left = '-10000px';
        announcer.style.width = '1px';
        announcer.style.height = '1px';
        announcer.style.overflow = 'hidden';
        
        document.body.appendChild(announcer);
        this.announcer = announcer;
    }
    
    setupHighContrast() {
        if (window.matchMedia('(prefers-contrast: high)').matches) {
            document.body.classList.add('high-contrast');
        }
    }
    
    // Animations and visual feedback
    setupAnimations() {
        // Intersection Observer for animations
        this.setupScrollAnimations();
        
        // Loading states
        this.setupLoadingStates();
        
        // Micro-interactions
        this.setupMicroInteractions();
    }
    
    setupScrollAnimations() {
        const animatedElements = document.querySelectorAll('.stat-card, .test-item, .device-type-btn');
        
        if ('IntersectionObserver' in window) {
            const animationObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('slide-in');
                    }
                });
            }, { threshold: 0.1 });
            
            animatedElements.forEach(el => animationObserver.observe(el));
        }
    }
    
    setupLoadingStates() {
        // Add loading states to forms
        const forms = document.querySelectorAll('form');
        
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                const submitBtn = form.querySelector('.submit-btn');
                if (submitBtn) {
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                }
            });
        });
    }
    
    setupMicroInteractions() {
        // Hover effects for touch devices
        document.addEventListener('touchstart', (e) => {
            if (e.target.classList.contains('interactive')) {
                e.target.classList.add('active');
            }
        });
        
        document.addEventListener('touchend', (e) => {
            setTimeout(() => {
                e.target.classList.remove('active');
            }, 150);
        });
    }
    
    // Utility methods
    showMessage(message, type = 'info') {
        const messageEl = document.createElement('div');
        messageEl.className = `mobile-message ${type}`;
        messageEl.textContent = message;
        messageEl.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#1C5FDD'};
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            z-index: 1000;
            font-weight: 600;
        `;
        
        document.body.appendChild(messageEl);
        
        setTimeout(() => {
            messageEl.remove();
        }, 3000);
    }
    
    showPullToRefreshIndicator() {
        // Implementation for pull-to-refresh indicator
        console.log('Pull to refresh triggered');
    }
    
    async refreshData() {
        // Refresh page data
        if (typeof loadDashboard === 'function') {
            await loadDashboard();
        }
        location.reload();
    }
    
    cleanup() {
        // Clean up event listeners and data
        this.offlineData.clear();
    }
    
    // Service Worker registration
    async registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.register('./sw.js');
                console.log('Service Worker registered:', registration);
            } catch (error) {
                console.log('Service Worker registration failed:', error);
            }
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new MobileEnhancements();
});

// Export for use in other scripts
window.MobileEnhancements = MobileEnhancements;