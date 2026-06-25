/**
 * FixariVan Track — UI helpers (sections, help, pricing summary, timeline).
 * Does not change business logic or API calls.
 */
(function (global) {
    'use strict';

    const SECTION_KEY = 'fixarivan_track_sections_v1';
    const SECTION_DEFAULTS = {
        info: true,
        communication: true,
        pricing: true,
        parts: true,
        documents: false,
        internal: false,
    };

    function loadSectionPrefs() {
        try {
            const raw = localStorage.getItem(SECTION_KEY);
            if (!raw) return { ...SECTION_DEFAULTS };
            const p = JSON.parse(raw);
            return (p && typeof p === 'object') ? { ...SECTION_DEFAULTS, ...p } : { ...SECTION_DEFAULTS };
        } catch (_) {
            return { ...SECTION_DEFAULTS };
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
        if (type === 'order') return 'Заказ создан';
        if (type === 'receipt') return 'Квитанция';
        if (type === 'invoice') {
            const st = String(d.status || '').toLowerCase();
            if (st === 'issued' || st === 'paid') return 'Счёт отправлен';
            return 'Счёт';
        }
        if (type === 'report') return 'Отчёт';
        return T.typeDoc || 'Документ';
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

    function updatePricingSummary(root, orderDocId) {
        if (!orderDocId) return;
        const box = root.querySelector('.order-lines-box[data-order-doc-id="' + orderDocId.replace(/"/g, '\\"') + '"]');
        if (!box) return;
        const card = box.closest('.order-card');
        if (!card) return;
        const p = calcPricingFromBox(box);
        card.querySelectorAll(`[data-pricing-field]`).forEach((el) => {
            const field = el.getAttribute('data-pricing-field');
            if (field === 'labour') el.textContent = formatEuro(p.labour);
            else if (field === 'parts') el.textContent = formatEuro(p.partsSale);
            else if (field === 'discount') el.textContent = formatEuro(p.discount);
            else if (field === 'total') el.textContent = formatEuro(p.total);
            else if (field === 'profit') el.textContent = formatEuro(p.profit);
            else if (field === 'margin') el.textContent = p.margin.toFixed(1).replace('.', ',') + ' %';
        });
        const summaryTotal = card.querySelector('[data-summary-total]');
        if (summaryTotal) summaryTotal.textContent = formatEuro(p.total);
    }

    function bindPricingLive(root) {
        root.querySelectorAll('.track-public-estimated-cost').forEach((inp) => {
            if (inp.dataset.pricingBound === '1') return;
            inp.dataset.pricingBound = '1';
            const docId = inp.getAttribute('data-doc') || '';
            const handler = () => updatePricingSummary(root, docId);
            inp.addEventListener('input', handler);
            inp.addEventListener('change', handler);
        });
        root.querySelectorAll('.order-lines-tbody').forEach((tbody) => {
            if (tbody.dataset.pricingBound === '1') return;
            tbody.dataset.pricingBound = '1';
            const docId = tbody.closest('.order-lines-box')?.getAttribute('data-order-doc-id') || '';
            tbody.addEventListener('input', () => updatePricingSummary(root, docId));
        });
    }

    function init(root) {
        if (!root) return;
        initHelp(root);
        initSections(root);
        bindPricingLive(root);
        root.querySelectorAll('.order-lines-box[data-order-doc-id]').forEach((box) => {
            updatePricingSummary(root, box.getAttribute('data-order-doc-id') || '');
        });
    }

    global.TrackUi = {
        init,
        updatePricingSummary,
        clientInitials,
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
