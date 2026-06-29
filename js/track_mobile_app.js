/**
 * FixariVan Track — мобильный app-like UI (≤768px). Десктоп и API не меняются.
 */
(function (global) {
    'use strict';

    const TAB_SECTIONS = {
        details: ['info', 'comments'],
        parts: ['parts'],
        finance: ['pricing'],
        documents: ['documents'],
        history: ['history'],
    };

    const STEPS = [
        { id: 'accepted', label: 'Принят' },
        { id: 'diagnosis', label: 'Диагностика' },
        { id: 'parts', label: 'Запчасти' },
        { id: 'repair', label: 'Ремонт' },
        { id: 'ready', label: 'Готов' },
        { id: 'delivered', label: 'Выдан' },
    ];

    const STORAGE_KEY = 'fixarivan_track_mobile_tab_v1';

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function isMobile() {
        return global.matchMedia && global.matchMedia('(max-width: 768px)').matches;
    }

    function getSavedTab() {
        try {
            const t = localStorage.getItem(STORAGE_KEY);
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
        const nav = document.getElementById('trackMobileNav');
        if (nav) nav.dataset.activeTab = tab;
    }

    function movePrepayForMobile(card, toFinance) {
        const prepay = card.querySelector('.track-prepay-wrap');
        const partsBody = card.querySelector('.track-section[data-section="parts"] .track-section-body');
        const financeBody = card.querySelector('.track-section[data-section="pricing"] .track-section-body');
        if (!prepay || !partsBody || !financeBody) return;
        if (toFinance) {
            if (prepay.parentElement !== financeBody) {
                financeBody.insertBefore(prepay, financeBody.firstChild);
            }
        } else if (prepay.dataset.homePart === '1' || prepay.parentElement === financeBody) {
            const anchor = partsBody.querySelector('.order-lines-box');
            if (anchor) partsBody.insertBefore(prepay, anchor);
            else partsBody.appendChild(prepay);
        }
        prepay.dataset.homePart = '1';
    }

    function buildHero(card, meta) {
        const body = card.querySelector('.track-order-body');
        if (!body) return;
        let hero = body.querySelector('.track-m-hero');
        if (!hero) {
            hero = document.createElement('div');
            hero.className = 'track-m-hero';
            body.insertBefore(hero, body.firstChild);
        }
        const orderId = card.querySelector('.track-order-id')?.textContent?.trim() || '—';
        const model = card.querySelector('.track-order-device')?.textContent?.trim() || '—';
        const problem = card.querySelector('.track-order-subtitle')?.textContent?.trim() || '—';
        const badges = card.querySelector('.track-order-badges')?.innerHTML || '';
        const clientName = meta?.clientName || document.getElementById('clientTitle')?.textContent?.trim() || '—';
        const phone = meta?.phone || '';
        const email = meta?.email || '';
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
            const hero = body.querySelector('.track-m-hero');
            if (hero && hero.nextSibling) body.insertBefore(stepper, hero.nextSibling);
            else body.insertBefore(stepper, body.children[1] || null);
        }
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
                const main = document.querySelector('.track-workspace-main');
                if (main) main.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
    }

    function teardownMobile(root) {
        document.body.classList.remove('track-mobile-app');
        const nav = document.getElementById('trackMobileNav');
        if (nav) nav.hidden = true;
        root?.querySelectorAll('.track-section').forEach((sec) => {
            sec.classList.remove('track-m-tab-hidden');
        });
        root?.querySelectorAll('.track-m-hero, .track-m-stepper').forEach((el) => el.remove());
        const card = root?.querySelector('.order-card');
        if (card) movePrepayForMobile(card, false);
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
        movePrepayForMobile(card, true);
        const header = card.querySelector('.track-order-header');
        if (header) header.classList.add('track-m-header-legacy');
        card.querySelectorAll('.track-pub-status').forEach((sel) => {
            if (sel.dataset.mobileStepBound === '1') return;
            sel.dataset.mobileStepBound = '1';
            sel.addEventListener('change', () => buildStepper(card));
        });
        applyTab(getSavedTab(), root);
    }

    function initMediaListener() {
        if (global.__trackMobileMqBound) return;
        global.__trackMobileMqBound = true;
        const mq = global.matchMedia('(max-width: 768px)');
        const fn = () => {
            const tree = document.getElementById('ordersTree');
            if (isMobile()) refresh(tree, window.__trackMobileMeta || {});
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
