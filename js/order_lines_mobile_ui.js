/**
 * Компактный мобильный UI «Позиции заказа» (Track + Новый заказ).
 * Таблица остаётся в DOM для сохранения; на экране — карточки и bottom sheet.
 */
(function (global) {
    'use strict';

    const MOBILE_MQ = '(max-width: 768px)';

    const MODES = {
        track: {
            box: '.order-lines-box',
            tbody: '.order-lines-tbody',
            addBtn: '.track-order-line-add',
            fields: {
                name: '.ol-name',
                qty: '.ol-qty',
                purchase: '.ol-purchase',
                sale: '.ol-sale',
                sku: '.ol-sku',
                fromStock: '.ol-from-stock',
                deduct: '.ol-deduct',
                hint: '.ol-sku-hint',
                del: '.track-order-line-del',
                pickStock: null,
            },
            openStockPicker(tr) {
                if (typeof global.openTrackStockPicker === 'function') {
                    global.openTrackStockPicker(tr);
                }
            },
            refreshHint(tr) {
                tr.querySelector('.ol-sku')?.dispatchEvent(new Event('blur'));
            },
            bindRow() {},
        },
        orderNew: {
            box: '[data-block-name="lines"]',
            tbody: '#linesBody',
            addBtn: '#addLine',
            fields: {
                name: '.line-name',
                qty: '.line-qty',
                purchase: '.line-purchase',
                sale: '.line-sale',
                sku: null,
                invId: '.line-inv-id',
                fromStock: '.line-from-stock',
                deduct: '.line-deduct-now',
                hint: '.line-stock-hint',
                del: null,
                pickStock: '.btn-pick-stock',
            },
            openStockPicker(tr) {
                tr.querySelector('.btn-pick-stock')?.click();
            },
            refreshHint(tr) {
                tr.querySelector('.line-inv-id')?.dispatchEvent(new Event('blur'));
            },
            bindRow() {},
        },
    };

    let sheetEl = null;
    let sheetTr = null;
    let sheetMode = null;

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function escAttr(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function isMobile() {
        return global.matchMedia && global.matchMedia(MOBILE_MQ).matches;
    }

    function cfg(mode) {
        return MODES[mode] || MODES.track;
    }

    function q(tr, sel) {
        return sel ? tr.querySelector(sel) : null;
    }

    function fmtEuro(n) {
        const x = parseFloat(String(n ?? '').replace(',', '.'));
        if (!Number.isFinite(x)) return '—';
        return x.toFixed(2).replace('.', ',') + '\u202f€';
    }

    function parseQty(hint) {
        const m = String(hint || '').match(/ост(?:аток)?\.?\s*:?\s*(\d+)/i);
        return m ? parseInt(m[1], 10) : null;
    }

    function readRow(tr, mode) {
        const c = cfg(mode);
        const f = c.fields;
        const name = String(q(tr, f.name)?.value || '').trim();
        const qty = parseFloat(String(q(tr, f.qty)?.value || '1').replace(',', '.')) || 1;
        const purchase = parseFloat(String(q(tr, f.purchase)?.value || '0').replace(',', '.')) || 0;
        const sale = parseFloat(String(q(tr, f.sale)?.value || '0').replace(',', '.')) || 0;
        const skuInp = f.sku ? q(tr, f.sku) : null;
        const sku = skuInp ? String(skuInp.value || '').trim() : String(tr.dataset.lineSku || '').trim();
        const invId = f.invId ? parseInt(String(q(tr, f.invId)?.value || ''), 10) || 0 : parseInt(tr.getAttribute('data-inv-id') || '0', 10) || 0;
        const fromEl = q(tr, f.fromStock);
        const dedEl = q(tr, f.deduct);
        const fromDone = tr.getAttribute('data-from-stock') === '1';
        const fromStock = !!(fromEl && !fromEl.disabled && fromEl.checked);
        const deduct = !!(dedEl && !dedEl.disabled && dedEl.checked);
        const hint = String(q(tr, f.hint)?.textContent || '').trim();
        let stockQty = parseQty(hint);
        if (stockQty == null && hint.includes('шт')) {
            const m2 = hint.match(/(\d+)\s*шт/);
            if (m2) stockQty = parseInt(m2[1], 10);
        }
        return { name, qty, purchase, sale, sku, invId, fromDone, fromStock, deduct, hint, stockQty };
    }

    function sourceMeta(row) {
        if (row.fromDone) return { icon: '✓', label: 'Списано', cls: 'is-done' };
        if (row.deduct) return { icon: '⚡', label: 'Сразу', cls: 'is-now' };
        if (row.fromStock) return { icon: '📦', label: 'Со склада', cls: 'is-stock' };
        return { icon: '📝', label: 'Под заказ', cls: 'is-order' };
    }

    function stockMeta(qty) {
        if (qty == null) return { icon: '⚪', label: 'Склад не указан', cls: 'is-unknown' };
        if (qty <= 0) return { icon: '🔴', label: 'Нет в наличии', cls: 'is-out' };
        if (qty <= 2) return { icon: '🟡', label: 'Осталось ' + qty, cls: 'is-low' };
        return { icon: '🟢', label: 'Остаток: ' + qty + ' шт.', cls: 'is-ok' };
    }

    function calcSummary(rows) {
        let purchase = 0;
        let sale = 0;
        rows.forEach((r) => {
            purchase += (r.purchase || 0) * (r.qty || 1);
            sale += (r.sale || 0) * (r.qty || 1);
        });
        const margin = sale - purchase;
        const pct = purchase > 0 ? Math.round((margin / purchase) * 100) : 0;
        return { count: rows.length, purchase, sale, margin, pct };
    }

    function ensureSheet() {
        if (sheetEl) return sheetEl;
        sheetEl = document.createElement('div');
        sheetEl.id = 'olmEditSheet';
        sheetEl.className = 'olm-sheet';
        sheetEl.hidden = true;
        sheetEl.innerHTML =
            '<div class="olm-sheet-backdrop" data-olm-close="1"></div>' +
            '<div class="olm-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="olmSheetTitle">' +
            '<div class="olm-sheet-head">' +
            '<button type="button" class="olm-sheet-back" data-olm-close="1" aria-label="Назад">←</button>' +
            '<div class="olm-sheet-title" id="olmSheetTitle">Позиция</div>' +
            '<button type="button" class="olm-sheet-del" aria-label="Удалить">🗑</button>' +
            '</div>' +
            '<div class="olm-sheet-body">' +
            '<label class="olm-field"><span>Название</span><input type="text" class="olm-inp-name" autocomplete="off"></label>' +
            '<label class="olm-field"><span>SKU / артикул</span><div class="olm-sku-row"><input type="text" class="olm-inp-sku" autocomplete="off" spellcheck="false"><button type="button" class="olm-btn-stock">📦</button></div><div class="olm-sku-stock muted"></div></label>' +
            '<label class="olm-field olm-field-inv" hidden><span>ID склада</span><input type="number" class="olm-inp-inv" min="1" step="1"></label>' +
            '<div class="olm-field"><span>Количество</span><div class="olm-stepper olm-stepper-lg"><button type="button" class="olm-step-minus">−</button><span class="olm-step-val">1</span><button type="button" class="olm-step-plus">+</button></div></div>' +
            '<div class="olm-field-row">' +
            '<label class="olm-field"><span>Закупка (€)</span><input type="number" class="olm-inp-purchase" min="0" step="0.01" inputmode="decimal"></label>' +
            '<label class="olm-field"><span>Продажа (€)</span><input type="number" class="olm-inp-sale" min="0" step="0.01" inputmode="decimal"></label>' +
            '</div>' +
            '<div class="olm-field"><span>Источник</span><div class="olm-source-seg">' +
            '<button type="button" data-src="stock">📦 Со склада</button>' +
            '<button type="button" data-src="now">⚡ Сразу</button>' +
            '<button type="button" data-src="order">📝 Под заказ</button>' +
            '</div></div>' +
            '</div>' +
            '<button type="button" class="olm-sheet-save primary">Сохранить изменения</button>' +
            '</div>';
        document.body.appendChild(sheetEl);

        sheetEl.querySelector('[data-olm-close]')?.addEventListener('click', closeSheet);
        sheetEl.querySelector('.olm-sheet-backdrop')?.addEventListener('click', closeSheet);
        sheetEl.querySelector('.olm-sheet-save')?.addEventListener('click', saveSheet);
        sheetEl.querySelector('.olm-sheet-del')?.addEventListener('click', deleteSheetRow);
        sheetEl.querySelector('.olm-btn-stock')?.addEventListener('click', () => {
            if (sheetTr && sheetMode) cfg(sheetMode).openStockPicker(sheetTr);
        });

        const stepMinus = sheetEl.querySelector('.olm-step-minus');
        const stepPlus = sheetEl.querySelector('.olm-step-plus');
        stepMinus?.addEventListener('click', () => adjustSheetQty(-1));
        stepPlus?.addEventListener('click', () => adjustSheetQty(1));

        sheetEl.querySelectorAll('.olm-source-seg button').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!sheetTr) return;
                const src = btn.getAttribute('data-src');
                const c = cfg(sheetMode);
                const fromEl = q(sheetTr, c.fields.fromStock);
                const dedEl = q(sheetTr, c.fields.deduct);
                if (!fromEl || !dedEl || fromEl.disabled) return;
                if (src === 'stock') { fromEl.checked = true; dedEl.checked = false; }
                else if (src === 'now') { dedEl.checked = true; fromEl.checked = false; }
                else { fromEl.checked = false; dedEl.checked = false; }
                syncSheetSourceUi();
            });
        });

        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && sheetEl && !sheetEl.hidden) closeSheet();
        });

        return sheetEl;
    }

    function closeSheet() {
        if (!sheetEl) return;
        sheetEl.hidden = true;
        document.body.classList.remove('olm-sheet-open');
        sheetTr = null;
    }

    function adjustSheetQty(delta) {
        if (!sheetTr || !sheetMode) return;
        const inp = q(sheetTr, cfg(sheetMode).fields.qty);
        if (!inp) return;
        let v = parseFloat(String(inp.value || '1').replace(',', '.')) || 1;
        v = Math.max(0.01, v + delta);
        inp.value = String(v);
        sheetEl.querySelector('.olm-step-val').textContent = String(v);
    }

    function syncSheetSourceUi() {
        if (!sheetTr || !sheetMode) return;
        const row = readRow(sheetTr, sheetMode);
        const seg = sheetEl.querySelector('.olm-source-seg');
        if (!seg) return;
        let active = 'order';
        if (row.fromDone || row.fromStock) active = 'stock';
        else if (row.deduct) active = 'now';
        seg.querySelectorAll('button').forEach((b) => {
            b.classList.toggle('is-active', b.getAttribute('data-src') === active);
            b.disabled = row.fromDone;
        });
    }

    function syncSheetStockLabel() {
        if (!sheetTr || !sheetMode) return;
        const row = readRow(sheetTr, sheetMode);
        const sm = stockMeta(row.stockQty);
        const el = sheetEl.querySelector('.olm-sku-stock');
        if (el) el.textContent = row.sku ? (row.sku + ' · ' + sm.icon + ' ' + sm.label) : sm.label;
    }

    function openSheet(tr, index, mode) {
        ensureSheet();
        sheetTr = tr;
        sheetMode = mode;
        const c = cfg(mode);
        const row = readRow(tr, mode);
        sheetEl.querySelector('#olmSheetTitle').textContent = 'Позиция №' + (index + 1);
        sheetEl.querySelector('.olm-inp-name').value = row.name;
        const skuInp = sheetEl.querySelector('.olm-inp-sku');
        const invWrap = sheetEl.querySelector('.olm-field-inv');
        const invInp = sheetEl.querySelector('.olm-inp-inv');
        if (c.fields.sku) {
            skuInp.parentElement.parentElement.hidden = false;
            invWrap.hidden = true;
            skuInp.value = row.sku;
            skuInp.readOnly = tr.getAttribute('data-from-stock') === '1';
        } else {
            skuInp.parentElement.parentElement.hidden = false;
            skuInp.value = row.sku || (row.invId > 0 ? 'ID ' + row.invId : '');
            skuInp.readOnly = true;
            invWrap.hidden = false;
            if (invInp) invInp.value = row.invId > 0 ? String(row.invId) : '';
        }
        sheetEl.querySelector('.olm-inp-purchase').value = row.purchase > 0 ? String(row.purchase) : '';
        sheetEl.querySelector('.olm-inp-sale').value = row.sale > 0 ? String(row.sale) : '';
        sheetEl.querySelector('.olm-step-val').textContent = String(row.qty);
        syncSheetSourceUi();
        syncSheetStockLabel();
        sheetEl.hidden = false;
        document.body.classList.add('olm-sheet-open');
        c.refreshHint(tr);
        setTimeout(syncSheetStockLabel, 400);
    }

    function saveSheet() {
        if (!sheetTr || !sheetMode) return;
        const c = cfg(sheetMode);
        q(sheetTr, c.fields.name).value = sheetEl.querySelector('.olm-inp-name').value;
        if (c.fields.sku) {
            q(sheetTr, c.fields.sku).value = sheetEl.querySelector('.olm-inp-sku').value;
        }
        if (c.fields.invId) {
            q(sheetTr, c.fields.invId).value = sheetEl.querySelector('.olm-inp-inv').value;
        }
        const qtyInp = q(sheetTr, c.fields.qty);
        if (qtyInp) qtyInp.value = sheetEl.querySelector('.olm-step-val').textContent;
        q(sheetTr, c.fields.purchase).value = sheetEl.querySelector('.olm-inp-purchase').value;
        q(sheetTr, c.fields.sale).value = sheetEl.querySelector('.olm-inp-sale').value;
        const tr = sheetTr;
        const mode = sheetMode;
        closeSheet();
        const box = tr.closest(c.box) || document.querySelector(c.box);
        if (box) renderPanel(box, mode);
    }

    function deleteSheetRow() {
        if (!sheetTr || !sheetMode) return;
        const tr = sheetTr;
        const mode = sheetMode;
        const c = cfg(mode);
        const del = q(tr, c.fields.del);
        if (del) del.click();
        else tr.remove();
        closeSheet();
        const box = tr.closest(c.box) || document.querySelector(c.box);
        if (box) renderPanel(box, mode);
    }

    function bindQtyStepper(card, tr, mode) {
        const c = cfg(mode);
        const inp = q(tr, c.fields.qty);
        if (!inp) return;
        const valEl = card.querySelector('.olm-step-val');
        const sync = () => { if (valEl) valEl.textContent = String(inp.value || '1'); };
        card.querySelector('.olm-step-minus')?.addEventListener('click', (e) => {
            e.stopPropagation();
            let v = parseFloat(String(inp.value || '1').replace(',', '.')) || 1;
            v = Math.max(0.01, v - 1);
            inp.value = String(v);
            inp.dispatchEvent(new Event('input', { bubbles: true }));
            sync();
            refreshSummary(card.closest('.olm-mobile-panel')?.parentElement, mode);
        });
        card.querySelector('.olm-step-plus')?.addEventListener('click', (e) => {
            e.stopPropagation();
            let v = parseFloat(String(inp.value || '1').replace(',', '.')) || 1;
            v = v + 1;
            inp.value = String(v);
            inp.dispatchEvent(new Event('input', { bubbles: true }));
            sync();
            refreshSummary(card.closest('.olm-mobile-panel')?.parentElement, mode);
        });
        sync();
    }

    function renderCard(tr, index, mode) {
        const row = readRow(tr, mode);
        const src = sourceMeta(row);
        const stk = stockMeta(row.stockQty);
        const skuLine = row.sku ? esc(row.sku) : (row.invId > 0 ? 'ID ' + row.invId : '—');
        const card = document.createElement('div');
        card.className = 'olm-card';
        card.dataset.lineIndex = String(index);
        card.innerHTML =
            '<div class="olm-card-top">' +
            '<span class="olm-card-num">' + (index + 1) + '</span>' +
            '<span class="olm-card-name">' + esc(row.name || 'Без названия') + '</span>' +
            '<button type="button" class="olm-card-del" aria-label="Удалить">🗑</button>' +
            '</div>' +
            '<div class="olm-card-meta">' + skuLine + ' · <span class="olm-stock ' + stk.cls + '">' + stk.icon + ' ' + esc(stk.label) + '</span></div>' +
            '<div class="olm-card-row">' +
            '<div class="olm-stepper"><button type="button" class="olm-step-minus">−</button><span class="olm-step-val">' + esc(String(row.qty)) + '</span><button type="button" class="olm-step-plus">+</button></div>' +
            '<div class="olm-card-prices"><span class="olm-price-buy">' + fmtEuro(row.purchase) + '</span><span class="olm-price-sep">|</span><span class="olm-price-sale">' + fmtEuro(row.sale) + '</span></div>' +
            '<span class="olm-src-badge ' + src.cls + '">' + src.icon + ' ' + esc(src.label) + '</span>' +
            '</div>' +
            '<button type="button" class="olm-card-edit">✏️ Редактировать</button>';
        card.querySelector('.olm-card-del')?.addEventListener('click', (e) => {
            e.stopPropagation();
            const c = cfg(mode);
            const del = q(tr, c.fields.del);
            if (del) del.click();
            else tr.remove();
            renderPanel(tr.closest(c.box) || document.querySelector(c.box), mode);
        });
        card.querySelector('.olm-card-edit')?.addEventListener('click', () => openSheet(tr, index, mode));
        card.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            openSheet(tr, index, mode);
        });
        bindQtyStepper(card, tr, mode);
        return card;
    }

    function renderSummaryEl(panel, rows) {
        let sum = panel.querySelector('.olm-summary');
        if (!sum) {
            sum = document.createElement('div');
            sum.className = 'olm-summary';
            panel.insertBefore(sum, panel.firstChild);
        }
        const s = calcSummary(rows);
        const marginCls = s.margin >= 0 ? 'is-pos' : 'is-neg';
        sum.innerHTML =
            '<div class="olm-summary-grid">' +
            '<div class="olm-sum-item"><span class="olm-sum-label">Позиций</span><span class="olm-sum-val">' + s.count + '</span></div>' +
            '<div class="olm-sum-item"><span class="olm-sum-label">Закупка</span><span class="olm-sum-val">' + fmtEuro(s.purchase) + '</span></div>' +
            '<div class="olm-sum-item"><span class="olm-sum-label">Продажа</span><span class="olm-sum-val">' + fmtEuro(s.sale) + '</span></div>' +
            '<div class="olm-sum-item ' + marginCls + '"><span class="olm-sum-label">Маржа</span><span class="olm-sum-val">' + (s.margin >= 0 ? '+' : '') + fmtEuro(s.margin) + (s.purchase > 0 ? ' <small>(' + (s.margin >= 0 ? '+' : '') + s.pct + '%)</small>' : '') + '</span></div>' +
            '</div>';
    }

    function refreshSummary(box, mode) {
        if (!box) return;
        const panel = box.querySelector('.olm-mobile-panel');
        if (!panel) return;
        const c = cfg(mode);
        const tbody = box.querySelector(c.tbody);
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr')).map((tr) => readRow(tr, mode)).filter((r) => r.name);
        renderSummaryEl(panel, rows);
    }

    function renderPanel(box, mode) {
        if (!box || !isMobile()) return;
        const c = cfg(mode);
        const tbody = box.querySelector(c.tbody) || document.querySelector(c.tbody);
        if (!tbody) return;

        box.classList.add('olm-mobile-active');
        const tableWrap = box.querySelector('.order-lines-table-wrap') || box.querySelector('.lines-table-wrap') || box.querySelector('#linesTableWrap');
        if (tableWrap) tableWrap.classList.add('olm-table-hidden');

        let panel = box.querySelector('.olm-mobile-panel');
        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'olm-mobile-panel';
            const anchor = tableWrap || box.querySelector(c.addBtn);
            if (anchor) box.insertBefore(panel, anchor);
            else box.appendChild(panel);

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'olm-add-btn';
            addBtn.textContent = '+ Добавить позицию';
            addBtn.addEventListener('click', () => {
                box.querySelector(c.addBtn)?.click();
                setTimeout(() => renderPanel(box, mode), 80);
            });
            panel.appendChild(addBtn);

            if (!tbody.dataset.olmObs) {
                tbody.dataset.olmObs = '1';
                new MutationObserver(() => {
                    if (isMobile()) renderPanel(box, mode);
                }).observe(tbody, { childList: true });
            }
        }

        let list = panel.querySelector('.olm-cards');
        if (!list) {
            list = document.createElement('div');
            list.className = 'olm-cards';
            panel.insertBefore(list, panel.querySelector('.olm-add-btn'));
        }

        const trs = Array.from(tbody.querySelectorAll('tr'));
        list.innerHTML = '';
        trs.forEach((tr, i) => {
            c.bindRow(tr);
            list.appendChild(renderCard(tr, i, mode));
            c.refreshHint(tr);
        });

        const rows = trs.map((tr) => readRow(tr, mode)).filter((r) => r.name);
        renderSummaryEl(panel, rows);

        const title = box.querySelector('.title');
        if (title) title.classList.add('olm-title-hidden');
    }

    function teardownPanel(box) {
        if (!box) return;
        box.classList.remove('olm-mobile-active');
        box.querySelector('.order-lines-table-wrap, .lines-table-wrap, #linesTableWrap')?.classList.remove('olm-table-hidden');
        box.querySelector('.title')?.classList.remove('olm-title-hidden');
    }

    function syncOpenSheetFromTr() {
        if (!sheetEl || sheetEl.hidden || !sheetTr || !sheetMode) return;
        if (!document.body.contains(sheetTr)) {
            closeSheet();
            return;
        }
        const tbody = sheetTr.parentElement;
        const index = tbody ? Array.from(tbody.querySelectorAll('tr')).indexOf(sheetTr) : 0;
        openSheet(sheetTr, Math.max(0, index), sheetMode);
    }

    function refreshTrack(root) {
        root = root || document;
        if (!isMobile()) {
            root.querySelectorAll('.order-lines-box.olm-mobile-active').forEach(teardownPanel);
            closeSheet();
            return;
        }
        root.querySelectorAll('.order-lines-box').forEach((box) => renderPanel(box, 'track'));
        syncOpenSheetFromTr();
    }

    function refreshOrderNew() {
        const block = document.querySelector('[data-block-name="lines"]');
        if (!block) return;
        if (!isMobile()) {
            teardownPanel(block);
            closeSheet();
            return;
        }
        renderPanel(block, 'orderNew');
    }

    function initOrderNew() {
        refreshOrderNew();
        if (global.__olmOrderNewMq) return;
        global.__olmOrderNewMq = true;
        const mq = global.matchMedia(MOBILE_MQ);
        const fn = () => refreshOrderNew();
        if (mq.addEventListener) mq.addEventListener('change', fn);
        else mq.addListener(fn);
        const addBtn = document.getElementById('addLine');
        if (addBtn && !addBtn.dataset.olmBound) {
            addBtn.dataset.olmBound = '1';
            addBtn.addEventListener('click', () => setTimeout(refreshOrderNew, 80));
        }
    }

    function initMedia() {
        if (global.__olmMediaBound) return;
        global.__olmMediaBound = true;
        const mq = global.matchMedia(MOBILE_MQ);
        const fn = () => {
            refreshTrack(document.getElementById('ordersTree'));
            refreshOrderNew();
        };
        if (mq.addEventListener) mq.addEventListener('change', fn);
        else mq.addListener(fn);
    }

    global.FixariVanOrderLinesMobile = {
        refreshTrack,
        refreshOrderNew,
        initOrderNew,
        initMedia,
        isMobile,
        closeSheet,
    };

    initMedia();
})(typeof window !== 'undefined' ? window : globalThis);
