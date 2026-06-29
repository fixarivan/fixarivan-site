/**
 * FixariVan — Космический склад: мобильный app-like UI (≤768px).
 * Бизнес-логика и API не меняются.
 */
(function (global) {
    'use strict';

    const STOCK_FILTERS = ['all', 'in-stock', 'low-stock', 'out-of-stock'];

    const MOBILE_CATEGORIES = [
        { id: 'screens', icon: '📱', label: 'Экраны' },
        { id: 'batteries', icon: '🔋', label: 'Батареи' },
        { id: 'protective_glass', icon: '🛡️', label: 'Стёкла' },
        { id: 'charging', icon: '🔌', label: 'Разъёмы' },
        { id: 'accessories', icon: '🎧', label: 'Аксессуары' },
        { id: 'technology', icon: '💻', label: 'Ноутбуки' },
        { id: 'tools', icon: '🛠️', label: 'Инструменты' },
        { id: 'for-order', icon: '📦', label: 'Под заказ' },
    ];

    const EXTRA_CATEGORIES = [
        { id: 'consumables', icon: '🧪', label: 'Расходники' },
        { id: 'cables', icon: '🔌', label: 'Кабели' },
        { id: 'parts', icon: '📦', label: 'Запчасти' },
        { id: 'other', icon: '🔹', label: 'Другое' },
    ];

    let patchedViewItem = false;

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function isMobile() {
        return global.matchMedia && global.matchMedia('(max-width: 768px)').matches;
    }

    function fmtMoney(v) {
        if (typeof global.formatMoney === 'function') return global.formatMoney(Number(v) || 0, 'ru');
        return (Number(v) || 0).toFixed(2).replace('.', ',') + ' €';
    }

    function getStatus(item) {
        if (typeof global.getStatus === 'function') return global.getStatus(item);
        const q = Number(item.quantity) || 0;
        const min = Number(item.minStock != null ? item.minStock : item.min_stock) || 5;
        if (q === 0) return { class: 'out-of-stock', text: '❌' };
        if (q <= min) return { class: 'low-stock', text: '⚠️' };
        return { class: 'in-stock', text: '✅' };
    }

    function catIcon(cat) {
        if (global.categoryIcons && global.categoryIcons[cat]) return global.categoryIcons[cat];
        const found = MOBILE_CATEGORIES.concat(EXTRA_CATEGORIES).find(function (c) { return c.id === cat; });
        return found ? found.icon : '📦';
    }

    function catLabel(cat) {
        if (global.translations && global.translations.ru && global.translations.ru[cat]) {
            return global.translations.ru[cat];
        }
        const found = MOBILE_CATEGORIES.concat(EXTRA_CATEGORIES).find(function (c) { return c.id === cat; });
        return found ? found.label : cat;
    }

    function stockBarMeta(item) {
        const q = Number(item.quantity) || 0;
        const min = Number(item.minStock != null ? item.minStock : item.min_stock) || 5;
        if (q === 0) return { pct: 0, cls: 'is-out' };
        if (q <= min) {
            const pct = Math.min(100, Math.round((q / Math.max(min, 1)) * 100));
            return { pct: Math.max(pct, 8), cls: 'is-low' };
        }
        const max = Math.max(min * 3, q, 1);
        return { pct: Math.min(100, Math.round((q / max) * 100)), cls: 'is-ok' };
    }

    function isStockFilter(filter) {
        return STOCK_FILTERS.indexOf(String(filter || '')) !== -1;
    }

    function countCategory(cat) {
        const inv = global.inventory || [];
        if (cat === 'for-order') {
            return (global.orderPurchaseQueueLines || []).length;
        }
        return inv.filter(function (item) {
            return String(item.category || '') === cat && Number(item.quantity) > 0;
        }).length;
    }

    function getActiveFilter() {
        if (typeof global.getInventoryFilter === 'function') return global.getInventoryFilter();
        return global.currentFilter || 'all';
    }

    function statusLabel(status) {
        if (status.class === 'out-of-stock') return 'Нет в наличии';
        if (status.class === 'low-stock') return 'Мало';
        return 'OK';
    }

    function buildItemCardHtml(item, escapeFn) {
        const escFn = escapeFn || esc;
        const status = getStatus(item);
        const statusText = statusLabel(status);
        const icon = catIcon(item.category);
        const bar = stockBarMeta(item);
        const qty = Number(item.quantity) || 0;
        const sell = Number(item.sellPrice != null ? item.sellPrice : item.sale_price) || 0;
        const cost = Number(item.costPrice != null ? item.costPrice : item.default_cost) || 0;
        const sku = item.sku && String(item.sku).trim()
            ? escFn(String(item.sku).trim())
            : '<span style="opacity:0.55">FV-…</span>';
        const compat = escFn(item.compatibility || '—');
        const name = escFn(item.name || '—');
        const location = item.location && String(item.location).trim()
            ? escFn(String(item.location).trim())
            : '';
        const img = item.image
            ? '<img src="' + escFn(item.image) + '" class="item-image" alt="' + name + '">'
            : '<div class="inv-m-card-icon">' + icon + '</div>';
        const id = Number(item.id);

        return (
            '<article class="item-card inv-m-card" data-item-id="' + id + '">' +
            '<div class="inv-m-card-tap" onclick="viewItem(' + id + ')">' +
            '<div class="inv-m-card-inner">' +
            '<div class="inv-m-card-media">' + img +
            '<span class="inv-m-stock-badge status-' + status.class + '">' + esc(statusText) + '</span></div>' +
            '<div class="inv-m-card-main">' +
            '<div class="inv-m-card-title">' + name + '</div>' +
            '<div class="inv-m-card-sku">SKU: ' + sku + '</div>' +
            '<div class="inv-m-card-compat">' + compat + '</div>' +
            (location ? '<div class="inv-m-card-loc">📍 ' + location + '</div>' : '') +
            '<div class="inv-m-card-stock-row">' +
            '<span class="inv-m-card-qty">Остаток: <strong>' + qty + '</strong></span>' +
            '<span class="inv-m-status-pill status-' + status.class + '">' + esc(statusText) + '</span></div>' +
            '<div class="inv-m-card-prices">' +
            (cost > 0 ? '<span class="inv-m-price-cost">Закуп: ' + fmtMoney(cost) + '</span>' : '') +
            '<span class="inv-m-price-sell">Продажа: ' + fmtMoney(sell) + '</span></div>' +
            '<div class="inv-m-stock-bar"><div class="inv-m-stock-bar-fill ' + bar.cls + '" style="width:' + bar.pct + '%"></div></div>' +
            '</div>' +
            '<div class="inv-m-card-chevron" aria-hidden="true">›</div>' +
            '</div></div>' +
            '<div class="inv-m-card-actions" onclick="event.stopPropagation()">' +
            '<button type="button" class="inv-m-act-btn is-in" title="Приход" onclick="openAdjustModal(' + id + ',\'in\')"><span>➕</span><span>Приход</span></button>' +
            '<button type="button" class="inv-m-act-btn is-out" title="Списание" onclick="openAdjustModal(' + id + ',\'out\')"><span>➖</span><span>Списание</span></button>' +
            '<button type="button" class="inv-m-act-btn" title="Редактировать" onclick="editItem(' + id + ')"><span>📝</span><span>Изм.</span></button>' +
            '<button type="button" class="inv-m-act-btn" title="История" onclick="openHistoryModal(' + id + ')"><span>🕒</span><span>История</span></button>' +
            '<button type="button" class="inv-m-act-btn is-del" title="Удалить" onclick="deleteInventoryItem(' + id + ')"><span>🗑</span><span>Удал.</span></button>' +
            '</div></article>'
        );
    }

    function syncStockChips() {
        const filter = getActiveFilter();
        document.querySelectorAll('.inv-m-stock-chip').forEach(function (btn) {
            const f = btn.getAttribute('data-filter');
            btn.classList.toggle('is-active', isStockFilter(filter) ? f === filter : f === 'all');
        });
    }

    function syncCatalogHead() {
        const grid = document.getElementById('inventoryGrid');
        if (!grid) return;
        let head = document.getElementById('invMobileCatalogHead');
        if (!head) {
            head = document.createElement('div');
            head.className = 'inv-m-catalog-head';
            head.id = 'invMobileCatalogHead';
            grid.parentNode.insertBefore(head, grid);
        }
        const filter = getActiveFilter();
        const showList = filter === 'all' || isStockFilter(filter);
        head.style.display = showList ? '' : 'none';
        if (!showList) return;
        const cards = grid.querySelectorAll('.inv-m-card, .item-card');
        const n = cards.length;
        if (filter === 'all') {
            head.textContent = n > 0 ? 'Товары (' + n + ')' : 'Товары';
        } else if (filter === 'in-stock') {
            head.textContent = 'В наличии (' + n + ')';
        } else if (filter === 'low-stock') {
            head.textContent = 'Мало на складе (' + n + ')';
        } else if (filter === 'out-of-stock') {
            head.textContent = 'Нет в наличии (' + n + ')';
        }
    }

    function syncCategoryCards() {
        const filter = getActiveFilter();
        document.querySelectorAll('.inv-m-cat-card').forEach(function (btn) {
            const id = btn.getAttribute('data-category');
            btn.classList.toggle('is-active', !isStockFilter(filter) && filter === id);
        });
        const head = document.getElementById('invMobileListHead');
        const title = document.getElementById('invMobileListHeadTitle');
        if (head && title) {
            if (filter === 'for-order') {
                head.classList.add('is-visible');
                title.textContent = 'Под заказ';
            } else if (!isStockFilter(filter) && filter !== 'all') {
                head.classList.add('is-visible');
                title.textContent = catLabel(filter);
            } else {
                head.classList.remove('is-visible');
            }
        }
        const catsWrap = document.getElementById('invMobileCategoriesWrap');
        if (catsWrap) {
            catsWrap.style.display = (!isStockFilter(filter) && filter !== 'all') ? 'none' : '';
        }
    }

    function renderCategoryGrid() {
        const grid = document.getElementById('invMobileCategories');
        if (!grid) return;
        grid.innerHTML = MOBILE_CATEGORIES.map(function (cat) {
            const cnt = countCategory(cat.id);
            return (
                '<button type="button" class="inv-m-cat-card" data-category="' + cat.id + '">' +
                '<span class="inv-m-cat-icon">' + cat.icon + '</span>' +
                '<span class="inv-m-cat-label">' + esc(cat.label) + '</span>' +
                '<span class="inv-m-cat-count">' + cnt + '</span></button>'
            );
        }).join('');
        grid.querySelectorAll('.inv-m-cat-card').forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyFilter(btn.getAttribute('data-category'));
            });
        });
    }

    async function applyFilter(filter) {
        if (typeof global.setInventoryFilter === 'function') {
            global.setInventoryFilter(filter);
        } else {
            global.currentFilter = filter;
        }
        document.querySelectorAll('.filter-chips .chip').forEach(function (chip) {
            chip.classList.toggle('active', chip.getAttribute('data-filter') === filter);
        });
        syncStockChips();
        syncCategoryCards();
        if (filter === 'for-order' && typeof global.reloadOrderPurchaseQueue === 'function') {
            await global.reloadOrderPurchaseQueue();
        }
        if (typeof global.loadInventory === 'function' && filter !== 'for-order') {
            await global.loadInventory();
        } else if (typeof global.renderInventory === 'function') {
            global.renderInventory();
        }
        if (isMobile() && filter !== 'all' && !isStockFilter(filter)) {
            requestAnimationFrame(function () {
                const grid = document.getElementById('inventoryGrid');
                if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    }

    function openDetail(id) {
        const item = (global.inventory || []).find(function (i) { return Number(i.id) === Number(id); });
        if (!item) return;
        const panel = document.getElementById('invMobileDetail');
        const body = document.getElementById('invMobileDetailBody');
        const actions = document.getElementById('invMobileDetailActions');
        const headerTitle = document.getElementById('invMobileDetailHeaderTitle');
        if (!panel || !body || !actions) {
            if (typeof global._invViewItemOrig === 'function') global._invViewItemOrig(id);
            return;
        }
        const status = getStatus(item);
        const statusText = statusLabel(status);
        const icon = catIcon(item.category);
        const cost = Number(item.costPrice != null ? item.costPrice : item.default_cost) || 0;
        const sell = Number(item.sellPrice != null ? item.sellPrice : item.sale_price) || 0;

        if (headerTitle) headerTitle.textContent = item.name || 'Товар';

        body.innerHTML =
            '<div class="inv-m-detail-hero">' +
            '<div class="inv-m-detail-photo">' +
            (item.image
                ? '<img src="' + esc(item.image) + '" alt="">'
                : '<span class="inv-m-detail-photo-icon">' + icon + '</span>') +
            '</div>' +
            '<div class="inv-m-detail-name">' + esc(item.name) + '</div>' +
            '<span class="inv-m-detail-badge status-' + status.class + '">' + esc(statusText) + '</span>' +
            '</div>' +
            '<div class="inv-m-detail-facts">' +
            factRow('Артикул', item.sku || '—') +
            factRow('Совместимость', item.compatibility || '—') +
            factRow('Категория', catLabel(item.category)) +
            factRow('Остаток', String(item.quantity) + ' шт') +
            factRow('Закупочная цена', fmtMoney(cost)) +
            factRow('Цена продажи', fmtMoney(sell)) +
            (item.location ? factRow('Место', item.location) : '') +
            '</div>';

        actions.innerHTML =
            '<button type="button" class="inv-m-detail-btn is-in" data-act="in">🟢 Приход</button>' +
            '<button type="button" class="inv-m-detail-btn is-out" data-act="out">🔴 Списание</button>' +
            '<button type="button" class="inv-m-detail-btn is-history" data-act="history">🟣 История</button>' +
            '<button type="button" class="inv-m-detail-btn is-edit" data-act="edit">✏️ Редактировать</button>' +
            '<button type="button" class="inv-m-detail-btn is-delete" data-act="delete">🗑 Удалить</button>';

        actions.querySelector('[data-act="in"]').addEventListener('click', function () {
            closeDetail();
            if (typeof global.openAdjustModal === 'function') global.openAdjustModal(id, 'in');
        });
        actions.querySelector('[data-act="out"]').addEventListener('click', function () {
            closeDetail();
            if (typeof global.openAdjustModal === 'function') global.openAdjustModal(id, 'out');
        });
        actions.querySelector('[data-act="history"]').addEventListener('click', function () {
            closeDetail();
            if (typeof global.openHistoryModal === 'function') global.openHistoryModal(id);
        });
        actions.querySelector('[data-act="edit"]').addEventListener('click', function () {
            closeDetail();
            if (typeof global.editItem === 'function') global.editItem(id);
        });
        actions.querySelector('[data-act="delete"]').addEventListener('click', function () {
            closeDetail();
            if (typeof global.deleteInventoryItem === 'function') global.deleteInventoryItem(id);
        });

        panel.hidden = false;
        requestAnimationFrame(function () { panel.classList.add('is-open'); });
    }

    function factRow(label, value) {
        return (
            '<div class="inv-m-detail-fact">' +
            '<span class="inv-m-detail-fact-label">' + esc(label) + '</span>' +
            '<span class="inv-m-detail-fact-value">' + esc(value) + '</span></div>'
        );
    }

    function closeDetail() {
        const panel = document.getElementById('invMobileDetail');
        if (!panel) return;
        panel.classList.remove('is-open');
        setTimeout(function () {
            panel.hidden = true;
        }, 280);
    }

    function openSheet(sheetId) {
        const backdrop = document.getElementById('invMobileSheetBackdrop');
        const sheet = document.getElementById(sheetId);
        if (!backdrop || !sheet) return;
        backdrop.classList.add('is-open');
        sheet.classList.add('is-open');
    }

    function closeSheets() {
        document.getElementById('invMobileSheetBackdrop')?.classList.remove('is-open');
        document.querySelectorAll('.inv-m-sheet').forEach(function (s) { s.classList.remove('is-open'); });
    }

    function buildFilterSheet() {
        const grid = document.getElementById('invMobileFilterSheetGrid');
        if (!grid || grid.dataset.built === '1') return;
        grid.dataset.built = '1';
        const all = EXTRA_CATEGORIES.concat([{ id: 'all', icon: '📋', label: 'Все позиции' }]);
        grid.innerHTML = all.map(function (cat) {
            return (
                '<button type="button" class="inv-m-sheet-btn" data-filter="' + cat.id + '">' +
                cat.icon + ' ' + esc(cat.label) + '</button>'
            );
        }).join('');
        grid.querySelectorAll('.inv-m-sheet-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeSheets();
                applyFilter(btn.getAttribute('data-filter'));
            });
        });
    }

    function buildMoreSheet() {
        const grid = document.getElementById('invMobileMoreSheetGrid');
        if (!grid || grid.dataset.built === '1') return;
        grid.dataset.built = '1';
        const items = [
            { label: '✨ Добавить позицию', fn: 'openAddModal' },
            { label: '🔄 Обновить с сервера', fn: 'syncInventory' },
            { label: '⚙️ Настройки склада', fn: 'openInventorySettingsModal' },
            { label: '🗑 Очистка склада', fn: 'clearInventory', danger: true },
        ];
        grid.innerHTML = items.map(function (it) {
            return (
                '<button type="button" class="inv-m-sheet-btn' + (it.danger ? ' is-danger' : '') + '" data-fn="' + it.fn + '">' +
                esc(it.label) + '</button>'
            );
        }).join('');
        grid.querySelectorAll('.inv-m-sheet-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeSheets();
                const fn = global[btn.getAttribute('data-fn')];
                if (typeof fn === 'function') fn();
            });
        });
    }

    function injectShell() {
        if (document.getElementById('invMobileTop')) return;

        const controls = document.querySelector('.controls');
        if (!controls) return;

        const top = document.createElement('div');
        top.className = 'inv-m-top';
        top.id = 'invMobileTop';
        top.innerHTML =
            '<div class="inv-m-top-row">' +
            '<h1 class="inv-m-top-title">🚀 Космический склад</h1>' +
            '<div class="inv-m-top-actions">' +
            '<button type="button" class="inv-m-icon-btn" id="invMobileFilterBtn" aria-label="Фильтры">🎛</button>' +
            '<button type="button" class="inv-m-icon-btn" id="invMobileAddBtn" aria-label="Добавить">＋</button>' +
            '</div></div>';

        controls.parentNode.insertBefore(top, controls);

        const stockFilters = document.createElement('div');
        stockFilters.className = 'inv-m-stock-filters';
        stockFilters.id = 'invMobileStockFilters';
        stockFilters.innerHTML =
            '<button type="button" class="inv-m-stock-chip is-active" data-filter="all">Все</button>' +
            '<button type="button" class="inv-m-stock-chip" data-filter="in-stock">✅ В наличии</button>' +
            '<button type="button" class="inv-m-stock-chip" data-filter="low-stock">⚠️ Мало</button>' +
            '<button type="button" class="inv-m-stock-chip" data-filter="out-of-stock">❌ Нет</button>';
        top.appendChild(stockFilters);

        const searchWrap = controls.querySelector('.search-container');
        if (searchWrap) top.appendChild(searchWrap);

        const catsWrap = document.createElement('div');
        catsWrap.className = 'inv-m-categories-wrap';
        catsWrap.id = 'invMobileCategoriesWrap';
        catsWrap.innerHTML =
            '<div class="inv-m-section-head"><span class="inv-m-section-title">Категории</span></div>' +
            '<div class="inv-m-categories" id="invMobileCategories"></div>';
        controls.parentNode.insertBefore(catsWrap, document.getElementById('inventoryGrid'));

        const listHead = document.createElement('div');
        listHead.className = 'inv-m-list-head';
        listHead.id = 'invMobileListHead';
        listHead.innerHTML =
            '<button type="button" class="inv-m-detail-back" id="invMobileListBack" aria-label="Назад">←</button>' +
            '<span class="inv-m-list-head-title" id="invMobileListHeadTitle">Категория</span>';
        controls.parentNode.insertBefore(listHead, document.getElementById('inventoryGrid'));

        stockFilters.querySelectorAll('.inv-m-stock-chip').forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyFilter(btn.getAttribute('data-filter'));
            });
        });

        document.getElementById('invMobileFilterBtn')?.addEventListener('click', function () {
            buildFilterSheet();
            openSheet('invMobileFilterSheet');
        });
        document.getElementById('invMobileAddBtn')?.addEventListener('click', function () {
            if (typeof global.openAddModal === 'function') global.openAddModal();
        });
        document.getElementById('invMobileListBack')?.addEventListener('click', function () {
            applyFilter('all');
        });
        document.getElementById('invMobileDetailBack')?.addEventListener('click', closeDetail);
        document.getElementById('invMobileMoreBtn')?.addEventListener('click', function () {
            buildMoreSheet();
            openSheet('invMobileMoreSheet');
        });
        document.getElementById('invMobileSheetBackdrop')?.addEventListener('click', closeSheets);
    }

    function patchViewItem() {
        if (patchedViewItem || typeof global.viewItem !== 'function') return;
        patchedViewItem = true;
        global._invViewItemOrig = global.viewItem;
        global.viewItem = function (id) {
            if (isMobile()) {
                openDetail(id);
                return;
            }
            global._invViewItemOrig(id);
        };
    }

    function hookRender() {
        if (global.__invMobileRenderHooked) return;
        global.__invMobileRenderHooked = true;
        const orig = global.renderInventory;
        if (typeof orig !== 'function') return;
        global.renderInventory = function () {
            orig.apply(global, arguments);
            afterRender();
        };
    }

    function teardown() {
        document.body.classList.remove('inventory-mobile-app');
        const nav = document.getElementById('inventoryMobileNav');
        if (nav) nav.hidden = true;
        closeDetail();
        closeSheets();
    }

    function refresh() {
        if (!isMobile()) {
            teardown();
            return;
        }
        document.body.classList.add('inventory-mobile-app');
        injectShell();
        patchViewItem();
        hookRender();
        buildFilterSheet();
        buildMoreSheet();
        renderCategoryGrid();
        syncStockChips();
        syncCategoryCards();
        const nav = document.getElementById('inventoryMobileNav');
        if (nav) nav.hidden = false;
    }

    function afterRender() {
        if (!isMobile()) return;
        renderCategoryGrid();
        syncStockChips();
        syncCategoryCards();
        syncCatalogHead();
    }

    function initMediaListener() {
        if (global.__invMobileMqBound) return;
        global.__invMobileMqBound = true;
        const mq = global.matchMedia('(max-width: 768px)');
        const fn = function () {
            refresh();
            if (isMobile() && typeof global.loadInventory === 'function') {
                global.loadInventory();
            } else {
                afterRender();
            }
        };
        if (mq.addEventListener) mq.addEventListener('change', fn);
        else mq.addListener(fn);
        if (isMobile()) refresh();
    }

    global.InventoryMobileApp = {
        refresh: refresh,
        afterRender: afterRender,
        buildItemCardHtml: buildItemCardHtml,
        isMobile: isMobile,
        openDetail: openDetail,
        closeDetail: closeDetail,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMediaListener);
    } else {
        initMediaListener();
    }
})(typeof window !== 'undefined' ? window : globalThis);
