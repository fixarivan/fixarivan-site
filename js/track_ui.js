/**
 * FixariVan Track — UI helpers (sections, help, pricing summary, timeline).
 * Does not change business logic or API calls.
 */
(function (global) {
    'use strict';

    const SECTION_KEY = 'fixarivan_track_sections_v1';
    const SECTION_DEFAULTS_DESKTOP = {
        info: true,
        communication: true,
        pricing: true,
        parts: true,
        documents: false,
        internal: false,
    };

    const SECTION_DEFAULTS_MOBILE = {
        info: true,
        communication: false,
        pricing: false,
        parts: true,
        documents: false,
        internal: false,
    };

    function isTrackMobileUi() {
        return typeof window !== 'undefined' && window.matchMedia('(max-width: 768px)').matches;
    }

    function sectionDefaultsForViewport() {
        return isTrackMobileUi() ? { ...SECTION_DEFAULTS_MOBILE } : { ...SECTION_DEFAULTS_DESKTOP };
    }

    function loadSectionPrefs() {
        const defaults = sectionDefaultsForViewport();
        try {
            const raw = localStorage.getItem(SECTION_KEY);
            if (!raw) return { ...defaults };
            const p = JSON.parse(raw);
            return (p && typeof p === 'object') ? { ...defaults, ...p } : { ...defaults };
        } catch (_) {
            return { ...defaults };
        }
    }

    function saveSectionPrefs(prefs) {
        try { localStorage.setItem(SECTION_KEY, JSON.stringify(prefs)); } catch (_) {}
    }

    function clientInitials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    /** Stable hue 0–7 from client key/name (UI only). */
    function clientAvatarHue(seed) {
        let h = 5381;
        const s = String(seed || '?');
        for (let i = 0; i < s.length; i++) h = ((h << 5) + h) ^ s.charCodeAt(i);
        return Math.abs(h) % 8;
    }

    function formatDateShort(raw) {
        const s = String(raw || '').trim();
        if (!s) return '—';
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return `${m[3]}.${m[2]}.${m[1]}`;
        if (/^\d{1,2}\.\d{1,2}\.\d{4}/.test(s)) return s.split(/\s/)[0];
        return s.split(/\s/)[0] || '—';
    }

    function parseDecimal(raw) {
        let s = String(raw == null ? '' : raw).trim().replace(/\u00a0/g, '').replace(/\s/g, '').replace(',', '.');
        if (s === '' || s === '-' || s === '—') return 0;
        const n = parseFloat(s);
        return Number.isFinite(n) ? n : 0;
    }

    function formatEuro(n) {
        const v = Number(n) || 0;
        return v.toFixed(2).replace('.', ',') + ' €';
    }

    function lastActivityFromGroup(g) {
        let best = '';
        let bestTs = 0;
        const orders = Array.isArray(g.orders) ? g.orders : [];
        for (const o of orders) {
            const docs = Array.isArray(o.documents) ? o.documents : [];
            for (const d of docs) {
                const raw = String(d.date_created || '').trim();
                const ts = Date.parse(raw) || 0;
                if (ts >= bestTs) { bestTs = ts; best = raw; }
            }
        }
        return best;
    }

    function activeOrderForClient(g) {
        const orders = Array.isArray(g.orders) ? g.orders : [];
        return orders.find((o) => {
            const p = String(o.order_status || o.public_status || '').toLowerCase();
            return p !== 'delivered' && p !== 'cancelled';
        }) || null;
    }

    function orderCreatedDate(o) {
        const orderDoc = (o.documents || []).find((d) => d.type === 'order');
        return orderDoc ? String(orderDoc.date_created || '') : '';
    }

    function orderUpdatedDate(o) {
        let best = '';
        let bestTs = 0;
        for (const d of (o.documents || [])) {
            const raw = String(d.date_created || '').trim();
            const ts = Date.parse(raw) || 0;
            if (ts >= bestTs) { bestTs = ts; best = raw; }
        }
        return best;
    }

    function timelineEventLabel(d, T) {
        const type = String(d.type || '').toLowerCase();
        const st = String(d.status || '').toLowerCase();
        if (type === 'order') return 'Заказ создан';
        if (type === 'receipt') return st === 'paid' ? 'Оплата получена' : 'Квитанция';
        if (type === 'invoice') {
            if (st === 'paid') return 'Счёт оплачен';
            if (st === 'issued') return 'Счёт отправлен';
            return 'Счёт создан';
        }
        if (type === 'report') return 'Отчёт создан';
        return T.typeDoc || 'Событие';
    }

    function buildTimelineItems(docs, T) {
        const sorted = [...(docs || [])].sort((a, b) => {
            const ta = Date.parse(String(a.date_created || '')) || 0;
            const tb = Date.parse(String(b.date_created || '')) || 0;
            return tb - ta;
        });
        return sorted;
    }

    function initHelp(root) {
        root.querySelectorAll('.track-help').forEach((wrap) => {
            const btn = wrap.querySelector('.track-help-toggle');
            const panel = wrap.querySelector('.track-help-panel');
            if (!btn || !panel || btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => {
                const open = panel.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    }

    function initSections(root) {
        const prefs = loadSectionPrefs();
        root.querySelectorAll('.track-section[data-section]').forEach((sec) => {
            const key = sec.getAttribute('data-section') || '';
            const btn = sec.querySelector('.track-section-toggle');
            const collapsed = prefs[key] === false;
            sec.classList.toggle('is-collapsed', collapsed);
            if (btn) btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            if (!btn || btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => {
                const nowCollapsed = sec.classList.toggle('is-collapsed');
                btn.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
                const p = loadSectionPrefs();
                p[key] = !nowCollapsed;
                saveSectionPrefs(p);
            });
        });
    }

    function calcPricingFromLines(labourRaw, lines) {
        const labour = parseDecimal(labourRaw);
        let partsSale = 0;
        let partsCost = 0;
        (lines || []).forEach((ln) => {
            const name = String(ln.name || ln.title || '').trim();
            if (!name) return;
            const qty = Math.max(parseDecimal(ln.qty != null ? ln.qty : ln.quantity), 0) || 1;
            partsSale += parseDecimal(ln.sale != null ? ln.sale : (ln.sale_price != null ? ln.sale_price : ln.price)) * qty;
            partsCost += parseDecimal(ln.purchase != null ? ln.purchase : (ln.purchase_price != null ? ln.purchase_price : ln.cost)) * qty;
        });
        const discount = 0;
        const total = labour + partsSale - discount;
        const profit = labour + (partsSale - partsCost);
        const margin = total > 0 ? (profit / total) * 100 : 0;
        return { labour, partsSale, discount, total, profit, margin, partsCost };
    }

    const orderPricingCache = new Map();

    function cacheOrderPricing(orderDocId, p) {
        if (orderDocId) orderPricingCache.set(String(orderDocId), p);
    }

    function setOrderPricingFromDoc(orderDocId, doc, parseLinesFn) {
        const lines = typeof parseLinesFn === 'function' ? parseLinesFn(doc || {}) : [];
        const p = calcPricingFromLines(doc && doc.public_estimated_cost, lines);
        cacheOrderPricing(orderDocId, p);
        return p;
    }

    function updateClientRailFinance(orderDocIds) {
        const merged = { labour: 0, partsSale: 0, discount: 0, total: 0, profit: 0, margin: 0 };
        (orderDocIds || []).forEach((id) => {
            const p = orderPricingCache.get(String(id));
            if (!p) return;
            merged.labour += p.labour;
            merged.partsSale += p.partsSale;
            merged.discount += p.discount;
            merged.profit += p.profit;
        });
        merged.total = merged.labour + merged.partsSale - merged.discount;
        merged.margin = merged.total > 0 ? (merged.profit / merged.total) * 100 : 0;
        applyPricingToContainer(document.querySelector('.track-rail-finance[data-client-scope="all"]'), merged);
    }

    function clearPricingCache() {
        orderPricingCache.clear();
    }

    function calcPricingFromBox(box) {
        const workInp = box.closest('.order-card')?.querySelector('.track-public-estimated-cost');
        const labour = workInp ? parseDecimal(workInp.value) : 0;
        let partsSale = 0;
        let partsCost = 0;
        const tbody = box.querySelector('.order-lines-tbody');
        if (tbody) {
            tbody.querySelectorAll('tr').forEach((tr) => {
                const name = String(tr.querySelector('.ol-name')?.value || '').trim();
                if (!name) return;
                const qty = Math.max(parseDecimal(tr.querySelector('.ol-qty')?.value), 0) || 1;
                const sale = parseDecimal(tr.querySelector('.ol-sale')?.value);
                const purchase = parseDecimal(tr.querySelector('.ol-purchase')?.value);
                partsSale += sale * qty;
                partsCost += purchase * qty;
            });
        }
        const discount = 0;
        const total = labour + partsSale - discount;
        const profit = labour + (partsSale - partsCost);
        const margin = total > 0 ? (profit / total) * 100 : 0;
        return { labour, partsSale, discount, total, profit, margin, partsCost };
    }

    function updatePricingSummary(root, orderDocId, clientDocIds) {
        if (!orderDocId) return;
        const scope = root || document;
        const q = orderDocId.replace(/"/g, '\\"');
        const box = scope.querySelector('.order-lines-box[data-order-doc-id="' + q + '"]')
            || document.querySelector('.order-lines-box[data-order-doc-id="' + q + '"]');
        let p = { labour: 0, partsSale: 0, discount: 0, total: 0, profit: 0, margin: 0 };
        if (box) {
            p = calcPricingFromBox(box);
            applyPricingToContainer(box.closest('.order-card'), p);
        } else {
            const workInp = scope.querySelector('.track-public-estimated-cost[data-doc="' + q + '"]')
                || document.querySelector('.track-public-estimated-cost[data-doc="' + q + '"]');
            if (workInp) {
                p.labour = parseDecimal(workInp.value);
                p.total = p.labour;
                p.profit = p.labour;
                p.margin = p.total > 0 ? 100 : 0;
            } else if (orderPricingCache.has(String(orderDocId))) {
                p = { ...orderPricingCache.get(String(orderDocId)) };
            }
        }
        cacheOrderPricing(orderDocId, p);
        if (clientDocIds && clientDocIds.length) {
            updateClientRailFinance(clientDocIds);
        }
    }

    function applyPricingToContainer(container, p) {
        if (!container) return;
        container.querySelectorAll('[data-pricing-field]').forEach((el) => {
            const field = el.getAttribute('data-pricing-field');
            if (field === 'labour') el.textContent = formatEuro(p.labour);
            else if (field === 'parts') el.textContent = formatEuro(p.partsSale);
            else if (field === 'discount') el.textContent = formatEuro(p.discount);
            else if (field === 'total') el.textContent = formatEuro(p.total);
            else if (field === 'profit') el.textContent = formatEuro(p.profit);
            else if (field === 'margin') el.textContent = p.margin.toFixed(1).replace('.', ',') + ' %';
        });
        const summaryTotal = container.querySelector('[data-summary-total]');
        if (summaryTotal) summaryTotal.textContent = formatEuro(p.total);
    }

    function bindPricingLive(root) {
        root.querySelectorAll('.track-public-estimated-cost').forEach((inp) => {
            if (inp.dataset.pricingBound === '1') return;
            inp.dataset.pricingBound = '1';
            const docId = inp.getAttribute('data-doc') || '';
            const handler = () => updatePricingSummary(root, docId, window.__trackClientDocIds || null);
            inp.addEventListener('input', handler);
            inp.addEventListener('change', handler);
        });
        root.querySelectorAll('.order-lines-tbody').forEach((tbody) => {
            if (tbody.dataset.pricingBound === '1') return;
            tbody.dataset.pricingBound = '1';
            const docId = tbody.closest('.order-lines-box')?.getAttribute('data-order-doc-id') || '';
            tbody.addEventListener('input', () => updatePricingSummary(root, docId, window.__trackClientDocIds || null));
        });
    }

    function init(root) {
        if (!root) return;
        initHelp(root);
        initSections(root);
        bindPricingLive(root);
        root.querySelectorAll('.order-lines-box[data-order-doc-id]').forEach((box) => {
            updatePricingSummary(root, box.getAttribute('data-order-doc-id') || '', window.__trackClientDocIds || null);
        });
    }

    global.TrackUi = {
        init,
        updatePricingSummary,
        updateClientRailFinance,
        setOrderPricingFromDoc,
        cacheOrderPricing,
        clearPricingCache,
        calcPricingFromLines,
        clientInitials,
        clientAvatarHue,
        formatDateShort,
        lastActivityFromGroup,
        activeOrderForClient,
        orderCreatedDate,
        orderUpdatedDate,
        buildTimelineItems,
        timelineEventLabel,
        formatEuro,
        calcPricingFromBox,
    };
})(typeof window !== 'undefined' ? window : globalThis);
