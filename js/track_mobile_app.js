/**
 * FixariVan Track — мобильный app-like UI (≤768px). Десктоп и API не меняются.
 */
(function (global) {
    'use strict';

    const TAB_SECTIONS = {
        details: ['parts', 'info', 'comments'],
        parts: ['parts'],
        finance: [],
        documents: ['documents'],
    };

    const HERO_VISIBLE_TABS = ['details', 'parts', 'finance'];

    const STEPS = [
        { id: 'accepted', label: 'Принят' },
        { id: 'diagnosis', label: 'Диагностика' },
        { id: 'parts', label: 'Запчасти' },
        { id: 'repair', label: 'Ремонт' },
        { id: 'ready', label: 'Готов' },
        { id: 'delivered', label: 'Выдан' },
    ];

    const STORAGE_KEY = 'fixarivan_track_mobile_tab_v1';

    function escAttr(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function portalHref(url, token) {
        const u = String(url || '').trim();
        if (u) return u;
        const t = String(token || '').trim();
        if (t) return 'client_portal.php?token=' + encodeURIComponent(t);
        return '';
    }

    function isMobile() {
        return global.matchMedia && global.matchMedia('(max-width: 768px)').matches;
    }

    function getSavedTab() {
        try {
            const t = localStorage.getItem(STORAGE_KEY);
            if (t === 'history') return 'details';
            if (t && TAB_SECTIONS[t]) return t;
        } catch (_) { /* ignore */ }
        return 'details';
    }

    function saveTab(tab) {
        try { localStorage.setItem(STORAGE_KEY, tab); } catch (_) { /* ignore */ }
    }

    function deviceEmoji(model, type) {
        const s = (String(model || '') + ' ' + String(type || '')).toLowerCase();
        if (/iphone|ipad|samsung|pixel|phone|mobile|телефон/.test(s)) return '📱';
        if (/macbook|laptop|asus|lenovo|hp|ноутбук/.test(s)) return '💻';
        if (/printer|laserjet|принтер/.test(s)) return '🖨';
        if (/imac|desktop|pc|монитор/.test(s)) return '🖥';
        if (/tablet|планшет/.test(s)) return '📱';
        return '🔧';
    }

    function pubStatusStep(pub, partsStatus, hasReport) {
        const p = String(pub || '').toLowerCase();
        const ps = String(partsStatus || '').toLowerCase();
        if (p === 'delivered' || p === 'signed') return 5;
        if (p === 'done' || p === 'ready') return 4;
        if (p === 'in_progress' && ps && ps !== 'none' && ps !== '—') return 3;
        if (p === 'waiting_parts' || p.includes('part') || p.includes('wait') || (ps && ps !== 'none')) return 2;
        if (hasReport) return 1;
        if (p === 'cancelled') return 0;
        return p === 'in_progress' || p === 'in_transit' ? 1 : 0;
    }

    function tabForSection(sectionId) {
        const id = String(sectionId || '');
        return Object.keys(TAB_SECTIONS).find((tab) => TAB_SECTIONS[tab].indexOf(id) !== -1) || null;
    }

    function applyTab(tab, root) {
        const scope = root || document;
        Object.keys(TAB_SECTIONS).forEach((key) => {
            const active = key === tab;
            scope.querySelectorAll('.track-m-nav-btn[data-tab="' + key + '"]').forEach((btn) => {
                btn.classList.toggle('is-active', active);
            });
        });
        scope.querySelectorAll('.track-section[data-section]').forEach((sec) => {
            const sid = sec.getAttribute('data-section') || '';
            const secTab = tabForSection(sid);
            const show = secTab === tab;
            sec.classList.toggle('track-m-tab-hidden', !show);
            if (show) sec.classList.remove('is-collapsed');
        });
        scope.querySelectorAll('.track-m-hero, .track-m-stepper').forEach((el) => {
            el.classList.toggle('track-m-tab-hidden', HERO_VISIBLE_TABS.indexOf(tab) === -1);
        });
        scope.querySelectorAll('.track-m-cost-card').forEach((el) => {
            el.classList.toggle('track-m-tab-hidden', HERO_VISIBLE_TABS.indexOf(tab) === -1);
            el.querySelectorAll('.track-pricing-row.is-internal').forEach((row) => {
                row.style.display = tab === 'finance' ? '' : 'none';
            });
        });
        const nav = document.getElementById('trackMobileNav');
        if (nav) nav.dataset.activeTab = tab;
    }

    function placePrepayAfterParts(card) {
        const prepay = card.querySelector('.track-prepay-wrap');
        const partsBody = card.querySelector('.track-section[data-section="parts"] .track-section-body');
        if (!prepay || !partsBody) return;
        const actions = partsBody.querySelector('.order-lines-actions');
        if (actions) {
            if (prepay.parentElement !== partsBody) partsBody.appendChild(prepay);
            if (actions.nextSibling !== prepay) partsBody.insertBefore(prepay, actions.nextSibling);
        } else {
            partsBody.appendChild(prepay);
        }
        prepay.dataset.homePart = '1';
    }

    function buildMobileCostCard(card) {
        const body = card.querySelector('.track-order-body');
        const pricingSec = card.querySelector('.track-section[data-section="pricing"]');
        if (!body || !pricingSec) return;

        let cost = body.querySelector('.track-m-cost-card');
        if (!cost) {
            cost = document.createElement('div');
            cost.className = 'track-m-cost-card';
            cost.innerHTML = '<div class="track-m-cost-head"><span class="track-m-cost-title">Стоимость</span></div><div class="track-m-cost-body"></div>';
            const stepper = body.querySelector('.track-m-stepper');
            if (stepper && stepper.nextSibling) body.insertBefore(cost, stepper.nextSibling);
            else body.insertBefore(cost, stepper ? stepper.nextSibling : body.children[1] || null);
        }

        const costBody = cost.querySelector('.track-m-cost-body');
        const workWrap = pricingSec.querySelector('.track-work-wrap');
        const summary = pricingSec.querySelector('.track-pricing-summary');
        if (workWrap && costBody && workWrap.parentElement !== costBody) {
            costBody.appendChild(workWrap);
        }
        if (summary && costBody && summary.parentElement !== costBody) {
            costBody.appendChild(summary);
        }
    }

    function buildMobilePartsToolbar(card) {
        const partsBody = card.querySelector('.track-section[data-section="parts"] .track-section-body');
        const box = partsBody && partsBody.querySelector('.order-lines-box');
        if (!partsBody || !box) return;

        let toolbar = partsBody.querySelector('.track-m-parts-toolbar');
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.className = 'track-m-parts-toolbar';
            toolbar.innerHTML =
                '<button type="button" class="track-m-parts-btn track-m-parts-from-stock"><span>📦</span><span>Со склада</span></button>' +
                '<button type="button" class="track-m-parts-btn track-m-parts-manual"><span>✏️</span><span>Вручную</span></button>';
            partsBody.insertBefore(toolbar, box);
        }

        if (toolbar.dataset.bound === '1') return;
        toolbar.dataset.bound = '1';

        toolbar.querySelector('.track-m-parts-from-stock')?.addEventListener('click', () => {
            const addBtn = box.querySelector('.track-order-line-add');
            if (addBtn) addBtn.click();
            const tbody = box.querySelector('.order-lines-tbody');
            const row = tbody && tbody.querySelector('tr:last-child');
            if (row && typeof global.openTrackStockPicker === 'function') {
                global.openTrackStockPicker(row);
            }
        });
        toolbar.querySelector('.track-m-parts-manual')?.addEventListener('click', () => {
            box.querySelector('.track-order-line-add')?.click();
        });
    }

    function applyStockFilter(mode) {
        const dialog = document.querySelector('.track-m-stock-sheet .track-stock-picker-dialog');
        if (!dialog) return;
        dialog.querySelectorAll('.track-stock-picker-item').forEach((item) => {
            const meta = item.querySelector('.tspi-meta');
            const text = meta ? meta.textContent : '';
            const qtyMatch = text.match(/ост\.\s*(\d+)/);
            const qty = qtyMatch ? parseInt(qtyMatch[1], 10) : 0;
            let show = true;
            if (mode === 'in') show = qty > 5;
            else if (mode === 'low') show = qty > 0 && qty <= 5;
            item.style.display = show ? '' : 'none';
        });
    }

    function enhanceStockPickerMobile() {
        const bd = document.getElementById('trackStockPickerBackdrop');
        if (!bd || bd.dataset.mobileEnhanced === '1') return;
        bd.dataset.mobileEnhanced = '1';
        bd.classList.add('track-m-stock-sheet');

        const dialog = bd.querySelector('.track-stock-picker-dialog');
        if (!dialog) return;

        const search = dialog.querySelector('#trackStockPickerSearch');
        if (search) search.placeholder = 'Поиск по названию, артикулу или SKU';

        let filters = dialog.querySelector('.track-m-stock-filters');
        if (!filters) {
            filters = document.createElement('div');
            filters.className = 'track-m-stock-filters';
            filters.innerHTML =
                '<button type="button" class="track-m-stock-filter is-active" data-filter="all">Все</button>' +
                '<button type="button" class="track-m-stock-filter" data-filter="in">✅ В наличии</button>' +
                '<button type="button" class="track-m-stock-filter" data-filter="low">⚠️ Мало</button>';
            if (search && search.parentElement) search.parentElement.insertBefore(filters, search.nextSibling);
            else dialog.insertBefore(filters, dialog.querySelector('.track-stock-picker-list'));
        }

        let activeFilter = 'all';
        filters.querySelectorAll('.track-m-stock-filter').forEach((btn) => {
            btn.addEventListener('click', () => {
                filters.querySelectorAll('.track-m-stock-filter').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                activeFilter = btn.getAttribute('data-filter') || 'all';
                applyStockFilter(activeFilter);
            });
        });

        const list = document.getElementById('trackStockPickerList');
        if (list && list.dataset.filterObs !== '1') {
            list.dataset.filterObs = '1';
            new MutationObserver(() => applyStockFilter(activeFilter)).observe(list, { childList: true });
        }
    }

    function updatePartsTitle(card) {
        const sec = card.querySelector('.track-section[data-section="parts"]');
        const title = sec && sec.querySelector('.track-section-title');
        if (!title) return;
        const n = card.querySelectorAll('.order-lines-tbody tr').length;
        title.textContent = n > 0 ? 'Позиции заказа (' + n + ')' : 'Позиции заказа';
    }

    function observePartsCount(card) {
        const tbody = card.querySelector('.order-lines-tbody');
        if (!tbody || tbody.dataset.partsObs === '1') return;
        tbody.dataset.partsObs = '1';
        new MutationObserver(() => updatePartsTitle(card)).observe(tbody, { childList: true });
    }

    function reorderMobileSections(card) {
        const body = card.querySelector('.track-order-body');
        if (!body) return;

        const hero = body.querySelector('.track-m-hero');
        const stepper = body.querySelector('.track-m-stepper');
        const cost = body.querySelector('.track-m-cost-card');
        const parts = body.querySelector('.track-section[data-section="parts"]');
        const info = body.querySelector('.track-section[data-section="info"]');
        const comments = body.querySelector('.track-section[data-section="comments"]');
        const pricing = body.querySelector('.track-section[data-section="pricing"]');
        const documents = body.querySelector('.track-section[data-section="documents"]');
        const history = body.querySelector('.track-section[data-section="history"]');
        if (history) history.remove();

        let stack = body.querySelector('.track-m-work-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'track-m-work-stack';
            body.appendChild(stack);
        }

        [hero, stepper, cost, parts, info, comments].forEach((el) => {
            if (el) stack.appendChild(el);
        });

        [pricing, documents].forEach((el) => {
            if (el) body.appendChild(el);
        });

        if (parts) {
            const title = parts.querySelector('.track-section-title');
            if (title) title.textContent = 'Позиции заказа';
        }
        if (info) {
            const title = info.querySelector('.track-section-title');
            if (title) title.textContent = 'Статус заказа';
        }
    }

    function buildHero(card, meta) {
        const body = card.querySelector('.track-order-body');
        if (!body) return;
        let hero = body.querySelector('.track-m-hero');
        if (!hero) {
            hero = document.createElement('div');
            hero.className = 'track-m-hero';
        }
        const stack = body.querySelector('.track-m-work-stack') || body;
        if (hero.parentElement !== stack) stack.insertBefore(hero, stack.firstChild);

        const orderId = card.querySelector('.track-order-id')?.textContent?.trim() || '—';
        const model = card.querySelector('.track-order-device')?.textContent?.trim() || '—';
        const problem = card.querySelector('.track-order-subtitle')?.textContent?.trim() || '—';
        const badgesRaw = card.querySelector('.track-order-badges')?.innerHTML || '';
        const badges = badgesRaw.replace(/\bbadge-lg\b/g, '').replace(/\s+/g, ' ').trim();
        const clientName = meta?.clientName || document.getElementById('clientTitle')?.textContent?.trim() || '—';
        const phone = meta?.phone || '';
        const portalDocId = meta?.portalDocId || '';
        const portalUrl = meta?.portalUrl || '';
        const portalOrderId = meta?.orderId || orderId;
        const clientToken = meta?.clientToken || '';
        const initials = global.TrackUi ? TrackUi.clientInitials(clientName) : clientName.slice(0, 2).toUpperCase();
        const hue = global.TrackUi ? TrackUi.clientAvatarHue(clientName) : 0;
        const emoji = deviceEmoji(model, meta?.deviceType || '');
        const serial = meta?.serial ? esc(meta.serial) : '';
        const phoneEsc = esc(phone || '—');
        const tel = phone ? ('tel:' + String(phone).replace(/[^\d+]/g, '')) : '#';
        const wa = phone && phone.replace(/\D/g, '').length >= 8
            ? ('https://wa.me/' + phone.replace(/\D/g, '').replace(/^\+/, ''))
            : '';
        const sms = phone ? ('sms:' + String(phone).replace(/[^\d+]/g, '')) : '';
        const portalLink = portalHref(portalUrl, clientToken);
        const portalBtnHtml = portalLink
            ? ('<a class="track-m-portal-btn" href="' + escAttr(portalLink) + '" target="_blank" rel="noopener noreferrer">' +
                '<span class="track-m-portal-btn-icon" aria-hidden="true">🔗</span>' +
                '<span>Открыть портал клиента</span></a>')
            : ('<button type="button" class="track-m-portal-btn portal-open" data-doc="' + escAttr(portalDocId) + '" data-oid="' + escAttr(portalOrderId) + '" data-url="' + escAttr(portalUrl) + '" data-token="' + escAttr(clientToken) + '">' +
                '<span class="track-m-portal-btn-icon" aria-hidden="true">🔗</span>' +
                '<span>Открыть портал клиента</span></button>');

        hero.innerHTML = `
            <div class="track-m-hero-top">
                <button type="button" class="track-m-back" id="trackMobileBack" aria-label="К списку">←</button>
                <div class="track-m-hero-order-id">${esc(orderId)}</div>
                <button type="button" class="track-m-more" id="trackMobileMore" aria-label="Ещё">⋯</button>
            </div>
            <div class="track-m-device-row">
                <div class="track-m-device-img" aria-hidden="true">${emoji}</div>
                <div class="track-m-device-main">
                    <div class="track-m-device-model">${esc(model)}</div>
                    <div class="track-m-device-problem">${esc(problem)}</div>
                    ${serial ? `<div class="track-m-device-meta">${serial}</div>` : ''}
                </div>
            </div>
            <div class="track-m-client-row">
                <div class="track-m-client-avatar track-avatar-h${hue}">${esc(initials)}</div>
                <div class="track-m-client-info">
                    <div class="track-m-client-name">${esc(clientName)}</div>
                    <div class="track-m-client-phone">${phoneEsc}</div>
                </div>
                <div class="track-m-client-badges">${badges}</div>
            </div>
            ${portalBtnHtml}
            <div class="track-m-quick">
                <a class="track-m-quick-btn${phone ? '' : ' is-disabled'}" href="${esc(tel)}" ${phone ? '' : ' tabindex="-1"'}><span>📞</span><span>Звонок</span></a>
                <a class="track-m-quick-btn${wa ? '' : ' is-disabled'}" href="${esc(wa)}" target="_blank" rel="noopener noreferrer"><span>💬</span><span>WhatsApp</span></a>
                <a class="track-m-quick-btn${phone ? '' : ' is-disabled'}" href="${esc(sms)}" ${phone ? '' : ' tabindex="-1"'}><span>✉</span><span>SMS</span></a>
                <button type="button" class="track-m-quick-btn" id="trackMobileMore2"><span>⋯</span><span>Ещё</span></button>
            </div>
        `;

        hero.querySelector('#trackMobileBack')?.addEventListener('click', () => {
            document.querySelector('.track-sidebar')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        const openMore = () => {
            document.getElementById('trackClientCard')?.setAttribute('open', '');
            document.getElementById('trackClientCard')?.scrollIntoView({ behavior: 'smooth' });
        };
        hero.querySelector('#trackMobileMore')?.addEventListener('click', openMore);
        hero.querySelector('#trackMobileMore2')?.addEventListener('click', openMore);
    }

    function buildStepper(card) {
        const body = card.querySelector('.track-order-body');
        if (!body) return;
        let stepper = body.querySelector('.track-m-stepper');
        if (!stepper) {
            stepper = document.createElement('div');
            stepper.className = 'track-m-stepper';
        }
        const stack = body.querySelector('.track-m-work-stack') || body;
        const hero = stack.querySelector('.track-m-hero');
        if (hero && hero.nextSibling !== stepper) stack.insertBefore(stepper, hero.nextSibling);
        else if (!stepper.parentElement) stack.appendChild(stepper);

        const pubSel = card.querySelector('.track-pub-status');
        const pub = pubSel ? pubSel.value : '';
        const partsBadge = card.querySelector('.track-field-cell--status-parts .badge')?.textContent || '';
        const hasReport = !!card.querySelector('.track-doc-row[data-doc-type="report"], .track-doc-card--report');
        const idx = pubStatusStep(pub, partsBadge, hasReport);
        stepper.innerHTML = '<div class="track-m-stepper-track">' + STEPS.map((step, i) => {
            const cls = i < idx ? ' is-done' : (i === idx ? ' is-current' : '');
            return `<div class="track-m-step${cls}"><div class="track-m-step-dot">${i < idx ? '✓' : (i + 1)}</div><div class="track-m-step-label">${esc(step.label)}</div></div>`;
        }).join('') + '</div>';
    }

    function bindNav(root) {
        const nav = document.getElementById('trackMobileNav');
        if (!nav || nav.dataset.bound === '1') return;
        nav.dataset.bound = '1';
        nav.querySelectorAll('.track-m-nav-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const tab = btn.getAttribute('data-tab') || 'details';
                saveTab(tab);
                applyTab(tab, root);
                const card = root.querySelector('.order-card');
                const scrollTargets = {
                    details: '.track-m-work-stack',
                    parts: '.track-section[data-section="parts"]',
                    finance: '.track-m-cost-card',
                    documents: '.track-section[data-section="documents"]',
                };
                const sel = scrollTargets[tab];
                if (card && sel) {
                    setTimeout(() => {
                        const el = card.querySelector(sel) || document.querySelector(sel);
                        el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 60);
                } else {
                    document.querySelector('.track-workspace-main')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    function restorePricingToSection(card) {
        const pricingSec = card.querySelector('.track-section[data-section="pricing"]');
        const pricingBody = pricingSec && pricingSec.querySelector('.track-section-body');
        const costBody = card.querySelector('.track-m-cost-card .track-m-cost-body');
        if (!pricingBody || !costBody) return;
        const workWrap = costBody.querySelector('.track-work-wrap');
        const summary = costBody.querySelector('.track-pricing-summary');
        if (workWrap) pricingBody.appendChild(workWrap);
        if (summary) pricingBody.insertBefore(summary, pricingBody.firstChild);
    }

    function teardownMobile(root) {
        document.body.classList.remove('track-mobile-app');
        const nav = document.getElementById('trackMobileNav');
        if (nav) nav.hidden = true;
        const card = root?.querySelector('.order-card');
        if (card) {
            restorePricingToSection(card);
            card.querySelector('.track-m-parts-toolbar')?.remove();
        }
        root?.querySelectorAll('.track-section').forEach((sec) => {
            sec.classList.remove('track-m-tab-hidden');
        });
        root?.querySelectorAll('.track-m-hero, .track-m-stepper, .track-m-cost-card, .track-m-work-stack').forEach((el) => el.remove());
        const bd = document.getElementById('trackStockPickerBackdrop');
        if (bd) bd.classList.remove('track-m-stock-sheet');
    }

    function refresh(root, meta) {
        root = root || document.getElementById('ordersTree');
        if (!root) return;
        if (!isMobile()) {
            teardownMobile(root);
            return;
        }
        document.body.classList.add('track-mobile-app');
        const card = root.querySelector('.order-card');
        const nav = document.getElementById('trackMobileNav');
        if (!card || !nav) {
            if (nav) nav.hidden = true;
            return;
        }
        nav.hidden = false;
        bindNav(root);
        buildHero(card, meta || {});
        buildStepper(card);
        buildMobileCostCard(card);
        reorderMobileSections(card);
        placePrepayAfterParts(card);
        buildMobilePartsToolbar(card);
        enhanceStockPickerMobile();
        updatePartsTitle(card);
        observePartsCount(card);
        const header = card.querySelector('.track-order-header');
        if (header) header.classList.add('track-m-header-legacy');
        card.querySelectorAll('.track-pub-status').forEach((sel) => {
            if (sel.dataset.mobileStepBound === '1') return;
            sel.dataset.mobileStepBound = '1';
            sel.addEventListener('change', () => buildStepper(card));
        });
        applyTab(getSavedTab(), root);
        if (typeof global.bindPortalButtons === 'function') {
            global.bindPortalButtons(card);
        }
    }

    function initMediaListener() {
        if (global.__trackMobileMqBound) return;
        global.__trackMobileMqBound = true;
        const mq = global.matchMedia('(max-width: 768px)');
        const fn = () => {
            const tree = document.getElementById('ordersTree');
            if (isMobile()) refresh(tree, global.__trackMobileMeta || {});
            else teardownMobile(tree);
        };
        if (mq.addEventListener) mq.addEventListener('change', fn);
        else mq.addListener(fn);
    }

    global.TrackMobileApp = {
        refresh,
        teardown: teardownMobile,
        initMediaListener,
        isMobile,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMediaListener);
    } else {
        initMediaListener();
    }
})(typeof window !== 'undefined' ? window : globalThis);
