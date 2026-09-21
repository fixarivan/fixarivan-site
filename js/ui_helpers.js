(function(window) {
    const root = window.FixariVan = window.FixariVan || {};
    const http = root.http = root.http || {};
    const ui = root.ui = root.ui || {};

    if (typeof http.fetchJson !== 'function') {
        http.fetchJson = async function fetchJson(url, options = {}) {
            const response = await fetch(url, options);
            const text = await response.text();

            if (!response.ok) {
                const snippet = (text || '').trim().slice(0, 200);
                throw new Error(snippet || `HTTP ${response.status}`);
            }

            try {
                const parsed = JSON.parse(text);
                if (parsed && typeof parsed === 'object' && Object.prototype.hasOwnProperty.call(parsed, 'success') && parsed.success === false) {
                    const msg = parsed.message || (Array.isArray(parsed.errors) && parsed.errors[0]) || 'Ошибка запроса';
                    throw new Error(msg);
                }
                return parsed;
            } catch (error) {
                if (error instanceof SyntaxError) {
                    const snippet = (text || '').trim().slice(0, 200);
                    throw new Error(snippet || 'Сервер вернул некорректный ответ');
                }
                throw error;
            }
        };
    }

    let toastContainer = null;
    let ensureListenerAttached = false;
    let toastIdCounter = 0;

    function ensureToastContainer() {
        if (toastContainer && document.body?.contains(toastContainer)) {
            return toastContainer;
        }

        if (!document.body) {
            if (!ensureListenerAttached) {
                ensureListenerAttached = true;
                document.addEventListener('DOMContentLoaded', ensureToastContainer, { once: true });
            }
            return null;
        }

        toastContainer = document.createElement('div');
        toastContainer.className = 'fixarivan-toast-container';
        toastContainer.setAttribute('role', 'status');
        document.body.appendChild(toastContainer);
        return toastContainer;
    }

    function scheduleRemoveToast(toastEl, timeout) {
        if (!toastEl) return;
        const delay = typeof timeout === 'number' ? timeout : 6000;
        if (delay <= 0) return;

        setTimeout(() => {
            toastEl.classList.add('hide');
            setTimeout(() => toastEl.remove(), 220);
        }, delay);
    }

    ui.showToast = function showToast(message, type = 'info', options = {}) {
        const container = ensureToastContainer();
        if (!container) {
            // Будем пытаться позже, когда DOM готов
            document.addEventListener('DOMContentLoaded', () => ui.showToast(message, type, options), { once: true });
            return null;
        }

        const toast = document.createElement('div');
        toastIdCounter += 1;
        toast.dataset.toastId = String(toastIdCounter);
        toast.className = `fixarivan-toast ${type}`;

        if (options.title) {
            const title = document.createElement('div');
            title.className = 'toast-title';
            title.textContent = options.title;
            toast.appendChild(title);
        }

        const body = document.createElement('div');
        body.className = 'toast-message';
        body.textContent = message;
        toast.appendChild(body);

        const closeBtn = document.createElement('button');
        closeBtn.className = 'toast-close';
        closeBtn.type = 'button';
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', () => {
            toast.classList.add('hide');
            setTimeout(() => toast.remove(), 180);
        });
        toast.appendChild(closeBtn);

        container.appendChild(toast);

        scheduleRemoveToast(toast, options.timeout);

        return toastIdCounter;
    };

    ui.clearToasts = function clearToasts() {
        if (!toastContainer) return;
        [...toastContainer.children].forEach(child => child.remove());
    };

    /**
     * Понятное сообщение для toast/alert по TypeError сети, 401/403 и типичным ответам API.
     */
    ui.digitsOnly = function digitsOnly(s) {
        return String(s ?? '').replace(/\D/g, '');
    };

    /**
     * Отображение телефона с международным префиксом (+358, +380, +7 …).
     * Поиск и wa.me по-прежнему используют только цифры.
     */
    ui.formatPhoneDisplay = function formatPhoneDisplay(phoneRaw) {
        const raw = String(phoneRaw ?? '').trim();
        if (!raw) return '—';
        let d = ui.digitsOnly(raw);
        if (!d) return raw;

        if (/^0[45]\d/.test(d) && d.length >= 9 && d.length <= 11) {
            d = '358' + d.slice(1);
        }
        if (d.startsWith('8') && d.length === 11 && !raw.startsWith('+')) {
            d = '7' + d.slice(1);
        }

        if (d.startsWith('358') && d.length >= 11) {
            const rest = d.slice(3);
            const groups = rest.match(/.{1,3}/g) || [rest];
            return '+358 ' + groups.join(' ');
        }
        if (d.startsWith('380') && d.length >= 11) {
            const r = d.slice(3);
            return '+380 ' + (r.length > 7 ? r.slice(0, 2) + ' ' + r.slice(2, 5) + ' ' + r.slice(5, 7) + ' ' + r.slice(7) : r);
        }
        if (d.startsWith('375') && d.length >= 11) {
            const r = d.slice(3);
            return '+375 ' + (r.length > 7 ? r.slice(0, 2) + ' ' + r.slice(2, 5) + ' ' + r.slice(5, 7) + ' ' + r.slice(7) : r);
        }
        if (d.startsWith('7') && d.length === 11) {
            return '+7 ' + d.slice(1, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7, 9) + ' ' + d.slice(9);
        }

        if (raw.startsWith('+') || d.length >= 10) {
            return '+' + d;
        }
        return raw;
    };

    /** E.164 для полей ввода: + и цифры без пробелов. */
    ui.formatPhoneInput = function formatPhoneInput(phoneRaw) {
        const raw = String(phoneRaw ?? '').trim();
        if (!raw) return '';
        let d = ui.digitsOnly(raw);
        if (!d) return raw;
        if (/^0[45]\d/.test(d) && d.length >= 9 && d.length <= 11) {
            d = '358' + d.slice(1);
        }
        if (d.startsWith('8') && d.length === 11 && !raw.startsWith('+')) {
            d = '7' + d.slice(1);
        }
        if (d.length >= 8) return '+' + d;
        return raw;
    };

    ui.humanizeApiError = function humanizeApiError(err) {
        const raw = String((err && err.message) != null ? err.message : err || 'Ошибка');
        const low = raw.toLowerCase();
        if (raw.includes('401') || low.includes('требуется вход') || low.includes('unauthorized')) {
            return 'Требуется вход. Обновите страницу и войдите как администратор.';
        }
        if (raw.includes('403') || low.includes('forbidden') || low.includes('доступ запрещён') || low.includes('доступ запрещен')) {
            return 'Доступ запрещён. Обновите страницу и войдите как администратор.';
        }
        if (low.includes('failed to fetch') || low.includes('networkerror') || low.includes('load failed') || low.includes('network request failed')) {
            return 'Нет соединения с сервером. Проверьте интернет и попробуйте снова.';
        }
        if (low.includes('не найдено') || low.includes('not found')) {
            return 'Запись не найдена.';
        }
        if (low.includes('timeout') || low.includes('timed out')) {
            return 'Превышено время ожидания ответа сервера.';
        }
        return raw;
    };
})(window);

