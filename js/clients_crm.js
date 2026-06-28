/**
 * FixariVan — CRM-модуль «Клиенты» (UI-only, данные из api/clients.php).
 * CRM-метаданные хранятся в поле notes (JSON-маркер), схема БД не меняется.
 */
(function (global) {
    'use strict';

    const CRM_MARKER = '<!--fv-crm:';
    const CRM_MARKER_END = '-->';

    const TIERS = [
        { id: 'vip', stars: 5, label: 'VIP' },
        { id: 'regular', stars: 4, label: 'Постоянный' },
        { id: 'good', stars: 3, label: 'Хороший' },
        { id: 'new', stars: 2, label: 'Новый' },
        { id: 'problem', stars: 1, label: 'Проблемный' },
    ];

    const TAG_DEFS = [
        { id: 'pays_on_time', label: 'Всегда оплачивает вовремя' },
        { id: 'prefers_whatsapp', label: 'Предпочитает WhatsApp' },
        { id: 'prefers_call', label: 'Предпочитает звонок' },
        { id: 'evening_visits', label: 'Любит вечерние визиты' },
        { id: 'recommends', label: 'Часто рекомендует компанию' },
        { id: 'warranty_claims', label: 'Есть гарантийные обращения' },
        { id: 'call_ahead', label: 'Просит заранее звонить' },
        { id: 'lang_ru', label: 'Предпочитает русский' },
        { id: 'lang_fi', label: 'Предпочитает финский' },
        { id: 'lang_en', label: 'Предпочитает английский' },
    ];

    const OPP_CATALOG = [
        { id: 'battery', label: 'Замена аккумулятора', match: /iphone|ipad|macbook|телефон|аккум|battery/i },
        { id: 'ssd', label: 'SSD Upgrade', match: /ssd|диск|hdd|macbook|ноутбук|laptop|asus|lenovo|hp/i },
        { id: 'clean', label: 'Чистка системы', match: /ноутбук|laptop|macbook|asus|lenovo|перегрев|шум/i },
        { id: 'smart', label: 'Smart Home', match: /router|роутер|wifi|wi-fi|сеть|smart/i },
        { id: 'wifi', label: 'Настройка Wi-Fi', match: /wifi|wi-fi|router|роутер|сеть|интернет/i },
        { id: 'windows', label: 'Windows Upgrade', match: /windows|win10|win11|ноутбук|laptop|pc|комп/i },
        { id: 'router', label: 'Новый роутер', match: /router|роутер|wifi|wi-fi|сеть/i },
    ];

    const FUTURE_SLOTS = [
        { id: 'family', title: 'Семья клиента', hint: 'Скоро' },
        { id: 'companies', title: 'Компании', hint: 'Скоро' },
        { id: 'contacts', title: 'Несколько контактов', hint: 'Скоро' },
        { id: 'campaigns', title: 'Маркетинговые кампании', hint: 'Скоро' },
        { id: 'photos', title: 'Фотографии', hint: 'Скоро' },
        { id: 'addresses', title: 'Адреса', hint: 'Скоро' },
        { id: 'vehicles', title: 'Автомобили', hint: 'Скоро' },
        { id: 'ai', title: 'ИИ-подсказки', hint: 'Скоро' },
    ];

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function escAttr(s) {
        return esc(s).replace(/"/g, '&quot;');
    }

    function tierById(id) {
        return TIERS.find((t) => t.id === id) || TIERS[3];
    }

    function parseNotes(raw) {
        const text = String(raw || '');
        const idx = text.indexOf(CRM_MARKER);
        if (idx === -1) {
            const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
            return { freeText: text.trim(), meta: { v: 1, tier: 'new', tags: [], bullets: lines } };
        }
        const before = text.slice(0, idx).trim();
        const after = text.slice(idx + CRM_MARKER.length);
        const end = after.indexOf(CRM_MARKER_END);
        const jsonStr = end >= 0 ? after.slice(0, end) : after;
        let meta = { v: 1, tier: 'new', tags: [], bullets: [] };
        try {
            const parsed = JSON.parse(jsonStr);
            if (parsed && typeof parsed === 'object') meta = { ...meta, ...parsed };
        } catch (_) { /* keep default */ }
        if (before && !meta.bullets.length) {
            meta.bullets = before.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
        } else if (before) {
            meta.bullets = [...before.split(/\r?\n/).map((l) => l.trim()).filter(Boolean), ...(meta.bullets || [])];
        }
        if (!Array.isArray(meta.tags)) meta.tags = [];
        if (!Array.isArray(meta.bullets)) meta.bullets = [];
        return { freeText: before, meta };
    }

    function serializeNotes(meta, freeText) {
        const bullets = Array.isArray(meta.bullets) ? meta.bullets.filter(Boolean) : [];
        const payload = {
            v: 1,
            tier: meta.tier || 'new',
            tags: Array.isArray(meta.tags) ? meta.tags : [],
            bullets,
        };
        const head = freeText ? freeText.trim() + '\n\n' : (bullets.length ? bullets.join('\n') + '\n\n' : '');
        return head + CRM_MARKER + JSON.stringify(payload) + CRM_MARKER_END;
    }

    function avatarColor(id) {
        const colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#06b6d4', '#3b82f6'];
        let h = 0;
        const s = String(id || 'x');
        for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
        return colors[h % colors.length];
    }

    function initials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    function parseDate(raw) {
        const s = String(raw || '').trim();
        if (!s) return null;
        const d = new Date(s);
        return Number.isNaN(d.getTime()) ? null : d;
    }

    function formatDateShort(raw) {
        const d = parseDate(raw);
        if (!d) return '—';
        return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function formatRelative(raw) {
        const d = parseDate(raw);
        if (!d) return '—';
        const diff = Math.floor((Date.now() - d.getTime()) / 86400000);
        if (diff <= 0) return 'Сегодня';
        if (diff === 1) return 'Вчера';
        if (diff < 7) return diff + ' дн. назад';
        if (diff < 30) return Math.floor(diff / 7) + ' нед. назад';
        if (diff < 365) return Math.floor(diff / 30) + ' мес. назад';
        return Math.floor(diff / 365) + ' г. назад';
    }

    function normalizePhone(v) {
        return String(v || '').replace(/[^\d+]/g, '');
    }

    function whatsappOk(phone) {
        const d = normalizePhone(phone).replace(/^\+/, '');
        return d.length >= 8;
    }

    function whatsappUrl(phone, text) {
        const d = normalizePhone(phone).replace(/^\+/, '');
        if (!d) return '#';
        const base = 'https://wa.me/' + d;
        return text ? base + '?text=' + encodeURIComponent(text) : base;
    }

    function telUrl(phone) {
        const p = normalizePhone(phone);
        return p ? 'tel:' + p : '#';
    }

    function mailUrl(email) {
        const e = String(email || '').trim();
        return e ? 'mailto:' + e : '#';
    }

    function smsUrl(phone) {
        const p = normalizePhone(phone);
        return p ? 'sms:' + p : '#';
    }

    function orderIsDone(o) {
        const s = String(o?.status || o?.order_status || '').toLowerCase();
        return ['completed', 'cancelled', 'signed', 'done'].includes(s);
    }

    function invoicePaid(inv) {
        const st = String(inv?.status || '').toLowerCase();
        return st.includes('paid') || st.includes('оплач');
    }

    function estimateFromLines(json) {
        if (!json) return 0;
        try {
            const rows = typeof json === 'string' ? JSON.parse(json) : json;
            if (!Array.isArray(rows)) return 0;
            return rows.reduce((sum, r) => {
                const q = Number(r?.qty ?? r?.quantity ?? 1) || 1;
                const sale = Number(r?.sale ?? r?.price ?? r?.sell ?? 0) || 0;
                return sum + q * sale;
            }, 0);
        } catch (_) {
            return 0;
        }
    }

    function profitFromLines(json) {
        if (!json) return 0;
        try {
            const rows = typeof json === 'string' ? JSON.parse(json) : json;
            if (!Array.isArray(rows)) return 0;
            return rows.reduce((sum, r) => {
                const q = Number(r?.qty ?? r?.quantity ?? 1) || 1;
                const sale = Number(r?.sale ?? r?.price ?? r?.sell ?? 0) || 0;
                const buy = Number(r?.purchase ?? r?.buy ?? r?.cost ?? 0) || 0;
                return sum + q * (sale - buy);
            }, 0);
        } catch (_) {
            return 0;
        }
    }

    function deviceIcon(model, type) {
        const s = (String(model || '') + ' ' + String(type || '')).toLowerCase();
        if (/iphone|ipad|samsung|pixel|телефон|phone|mobile/.test(s)) return '📱';
        if (/macbook|laptop|asus|lenovo|hp|ноутбук|fx506/.test(s)) return '💻';
        if (/printer|laserjet|hp|принтер|печат/.test(s)) return '🖨';
        if (/imac|монитор|desktop|pc|комп/.test(s)) return '🖥';
        return '📦';
    }

    function computeAnalytics(detail) {
        const orders = Array.isArray(detail?.history?.orders) ? detail.history.orders : [];
        const receipts = Array.isArray(detail?.history?.receipts) ? detail.history.receipts : [];
        const invoices = Array.isArray(detail?.history?.invoices) ? detail.history.invoices : [];
        const groups = Array.isArray(detail?.orders_with_docs) ? detail.orders_with_docs : [];
        const active = Array.isArray(detail?.active_orders) ? detail.active_orders : [];

        let totalRevenue = 0;
        let debt = 0;
        let paidCount = 0;
        let onTimeCount = 0;
        let profitSum = 0;
        const seenInv = new Set();

        invoices.forEach((inv) => {
            const id = String(inv.document_id || '');
            if (id && seenInv.has(id)) return;
            if (id) seenInv.add(id);
            const amt = Number(inv.total_amount) || 0;
            if (invoicePaid(inv)) {
                totalRevenue += amt;
                paidCount++;
                const due = parseDate(inv.due_date);
                const paid = parseDate(inv.payment_date || inv.updated_at);
                if (due && paid && paid <= due) onTimeCount++;
            } else if (amt > 0) {
                debt += amt;
            }
        });

        receipts.forEach((r) => {
            const st = String(r.status || '').toLowerCase();
            if (st.includes('paid') || st.includes('оплач') || Number(r.total_amount) > 0) {
                totalRevenue += Number(r.total_amount) || 0;
            }
        });

        const completed = orders.filter((o) => orderIsDone(o) && !String(o.status || '').toLowerCase().includes('cancel'));
        const completedCount = completed.length;
        const avgCheck = completedCount > 0 ? totalRevenue / completedCount : (orders.length ? totalRevenue / orders.length : 0);

        orders.forEach((o) => {
            profitSum += profitFromLines(o.order_lines_json);
        });

        let lastOrderDate = '';
        orders.forEach((o) => {
            const u = o.updated_at || o.date_updated || o.date_created || '';
            if (!lastOrderDate || String(u) > String(lastOrderDate)) lastOrderDate = u;
        });

        let firstOrderDate = '';
        orders.slice().reverse().forEach((o) => {
            const c = o.date_created || o.date_updated || '';
            if (c && (!firstOrderDate || String(c) < String(firstOrderDate))) firstOrderDate = c;
        });

        const deviceMap = new Map();
        orders.forEach((o) => {
            const model = String(o.device_model || '').trim();
            if (!model) return;
            const key = model.toLowerCase();
            const prev = deviceMap.get(key) || {
                model,
                type: o.device_type || '',
                serial: o.device_serial || '',
                repairs: [],
                lastDate: '',
                lastStatus: '',
            };
            prev.repairs.push(o);
            const u = o.updated_at || o.date_updated || o.date_created || '';
            if (!prev.lastDate || String(u) > String(prev.lastDate)) {
                prev.lastDate = u;
                prev.lastStatus = o.public_status || o.status || '';
            }
            if (o.device_serial) prev.serial = o.device_serial;
            deviceMap.set(key, prev);
        });

        const languages = {};
        orders.forEach((o) => {
            const l = String(o.language || '').toLowerCase();
            if (l === 'ru' || l === 'fi' || l === 'en') languages[l] = (languages[l] || 0) + 1;
        });
        let preferredLang = '';
        let maxL = 0;
        Object.keys(languages).forEach((k) => {
            if (languages[k] > maxL) { maxL = languages[k]; preferredLang = k; }
        });

        const avgProfit = completedCount > 0 ? profitSum / completedCount : 0;
        const onTimePct = paidCount > 0 ? Math.round((onTimeCount / paidCount) * 100) : null;

        return {
            ordersTotal: orders.length,
            completedCount,
            activeCount: active.length,
            totalRevenue,
            avgCheck,
            avgProfit,
            profitSum,
            debt,
            onTimePct,
            ltv: totalRevenue,
            lastOrderDate,
            firstOrderDate,
            devices: Array.from(deviceMap.values()).sort((a, b) => String(b.lastDate).localeCompare(String(a.lastDate))),
            preferredLang,
            groups,
            orders,
            receipts,
            invoices,
        };
    }

    function buildTimeline(detail, client) {
        const events = [];
        const created = client?.created_at;
        if (created) {
            events.push({ ts: created, icon: '👤', title: 'Клиент создан', sub: formatDateShort(created) });
        }
        const groups = Array.isArray(detail?.orders_with_docs) ? detail.orders_with_docs : [];
        groups.forEach((g) => {
            const o = g.order || {};
            const ts = o.updated_at || o.date_updated || o.date_created || '';
            const oid = o.order_id || o.document_id || '—';
            events.push({
                ts,
                icon: '📋',
                title: 'Заказ ' + oid,
                sub: [o.device_model, o.public_status || o.status].filter(Boolean).join(' • '),
            });
            (g.receipts || []).forEach((r) => {
                events.push({
                    ts: r.updated_at || r.date_updated || '',
                    icon: '🧾',
                    title: 'Квитанция ' + (r.receipt_number || r.document_id || ''),
                    sub: r.total_amount != null ? Number(r.total_amount).toFixed(2) + ' €' : '',
                });
            });
            (g.invoices || []).forEach((inv) => {
                events.push({
                    ts: inv.updated_at || inv.payment_date || '',
                    icon: '📄',
                    title: 'Счёт ' + (inv.invoice_id || inv.document_id || ''),
                    sub: (inv.status || '') + (inv.total_amount != null ? ' • ' + Number(inv.total_amount).toFixed(2) + ' €' : ''),
                });
            });
            (g.reports || []).forEach((rep) => {
                events.push({
                    ts: rep.created_at || '',
                    icon: '🔍',
                    title: 'Диагностика ' + (rep.report_id || ''),
                    sub: rep.model || rep.device_type || '',
                });
            });
        });
        return events.filter((e) => e.ts).sort((a, b) => String(b.ts).localeCompare(String(a.ts))).slice(0, 40);
    }

    function buildOpportunities(analytics, meta) {
        const done = new Set();
        const textBlob = analytics.orders.map((o) =>
            [o.device_model, o.problem_description, o.device_type].join(' ')
        ).join(' ');
        return OPP_CATALOG.map((item) => {
            const suggested = item.match.test(textBlob);
            const checked = Array.isArray(meta?.opp) && meta.opp.includes(item.id);
            return { ...item, suggested, checked };
        }).filter((item) => item.suggested || item.checked);
    }

    function buildInsights(client, analytics, meta) {
        const out = [];
        const last = parseDate(analytics.lastOrderDate || client?.updated_at);
        if (last) {
            const days = Math.floor((Date.now() - last.getTime()) / 86400000);
            if (days > 60) out.push('Клиент не обращался ' + Math.floor(days / 30) + ' мес.');
            else if (days > 14) out.push('Клиент не обращался ' + days + ' дн.');
            else out.push('Последний контакт: ' + formatRelative(analytics.lastOrderDate));
        }
        if (analytics.devices.length) out.push('Есть ' + analytics.devices.length + ' ' + (analytics.devices.length === 1 ? 'устройство' : 'устройства') + '.');
        if (analytics.avgCheck > 0) out.push('Средний бюджет ремонта ≈ ' + Math.round(analytics.avgCheck) + ' €.');
        if (analytics.activeCount > 0) out.push('Сейчас ' + analytics.activeCount + ' активн. заказ(ов).');
        if (analytics.debt > 0) out.push('Открытый долг: ' + analytics.debt.toFixed(2) + ' €.');
        if (meta.tags && meta.tags.includes('recommends')) out.push('Отмечен как рекомендующий компанию.');
        if (analytics.onTimePct === 100 && analytics.ordersTotal > 0) out.push('Все оплаченные счета — в срок.');
        return out.slice(0, 6);
    }

    function loyaltyLevel(analytics) {
        const spend = analytics.ltv;
        if (spend >= 2000 || analytics.ordersTotal >= 10) return { label: 'Gold', pct: Math.min(95, 50 + analytics.ordersTotal * 4), next: 'Platinum', need: Math.max(0, 3000 - spend) };
        if (spend >= 800 || analytics.ordersTotal >= 5) return { label: 'Silver', pct: Math.min(80, 30 + analytics.ordersTotal * 5), next: 'Gold', need: Math.max(0, 2000 - spend) };
        if (analytics.ordersTotal >= 2) return { label: 'Bronze', pct: Math.min(60, 15 + analytics.ordersTotal * 8), next: 'Silver', need: Math.max(0, 800 - spend) };
        return { label: 'Starter', pct: Math.min(40, analytics.ordersTotal * 15), next: 'Bronze', need: Math.max(0, 200 - spend) };
    }

    function langLabel(code) {
        return ({ ru: 'Русский', fi: 'Suomi', en: 'English' })[code] || code || '—';
    }

    function listStatus(c, meta) {
        const active = Number(c.active_orders_count ?? 0) > 0;
        if (active) return { label: 'В работе', cls: 'is-active' };
        const tier = tierById(meta?.tier);
        if (tier.id === 'vip') return { label: 'VIP', cls: 'is-vip' };
        if (Number(c.orders_count ?? 0) > 0) return { label: 'Завершён', cls: 'is-done' };
        return { label: 'Новый', cls: 'is-new' };
    }

    global.FixariVanClientsCrm = {
        CRM_MARKER,
        TIERS,
        TAG_DEFS,
        FUTURE_SLOTS,
        esc,
        escAttr,
        tierById,
        parseNotes,
        serializeNotes,
        avatarColor,
        initials,
        formatDateShort,
        formatRelative,
        whatsappOk,
        whatsappUrl,
        telUrl,
        mailUrl,
        smsUrl,
        orderIsDone,
        computeAnalytics,
        buildTimeline,
        buildOpportunities,
        buildInsights,
        loyaltyLevel,
        langLabel,
        listStatus,
        deviceIcon,
        estimateFromLines,
    };
})(typeof window !== 'undefined' ? window : globalThis);
