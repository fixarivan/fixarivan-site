/**
 * FixariVan — Новый заказ: мобильный wizard (≤768px).
 * Форма и API не меняются — только UX.
 */
(function (global) {
    'use strict';

    const STORAGE_STEP = 'fixarivan_order_new_wizard_step_v1';
    const STORAGE_TYPE = 'fixarivan_order_new_wizard_type_v1';

    const TYPE_OPTIONS = [
        { id: 'repair', icon: '🔧', label: 'Ремонт', desc: 'Акт приёма: устройство, неисправность' },
        { id: 'sale', icon: '📦', label: 'Продажа', desc: 'Позиции и склад, без полей ремонта' },
        { id: 'custom', icon: '⚙️', label: 'Нестандарт', desc: 'Другое: камеры, Wi‑Fi, настройка, выезд' },
    ];

    const PROGRESS = [
        { n: 1, label: 'Клиент' },
        { n: 2, label: 'Устройство' },
        { n: 3, label: 'Стоимость' },
        { n: 4, label: 'Готово' },
    ];

    let currentStep = 1;
    let selectedType = '';
    let wizardStarted = false;
    let lastSave = null;

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function isMobile() {
        return global.matchMedia && global.matchMedia('(max-width: 768px)').matches;
    }

    function el(id) { return document.getElementById(id); }

    function modeFromType(typeId) {
        if (typeId === 'sale') return 'sale';
        if (typeId === 'custom') return 'custom';
        return 'repair';
    }

    function stepBlocks(step, mode) {
        if (step === 1) return ['client'];
        if (step === 2) {
            if (mode === 'repair') return ['device', 'description'];
            return ['description'];
        }
        if (step === 3) return ['portal', 'lines'];
        if (step === 4) {
            const b = ['acceptance'];
            return mode === 'repair' ? b : [];
        }
        return [];
    }

    function setOrderMode(mode) {
        global.orderModeUserOverride = true;
        const sel = el('orderMode');
        if (sel) sel.value = mode;
        if (typeof global.updateOrderTypeUI === 'function') {
            global.updateOrderTypeUI(mode);
        }
        if (typeof global.refreshOrderModeAutoNote === 'function') {
            global.refreshOrderModeAutoNote();
        }
    }

    function applyStepVisibility() {
        const mode = el('orderMode') ? el('orderMode').value : 'repair';
        const visible = new Set();
        for (let s = 1; s <= 4; s++) {
            if (s !== currentStep) continue;
            stepBlocks(s, mode).forEach(function (b) { visible.add(b); });
        }
        document.querySelectorAll('#orderForm .order-type-block[data-block-name]').forEach(function (block) {
            const name = block.getAttribute('data-block-name') || '';
            block.classList.toggle('onw-step-visible', visible.has(name));
        });
        const summary = el('onwSummary');
        if (summary) {
            summary.hidden = currentStep !== 4;
            if (currentStep === 4) renderSummary(summary, mode);
        }
    }

    function fmtMoney(v) {
        if (typeof global.formatMoney === 'function') return global.formatMoney(Number(v) || 0, 'ru');
        return (Number(v) || 0).toFixed(2).replace('.', ',') + ' €';
    }

    function renderSummary(container, mode) {
        const lines = typeof global.collectLines === 'function' ? global.collectLines() : [];
        let partsTotal = 0;
        lines.forEach(function (ln) {
            partsTotal += (Number(ln.sale) || 0) * (Number(ln.qty) || 1);
        });
        const client = (el('clientName') && el('clientName').value.trim()) || '—';
        const phone = (el('clientPhone') && el('clientPhone').value.trim()) || '—';
        const device = mode === 'repair'
            ? ((el('deviceModel') && el('deviceModel').value.trim()) || '—')
            : '—';
        const problem = (el('problemDescription') && el('problemDescription').value.trim()) || '—';
        const work = (el('publicEstimatedCost') && el('publicEstimatedCost').value.trim()) || '—';
        const linesLabel = lines.length ? lines.length + ' поз.' : '—';

        container.innerHTML =
            '<div class="onw-summary">' +
            '<div class="onw-summary-title">Итог перед созданием</div>' +
            row('Клиент', client) +
            row('Телефон', phone) +
            (mode === 'repair' ? row('Устройство', device) : '') +
            row('Описание', problem.length > 48 ? problem.slice(0, 48) + '…' : problem) +
            row('Запчасти', linesLabel) +
            row('Работа / ориентир', work) +
            (partsTotal > 0 ? '<div class="onw-summary-total">Позиции: ' + fmtMoney(partsTotal) + '</div>' : '') +
            '</div>';

        function row(label, value) {
            return '<div class="onw-summary-row"><span>' + esc(label) + '</span><span>' + esc(value) + '</span></div>';
        }
    }

    function validateStep(step) {
        const mode = el('orderMode') ? el('orderMode').value : 'repair';
        if (step === 1) {
            const name = el('clientName');
            const phone = el('clientPhone');
            if (!name || !name.value.trim()) {
                name && name.focus();
                alert('Укажите имя клиента');
                return false;
            }
            if (!phone || !phone.value.trim()) {
                phone && phone.focus();
                alert('Укажите телефон клиента');
                return false;
            }
            return true;
        }
        if (step === 2 && mode === 'repair') {
            const dt = el('deviceType');
            if (dt && dt.required && (!dt.value || dt.value === '')) {
                dt.focus();
                alert('Выберите тип устройства');
                return false;
            }
            return true;
        }
        if (step === 3 && (mode === 'sale' || mode === 'custom')) {
            const lines = typeof global.collectLines === 'function' ? global.collectLines() : [];
            if (!lines.length) {
                alert('Добавьте хотя бы одну позицию');
                return false;
            }
        }
        return true;
    }

    function updateProgress() {
        document.querySelectorAll('.onw-progress-step').forEach(function (node) {
            const n = Number(node.getAttribute('data-step'));
            node.classList.toggle('is-active', n === currentStep);
            node.classList.toggle('is-done', n < currentStep);
        });
        const back = el('onwBackBtn');
        const next = el('onwNextBtn');
        if (back) back.hidden = currentStep <= 1;
        if (next) {
            next.textContent = currentStep >= 4 ? 'Создать заказ' : 'Далее →';
            next.classList.toggle('is-primary', true);
        }
        const footer = el('onwFooter');
        if (footer) footer.hidden = !wizardStarted;
        applyStepVisibility();
    }

    function goStep(step) {
        currentStep = Math.max(1, Math.min(4, step));
        try { localStorage.setItem(STORAGE_STEP, String(currentStep)); } catch (_) { /* ignore */ }
        updateProgress();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function startWizard(typeId) {
        if (typeId === 'hybrid') typeId = 'repair';
        selectedType = typeId;
        try { localStorage.setItem(STORAGE_TYPE, typeId); } catch (_) { /* ignore */ }
        setOrderMode(modeFromType(typeId));
        wizardStarted = true;
        const typeScreen = el('onwTypeScreen');
        const wizardForm = el('onwWizardForm');
        const form = el('orderForm');
        if (typeScreen) typeScreen.hidden = true;
        if (wizardForm) wizardForm.hidden = false;
        if (form) form.hidden = false;
        mountFormInWizard();
        goStep(1);
    }

    function buildTypeScreen() {
        const grid = el('onwTypeGrid');
        if (!grid || grid.dataset.built === '1') return;
        grid.dataset.built = '1';
        grid.innerHTML = TYPE_OPTIONS.map(function (opt) {
            return (
                '<button type="button" class="onw-type-card" data-type="' + opt.id + '">' +
                '<div class="onw-type-card-icon">' + opt.icon + '</div>' +
                '<div class="onw-type-card-label">' + esc(opt.label) + '</div>' +
                '<div class="onw-type-card-desc">' + esc(opt.desc) + '</div></button>'
            );
        }).join('');
        const startBtn = el('onwTypeStart');
        grid.querySelectorAll('.onw-type-card').forEach(function (card) {
            card.addEventListener('click', function () {
                grid.querySelectorAll('.onw-type-card').forEach(function (c) {
                    c.classList.remove('is-selected');
                });
                card.classList.add('is-selected');
                selectedType = card.getAttribute('data-type') || '';
                if (startBtn) startBtn.disabled = !selectedType;
            });
        });
        if (startBtn) {
            startBtn.addEventListener('click', function () {
                if (!selectedType) return;
                startWizard(selectedType);
            });
        }
    }

    function buildProgress() {
        const bar = el('onwProgress');
        if (!bar || bar.dataset.built === '1') return;
        bar.dataset.built = '1';
        bar.innerHTML = PROGRESS.map(function (p) {
            return (
                '<div class="onw-progress-step" data-step="' + p.n + '">' +
                '<div class="onw-progress-dot"></div>' +
                '<div class="onw-progress-label">' + esc(p.label) + '</div></div>'
            );
        }).join('');
    }

    function bindFooter() {
        const back = el('onwBackBtn');
        const next = el('onwNextBtn');
        if (back && !back.dataset.bound) {
            back.dataset.bound = '1';
            back.addEventListener('click', function () {
                if (currentStep <= 1) return;
                goStep(currentStep - 1);
            });
        }
        if (next && !next.dataset.bound) {
            next.dataset.bound = '1';
            next.addEventListener('click', function () {
                if (!validateStep(currentStep)) return;
                if (currentStep >= 4) {
                    const form = el('orderForm');
                    const modeSelect = el('orderMode');
                    if (modeSelect && !global.orderModeUserOverride && typeof global.inferOrderMode === 'function') {
                        modeSelect.value = global.inferOrderMode();
                    }
                    if (typeof global.updateOrderTypeUI === 'function') {
                        global.updateOrderTypeUI(modeSelect ? modeSelect.value : 'repair');
                    }
                    if (form && !form.reportValidity()) return;
                    submitOrder();
                    return;
                }
                goStep(currentStep + 1);
            });
        }
    }

    function submitOrder() {
        const form = el('orderForm');
        const btn = el('submitBtn');
        if (!form) return;
        if (btn) btn.click();
    }

    function buildPortalWhatsappMessage(clientName, portalUrl) {
        const name = String(clientName || '').trim();
        const u = String(portalUrl || '').trim();
        return (
            'Здравствуйте' + (name ? ', ' + name : '') + '!\n\n' +
            'Ваш заказ успешно зарегистрирован.\n\n' +
            'Следить за статусом ремонта можно здесь:\n\n' +
            u + '\n\n' +
            'Спасибо!\nFixariVan'
        );
    }

    function whatsappUrl(phone, message) {
        let digits = String(phone || '').replace(/\D/g, '');
        if (!digits) return '';
        if (digits.indexOf('358') !== 0 && digits.charAt(0) === '0') {
            digits = '358' + digits.slice(1);
        }
        return 'https://wa.me/' + digits + '?text=' + encodeURIComponent(message);
    }

    async function copyText(text) {
        if (typeof global.copyTextToClipboard === 'function') {
            return global.copyTextToClipboard(text);
        }
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (_) {
            return false;
        }
    }

    function showSuccess(data) {
        lastSave = data;
        const panel = el('onwSuccess');
        if (!panel) return false;
        const orderId = data.orderId || '—';
        const link = data.link || '';
        const clientName = data.clientName || '';
        const phone = data.clientPhone || '';
        const msg = buildPortalWhatsappMessage(clientName, link);
        const wa = whatsappUrl(phone, msg);

        el('onwSuccessOrderId').textContent = orderId;
        const openBtn = el('onwOpenOrderBtn');
        if (openBtn) {
            openBtn.href = 'track.html?q=' + encodeURIComponent(orderId);
        }
        const copyBtn = el('onwCopyMsgBtn');
        if (copyBtn) {
            copyBtn.onclick = async function () {
                const ok = await copyText(msg);
                if (ok) {
                    copyBtn.textContent = 'Скопировано ✓';
                    setTimeout(function () { copyBtn.textContent = '🔗 Копировать сообщение'; }, 2000);
                } else {
                    alert('Не удалось скопировать');
                }
            };
        }
        const waBtn = el('onwWhatsappBtn');
        if (waBtn) {
            if (wa) {
                waBtn.href = wa;
                waBtn.style.display = '';
            } else {
                waBtn.style.display = 'none';
            }
        }
        const app = el('onwApp');
        if (app) app.hidden = true;
        panel.hidden = false;
        document.getElementById('orderForm').hidden = true;
        window.scrollTo(0, 0);
        return true;
    }

    function resetForNewOrder() {
        const panel = el('onwSuccess');
        if (panel) panel.hidden = true;
        const app = el('onwApp');
        if (app) app.hidden = false;
        document.getElementById('orderForm').hidden = false;
        wizardStarted = false;
        currentStep = 1;
        selectedType = '';
        const typeScreen = el('onwTypeScreen');
        const wizardForm = el('onwWizardForm');
        if (typeScreen) typeScreen.hidden = false;
        if (wizardForm) wizardForm.hidden = true;
        el('onwFooter').hidden = true;
        if (typeof global.genId === 'function') {
            el('documentId').value = global.genId();
        }
        el('orderForm').reset();
        el('documentId').value = typeof global.genId === 'function' ? global.genId() : el('documentId').value;
        el('dateOfAcceptance').value = new Date().toISOString().split('T')[0];
        el('placeOfAcceptance').value = 'FixariVan, Turku';
        const tbody = el('linesBody');
        if (tbody) tbody.innerHTML = '';
        if (typeof global.addLineRow === 'function') global.addLineRow({});
        global.orderModeUserOverride = false;
        if (typeof global.applyAutoOrderMode === 'function') global.applyAutoOrderMode();
        window.location.reload();
    }

    function handleSaveSuccess(ctx) {
        if (!isMobile() || !wizardStarted) return false;
        showSuccess({
            orderId: ctx.orderId,
            link: ctx.link,
            clientName: ctx.payload && ctx.payload.clientName,
            clientPhone: ctx.payload && ctx.payload.clientPhone,
            token: ctx.token,
        });
        if (ctx.supplyWarning) {
            setTimeout(function () { alert(ctx.supplyWarning); }, 400);
        }
        return true;
    }

    function mountFormInWizard() {
        const form = el('orderForm');
        const wizardForm = el('onwWizardForm');
        if (!form || !wizardForm || form.parentElement === wizardForm) return;
        wizardForm.appendChild(form);
    }

    function unmountFormFromWizard() {
        const form = el('orderForm');
        const container = document.querySelector('.container');
        if (!form || !container || form.parentElement !== el('onwWizardForm')) return;
        container.appendChild(form);
    }

    function injectShell() {
        if (el('onwApp')) return;
        const app = document.createElement('div');
        app.className = 'onw-app';
        app.id = 'onwApp';
        app.innerHTML =
            '<div class="onw-top">' +
            '<div class="onw-top-row">' +
            '<button type="button" class="onw-icon-btn" id="onwCloseBtn" aria-label="Закрыть">×</button>' +
            '<div class="onw-top-title">Новый заказ</div>' +
            '<a class="onw-icon-btn" href="dashboard_app.html" aria-label="Рабочий стол">⌂</a>' +
            '</div>' +
            '<div class="onw-progress" id="onwProgress"></div>' +
            '</div>' +
            '<div class="onw-type-screen" id="onwTypeScreen">' +
            '<div class="onw-type-title">Выберите тип заказа</div>' +
            '<div class="onw-type-sub">От типа зависят поля мастера. Всю логику склада и портала сохраняем без изменений.</div>' +
            '<div class="onw-type-grid" id="onwTypeGrid"></div>' +
            '<button type="button" class="onw-type-start" id="onwTypeStart" disabled>Начать</button>' +
            '</div>' +
            '<div class="onw-wizard-form" id="onwWizardForm" hidden>' +
            '<div id="onwSummary" hidden></div>' +
            '</div>' +
            '<div class="onw-footer" id="onwFooter" hidden>' +
            '<button type="button" class="onw-footer-btn" id="onwBackBtn">← Назад</button>' +
            '<button type="button" class="onw-footer-btn is-primary" id="onwNextBtn">Далее →</button>' +
            '</div>';

        document.body.insertBefore(app, document.body.firstChild);

        const success = document.createElement('div');
        success.className = 'onw-success';
        success.id = 'onwSuccess';
        success.hidden = true;
        success.innerHTML =
            '<div class="onw-success-icon">✓</div>' +
            '<div class="onw-success-title">Заказ создан!</div>' +
            '<div class="onw-success-order" id="onwSuccessOrderId"></div>' +
            '<div class="onw-success-actions">' +
            '<a class="onw-success-btn is-primary" id="onwOpenOrderBtn" href="track.html">✅ Открыть заказ</a>' +
            '<button type="button" class="onw-success-btn" id="onwCopyMsgBtn">🔗 Копировать сообщение</button>' +
            '<a class="onw-success-btn is-wa" id="onwWhatsappBtn" href="#" target="_blank" rel="noopener noreferrer">💬 WhatsApp клиенту</a>' +
            '</div>' +
            '<button type="button" class="onw-success-btn" id="onwNewOrderBtn">➕ Создать ещё один заказ</button>';
        document.body.appendChild(success);

        el('onwCloseBtn').addEventListener('click', function () {
            if (confirm('Закрыть форму? Несохранённые данные будут потеряны.')) {
                window.location.href = 'dashboard_app.html';
            }
        });
        el('onwNewOrderBtn').addEventListener('click', resetForNewOrder);

        buildTypeScreen();
        buildProgress();
        bindFooter();
    }

    function teardown() {
        document.body.classList.remove('order-new-mobile-wizard');
        unmountFormFromWizard();
        const app = el('onwApp');
        if (app) app.hidden = true;
        const succ = el('onwSuccess');
        if (succ) succ.hidden = true;
        document.querySelectorAll('.onw-step-visible').forEach(function (b) {
            b.classList.remove('onw-step-visible');
        });
        const form = el('orderForm');
        if (form) form.hidden = false;
    }

    function refresh() {
        if (!isMobile()) {
            teardown();
            return;
        }
        document.body.classList.add('order-new-mobile-wizard');
        injectShell();
        el('onwApp').hidden = false;
        const form = el('orderForm');
        if (!wizardStarted) {
            if (form) form.hidden = true;
            el('onwTypeScreen').hidden = false;
            el('onwWizardForm').hidden = true;
            el('onwFooter').hidden = true;
        } else {
            if (form) form.hidden = false;
            mountFormInWizard();
            el('onwWizardForm').hidden = false;
            applyStepVisibility();
            updateProgress();
        }
    }

    function initMediaListener() {
        if (global.__onwMqBound) return;
        global.__onwMqBound = true;
        const mq = global.matchMedia('(max-width: 768px)');
        const fn = function () { refresh(); };
        if (mq.addEventListener) mq.addEventListener('change', fn);
        else mq.addListener(fn);
    }

    global.OrderNewMobileWizard = {
        refresh: refresh,
        handleSaveSuccess: handleSaveSuccess,
        isMobile: isMobile,
        buildPortalWhatsappMessage: buildPortalWhatsappMessage,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMediaListener);
    } else {
        initMediaListener();
    }
})(typeof window !== 'undefined' ? window : globalThis);
