/**
 * Склад FixariVan: данные из SQLite (api/inventory_list.php и др.).
 * Подключается после основного скрипта inventory.html и переопределяет функции.
 */
(function () {
    'use strict';

    var STOCK_FILTERS = ['all', 'in-stock', 'low-stock', 'out-of-stock', 'for-order'];
    var sqliteInventoryWarnShown = false;

    function apiCategoryFilter() {
        if (typeof currentFilter === 'undefined') return '';
        if (STOCK_FILTERS.indexOf(currentFilter) !== -1) return '';
        return currentFilter;
    }

    window.normalizeInventoryItemFromApi = function (row) {
        return {
            id: Number(row.id),
            name: row.name || '',
            category: row.category || 'other',
            sku: row.sku || '',
            compatibility: row.compatibility || '',
            quantity: Number(row.quantity) || 0,
            minStock: Number(row.min_stock) || 0,
            costPrice: Number(row.default_cost) || 0,
            sellPrice: Number(row.sale_price) || 0,
            notes: row.notes || '',
            image: '',
            location: row.location || ''
        };
    };

    /**
     * Список inventory заполняется только по текущему поиску/категории — позиции может не быть в массиве.
     * Для прихода по id (в т.ч. из списка покупок) подгружаем карточку напрямую с сервера.
     */
    async function ensureInventoryItemForId(id) {
        var nid = Number(id);
        if (nid <= 0) {
            return null;
        }
        var found = inventory.find(function (i) {
            return Number(i.id) === nid;
        });
        if (found) {
            return found;
        }
        try {
            var res = await fetch('./api/inventory_list.php?id=' + encodeURIComponent(String(nid)), {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            var json = await res.json();
            if (!json || !json.success) {
                return null;
            }
            var rows = json.data && json.data.items;
            if (!Array.isArray(rows) || !rows[0]) {
                return null;
            }
            var it = normalizeInventoryItemFromApi(rows[0]);
            if (!inventory.some(function (x) {
                return Number(x.id) === nid;
            })) {
                inventory.push(it);
            }
            return it;
        } catch (e) {
            console.warn('ensureInventoryItemForId', e);
            return null;
        }
    }

    window.loadInventory = async function () {
        var searchEl = document.getElementById('searchInput');
        var q = searchEl ? searchEl.value.trim() : '';
        var cat = apiCategoryFilter();
        var params = new URLSearchParams();
        if (q) params.set('q', q);
        if (cat) params.set('category', cat);
        var url = './api/inventory_list.php' + (params.toString() ? '?' + params.toString() : '');
        try {
            var res = await fetch(url, { credentials: 'same-origin' });
            var text = await res.text();
            var json;
            try {
                json = JSON.parse(text);
            } catch (parseErr) {
                throw new Error('Ответ сервера не JSON. Проверьте PHP и путь к api/inventory_list.php');
            }
            if (!json.success) {
                throw new Error(json.message || 'Ошибка загрузки');
            }
            var data = json.data || {};
            var items = Array.isArray(data.items) ? data.items : [];
            if (data.sqlite_available === false && !sqliteInventoryWarnShown) {
                sqliteInventoryWarnShown = true;
                console.warn('FixariVan: на сервере недоступен SQLite (pdo_sqlite). Склад пустой до настройки PHP.');
            }
            inventory = items.map(normalizeInventoryItemFromApi);
            if (typeof reloadOrderPurchaseQueue === 'function') {
                await reloadOrderPurchaseQueue();
            }
            renderInventory();
            if (typeof updateStatsFromServer === 'function') {
                await updateStatsFromServer();
            } else {
                updateStats();
            }
            if (typeof updateUsageChart === 'function') {
                updateUsageChart();
            }
        } catch (e) {
            console.error(e);
            alert('Не удалось загрузить склад: ' + e.message);
        }
    };

    window.updateStatsFromServer = async function () {
        try {
            var res = await fetch('./api/get_inventory_stats.php', { credentials: 'same-origin' });
            var json = await res.json();
            if (!json.success || !json.data) return;
            var d = json.data;
            var totalEl = document.getElementById('totalItems');
            var inStockEl = document.getElementById('inStockItems');
            var lowStockEl = document.getElementById('lowStockItems');
            var valueEl = document.getElementById('totalValue');
            if (totalEl) totalEl.textContent = d.total ?? 0;
            if (inStockEl) inStockEl.textContent = d.in_stock ?? 0;
            if (lowStockEl) lowStockEl.textContent = d.low_stock ?? 0;
            var saleEl = document.getElementById('saleValue');
            var profEl = document.getElementById('profitValue');
            var soldQtyEl = document.getElementById('soldQtyValue');
            var soldRevenueEl = document.getElementById('soldRevenueValue');
            var soldPurchaseEl = document.getElementById('soldPurchaseValue');
            var realizedMarginEl = document.getElementById('realizedMarginValue');
            var pv = Number(d.purchase_value != null ? d.purchase_value : d.value) || 0;
            var sv = Number(d.sale_value) || 0;
            var pr = Number(d.profit_potential) || (sv - pv);
            var soldQty = Number(d.sold_qty) || 0;
            var soldRevenue = Number(d.sold_revenue) || 0;
            var soldPurchase = Number(d.sold_purchase) || 0;
            var realizedMargin = Number(d.realized_margin) || (soldRevenue - soldPurchase);
            if (valueEl) {
                valueEl.textContent = typeof formatMoney === 'function' ? formatMoney(pv, 'ru') : (pv.toFixed(2) + ' €');
            }
            if (saleEl) {
                saleEl.textContent = typeof formatMoney === 'function' ? formatMoney(sv, 'ru') : (sv.toFixed(2) + ' €');
            }
            if (profEl) {
                profEl.textContent = typeof formatMoney === 'function' ? formatMoney(pr, 'ru') : (pr.toFixed(2) + ' €');
            }
            if (soldQtyEl) {
                soldQtyEl.textContent = Number.isInteger(soldQty) ? String(soldQty) : soldQty.toFixed(2);
            }
            if (soldRevenueEl) {
                soldRevenueEl.textContent = typeof formatMoney === 'function' ? formatMoney(soldRevenue, 'ru') : (soldRevenue.toFixed(2) + ' €');
            }
            if (soldPurchaseEl) {
                soldPurchaseEl.textContent = typeof formatMoney === 'function' ? formatMoney(soldPurchase, 'ru') : (soldPurchase.toFixed(2) + ' €');
            }
            if (realizedMarginEl) {
                realizedMarginEl.textContent = typeof formatMoney === 'function' ? formatMoney(realizedMargin, 'ru') : (realizedMargin.toFixed(2) + ' €');
            }
            window.__inventoryCategoryStatsFromServer = Array.isArray(d.categories) ? d.categories : null;
            if (typeof renderCategoryAnalytics === 'function') {
                renderCategoryAnalytics();
            }
        } catch (err) {
            console.warn('updateStatsFromServer', err);
            window.__inventoryCategoryStatsFromServer = null;
            updateStats();
        }
    };

    function setMovementModeVisualState(labelEl, active, kind) {
        if (!labelEl) return;
        if (active && kind === 'sale') {
            labelEl.style.background = 'rgba(16,185,129,0.18)';
            labelEl.style.borderColor = '#10b981';
        } else if (active && kind === 'writeoff') {
            labelEl.style.background = 'rgba(245,158,11,0.18)';
            labelEl.style.borderColor = '#f59e0b';
        } else {
            labelEl.style.background = 'rgba(45,27,78,0.25)';
            labelEl.style.borderColor = 'var(--border)';
        }
    }

    function syncMovementModeUi() {
        var typeEl = document.getElementById('adjustType');
        var saleBox = document.getElementById('movementSaleFlag');
        var writeoffBox = document.getElementById('movementWriteoffFlag');
        var saleOpt = document.getElementById('movementSaleOption');
        var writeoffOpt = document.getElementById('movementWriteoffOption');
        var hint = document.getElementById('movementModeHint');
        if (!typeEl || !saleBox || !writeoffBox) return;
        var isSubtract = typeEl.value === 'subtract';
        saleBox.disabled = !isSubtract;
        writeoffBox.disabled = !isSubtract;
        if (!isSubtract) {
            saleBox.checked = false;
            writeoffBox.checked = false;
            if (hint) {
                hint.textContent = 'Для прихода и установки количества тип расхода не используется.';
            }
        } else if (hint) {
            hint.textContent = 'Продажа влияет на продажи и маржу, списание уменьшает остаток без влияния на продажи. Если ничего не выбрано, будет списание.';
        }
        setMovementModeVisualState(saleOpt, isSubtract && saleBox.checked, 'sale');
        setMovementModeVisualState(writeoffOpt, isSubtract && writeoffBox.checked, 'writeoff');
    }

    function setupMovementModeControls() {
        var saleBox = document.getElementById('movementSaleFlag');
        var writeoffBox = document.getElementById('movementWriteoffFlag');
        var typeEl = document.getElementById('adjustType');
        if (!saleBox || !writeoffBox || !typeEl) return;
        if (!saleBox.dataset.boundFixariVan) {
            saleBox.addEventListener('change', function () {
                if (saleBox.checked) writeoffBox.checked = false;
                syncMovementModeUi();
            });
            saleBox.dataset.boundFixariVan = '1';
        }
        if (!writeoffBox.dataset.boundFixariVan) {
            writeoffBox.addEventListener('change', function () {
                if (writeoffBox.checked) saleBox.checked = false;
                syncMovementModeUi();
            });
            writeoffBox.dataset.boundFixariVan = '1';
        }
        if (!typeEl.dataset.boundFixariVan) {
            typeEl.addEventListener('change', syncMovementModeUi);
            typeEl.dataset.boundFixariVan = '1';
        }
        syncMovementModeUi();
    }

    window.renderInventory = function () {
        var grid = document.getElementById('inventoryGrid');
        if (!grid) return;

        // "Под заказ" всегда рендерится из order_purchase_queue.php (window.orderPurchaseQueueLines).
        if (typeof currentFilter !== 'undefined' && currentFilter === 'for-order') {
            if (typeof renderOrderQueueCardsHtml === 'function') {
                grid.innerHTML = renderOrderQueueCardsHtml();
            } else {
                grid.innerHTML =
                    '<div style="grid-column: 1/-1; text-align: center; padding: 80px 20px;">' +
                    '<h3 style="font-size: 24px; color: var(--highlight); margin-bottom: 8px;">Нет позиций к закупке из заказов</h3>' +
                    '</div>';
            }
            return;
        }

        var filtered = inventory;
        var queueItemIds = (typeof inventoryItemIdsInOrderQueue === 'function')
            ? inventoryItemIdsInOrderQueue()
            : new Set();
        var queueOrderIds = (typeof orderIdsInQueue === 'function')
            ? orderIdsInQueue()
            : new Set();

        function linkedToQueueByReqTag(item) {
            if (!item || !queueOrderIds || queueOrderIds.size === 0) return false;
            var notes = String(item.notes || '').trim();
            if (!notes) return false;
            for (const oid of queueOrderIds) {
                if (notes.indexOf('[REQ ' + oid + ']') !== -1) return true;
            }
            return false;
        }

        if (typeof currentFilter !== 'undefined') {
            filtered = filtered.filter(function (item) {
                if (currentFilter === 'all') return item.quantity > 0;
                if (currentFilter === 'in-stock') return item.quantity > item.minStock;
                if (currentFilter === 'low-stock') {
                    if (queueItemIds.has(item.id) || linkedToQueueByReqTag(item)) return false;
                    return item.quantity > 0 && item.quantity <= item.minStock;
                }
                if (currentFilter === 'out-of-stock') {
                    if (queueItemIds.has(item.id) || linkedToQueueByReqTag(item)) return false;
                    return item.quantity === 0;
                }
                return item.category === currentFilter && item.quantity > 0;
            });
        }

        if (filtered.length === 0) {
            grid.innerHTML =
                '<div style="grid-column: 1/-1; text-align: center; padding: 80px 20px;">' +
                '<div style="font-size: 80px; margin-bottom: 20px; filter: drop-shadow(0 0 20px var(--glow));">🔍</div>' +
                '<h3 style="font-size: 24px; color: var(--highlight); margin-bottom: 8px;">Ничего не найдено</h3>' +
                '<p style="color: var(--text-secondary);">Попробуйте изменить фильтры или добавьте новую позицию</p>' +
                '</div>';
            return;
        }

        grid.innerHTML = filtered.map(function (item) {
            var status = getStatus(item);
            var icon = (typeof categoryIcons !== 'undefined' && categoryIcons[item.category]) ? categoryIcons[item.category] : '📦';
            return (
                '<div class="item-card" onclick="viewItem(' + item.id + ')">' +
                '<div class="item-content">' +
                '<div class="item-header">' +
                '<div class="item-icon-container">' +
                (item.image
                    ? '<img src="' + item.image + '" class="item-image" alt="' + escapeHtml(item.name) + '">'
                    : '<div class="item-icon">' + icon + '</div>') +
                '</div>' +
                '<div class="item-status status-' + status.class + '">' + status.text + '</div>' +
                '</div>' +
                '<div class="item-name">' + escapeHtml(item.name) + '</div>' +
                '<div class="item-sku-inline" style="font-size:0.86rem;opacity:0.88;margin-top:5px;line-height:1.3;">' +
                '<strong>Артикул:</strong> ' +
                (item.sku && String(item.sku).trim()
                    ? escapeHtml(String(item.sku).trim())
                    : '<span style="opacity:0.65">обновите страницу — присвоится автоматически (FV-…)</span>') +
                '</div>' +
                '<div class="item-details">' +
                '<strong>Совместимость:</strong> ' + escapeHtml(item.compatibility || '—') + '<br>' +
                (item.location ? '<strong>Место:</strong> ' + escapeHtml(item.location) : '') +
                '</div>' +
                '<div class="item-quantity">' +
                '<span class="quantity-label">На складе:</span>' +
                '<span class="quantity-value">' + item.quantity + '</span>' +
                '</div>' +
                '<div class="item-actions" onclick="event.stopPropagation()">' +
                '<button type="button" class="btn-small" style="background:linear-gradient(135deg,#10b981,#059669);" onclick="openAdjustModal(' + item.id + ',\'in\')">Приход</button>' +
                '<button type="button" class="btn-small" style="background:linear-gradient(135deg,#ef4444,#dc2626);" onclick="openAdjustModal(' + item.id + ',\'out\')">Списать</button>' +
                '<button type="button" class="btn-small" onclick="openHistoryModal(' + item.id + ')">История</button>' +
                '<button type="button" class="btn-small" onclick="editItem(' + item.id + ')">✏️</button>' +
                '<button type="button" class="btn-small" style="background:rgba(239,68,68,0.2);border-color:#ef4444;color:#fecaca;" onclick="deleteInventoryItem(' + item.id + ')">🗑️</button>' +
                '</div>' +
                '</div></div>'
            );
        }).join('');
    };

    function escapeHtml(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.openAdjustModal = function (id, preset) {
        window.pendingOwlArrival = null;
        currentAdjustId = id;
        var form = document.getElementById('adjustForm');
        if (form) form.reset();
        var adjustType = document.getElementById('adjustType');
        if (adjustType) {
            if (preset === 'in') adjustType.value = 'add';
            else if (preset === 'out') adjustType.value = 'subtract';
            else if (preset === 'set') adjustType.value = 'set';
        }
        setupMovementModeControls();
        syncMovementModeUi();
        var modal = document.getElementById('adjustModal');
        if (modal) modal.classList.add('show');
    };

    window.openHistoryModal = async function (itemId) {
        var modal = document.getElementById('historyModal');
        var bodyEl = document.getElementById('historyModalBody');
        var titleEl = document.getElementById('historyModalTitle');
        if (!modal || !bodyEl) return;
        var item = inventory.find(function (i) { return i.id === itemId; });
        if (titleEl) titleEl.textContent = item ? '📜 История: ' + item.name : '📜 История движений';
        bodyEl.innerHTML = '<p style="color:var(--text-secondary);text-align:center;padding:24px;">Загрузка…</p>';
        modal.classList.add('show');
        try {
            var res = await fetch('./api/inventory_history.php?item_id=' + encodeURIComponent(itemId) + '&limit=200', { credentials: 'same-origin' });
            var text = await res.text();
            var json;
            try {
                json = JSON.parse(text);
            } catch (pe) {
                throw new Error('Некорректный ответ сервера');
            }
            if (!json.success) {
                throw new Error(json.message || 'Ошибка запроса');
            }
            var histData = json.data || {};
            if (histData.sqlite_available === false) {
                bodyEl.innerHTML = '<p style="color:var(--text-secondary);text-align:center;">История недоступна: на сервере не включён SQLite (pdo_sqlite).</p>';
                return;
            }
            var rows = histData.movements || [];
            if (rows.length === 0) {
                bodyEl.innerHTML = '<p style="color:var(--text-secondary);text-align:center;">Движений пока нет</p>';
                return;
            }
            var thead = '<thead><tr><th>Дата</th><th>Тип</th><th>Δ</th><th>Документ</th><th>Примечание</th></tr></thead>';
            var tbody = rows.map(function (r) {
                var ref = '';
                if (r.ref_kind || r.ref_id) {
                    ref = escapeHtml(String(r.ref_kind || '')) + (r.ref_id ? ' · ' + escapeHtml(String(r.ref_id)) : '');
                } else {
                    ref = '—';
                }
                return '<tr><td>' + escapeHtml(r.created_at || '') + '</td><td>' + escapeHtml(r.movement_type || '') + '</td><td>' +
                    (r.quantity_delta != null ? r.quantity_delta : '') + '</td><td>' + ref + '</td><td>' + escapeHtml(r.note || '') + '</td></tr>';
            }).join('');
            bodyEl.innerHTML = '<table class="history-table" style="width:100%;">' + thead + '<tbody>' + tbody + '</tbody></table>';
        } catch (e) {
            bodyEl.innerHTML = '<p style="color:#ef4444;">' + escapeHtml(e.message) + '</p>';
        }
    };

    window.closeHistoryModal = function () {
        var modal = document.getElementById('historyModal');
        if (modal) modal.classList.remove('show');
    };

    function parseInventoryJsonResponse(text) {
        var j;
        try {
            j = JSON.parse(text);
        } catch (e) {
            throw new Error('Сервер вернул не JSON. Проверьте логи PHP и путь к api/inventory_list.php');
        }
        return j;
    }

    function itemIdFromResponse(json) {
        var d = json.data;
        if (d && (d.item_id !== undefined && d.item_id !== null)) {
            return Number(d.item_id);
        }
        return 0;
    }

    function skuInputTrim() {
        var el = document.getElementById('itemSku');
        if (!el) return '';
        return String(el.value || '').trim();
    }

    var _openAddModalOrig = typeof window.openAddModal === 'function' ? window.openAddModal : null;
    window.openAddModal = function () {
        if (_openAddModalOrig) {
            _openAddModalOrig();
        }
        var skuEl = document.getElementById('itemSku');
        if (skuEl) {
            skuEl.value = '';
            skuEl.placeholder = 'Автоматически: FV-…';
        }
    };

    var _editItemOrig = typeof window.editItem === 'function' ? window.editItem : null;
    window.editItem = function (id) {
        if (_editItemOrig) {
            _editItemOrig(id);
        }
        var skuEl = document.getElementById('itemSku');
        if (skuEl) {
            skuEl.placeholder = '';
        }
    };

    window.saveItem = async function () {
        var itemData = {
            name: document.getElementById('itemName').value,
            category: document.getElementById('itemCategory').value,
            sku: skuInputTrim(),
            compatibility: document.getElementById('itemCompatibility').value,
            quantity: parseFloat(document.getElementById('itemQuantity').value) || 0,
            minStock: parseFloat(document.getElementById('itemMinStock').value) || 0,
            costPrice: parseFloat(document.getElementById('itemCostPrice').value) || 0,
            sellPrice: parseFloat(document.getElementById('itemSellPrice').value) || 0,
            location: document.getElementById('itemLocation').value,
            notes: document.getElementById('itemNotes').value
        };

        var payload = {
            name: itemData.name,
            category: itemData.category || null,
            sku: itemData.sku ? itemData.sku : null,
            compatibility: itemData.compatibility || null,
            min_stock: itemData.minStock,
            default_cost: itemData.costPrice,
            sale_price: itemData.sellPrice,
            location: itemData.location || null,
            notes: itemData.notes || null
        };
        if (currentEditId) {
            payload.id = currentEditId;
        }

        try {
            var res = await fetch('./api/inventory_list.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            var txt = await res.text();
            var json = parseInventoryJsonResponse(txt);
            if (!json.success) {
                alert(json.message || 'Не удалось сохранить');
                return;
            }
            if (currentEditId) {
                await loadInventory();
                if (typeof closeModal === 'function') closeModal();
                return;
            }
            var newId = itemIdFromResponse(json);
            if (!newId) {
                alert('Сервер не вернул id новой позиции. Проверьте ответ API.');
                return;
            }
            var d = json.data || {};
            if (d.sku && document.getElementById('itemSku')) {
                document.getElementById('itemSku').value = d.sku;
            }
            if (itemData.quantity > 0) {
                var movRes = await fetch('./api/inventory_movement.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        item_id: newId,
                        movement_type: 'in',
                        quantity: itemData.quantity,
                        note: 'Начальный остаток при создании',
                        unit_cost: itemData.costPrice || null
                    })
                });
                var movTxt = await movRes.text();
                var movJson = parseInventoryJsonResponse(movTxt);
                if (!movJson.success) {
                    alert('Позиция создана, но приход не записан: ' + (movJson.message || ''));
                }
            }
            await loadInventory();
            if (typeof closeModal === 'function') closeModal();
        } catch (e) {
            console.error(e);
            alert('Ошибка: ' + e.message);
        }
    };

    window.adjustQuantity = async function () {
        var cid = Number(currentAdjustId);
        var item = inventory.find(function (i) { return Number(i.id) === cid; });
        if (!item) {
            item = await ensureInventoryItemForId(cid);
        }
        if (!item) {
            alert('Позиция не найдена на сервере (id ' + cid + '). Проверьте каталог склада.');
            return;
        }

        var type = document.getElementById('adjustType').value;
        var quantity = parseFloat(document.getElementById('adjQtyInput').value);
        var reason = document.getElementById('adjustReason').value;

        if (type !== 'set' && !(quantity > 0)) {
            alert('Укажите количество больше 0');
            return;
        }

        var movementType;
        var payloadQty;

        if (type === 'add') {
            movementType = 'in';
            payloadQty = quantity;
        } else if (type === 'subtract') {
            var saleFlag = document.getElementById('movementSaleFlag');
            var writeoffFlag = document.getElementById('movementWriteoffFlag');
            movementType = saleFlag && saleFlag.checked ? 'sale' : 'writeoff';
            if (writeoffFlag && writeoffFlag.checked) {
                movementType = 'writeoff';
            }
            payloadQty = quantity;
        } else {
            movementType = 'adjust';
            payloadQty = quantity - item.quantity;
            if (payloadQty === 0) {
                alert('Остаток уже совпадает');
                return;
            }
        }

        var refKindEl = document.getElementById('moveRefKind');
        var refIdEl = document.getElementById('moveRefId');
        var refKind = refKindEl && refKindEl.value ? String(refKindEl.value).trim() : '';
        var refId = refIdEl && refIdEl.value ? String(refIdEl.value).trim() : '';
        var moveBody = {
            item_id: item.id,
            movement_type: movementType,
            quantity: movementType === 'adjust' ? payloadQty : quantity,
            note: reason || null
        };
        if (movementType === 'sale') {
            moveBody.unit_sale_price = Number(item.sellPrice || item.sale_price || 0) || 0;
        }
        if (refKind) {
            moveBody.ref_kind = refKind;
        }
        if (refId) {
            moveBody.ref_id = refId;
        }
        if (window.pendingOwlArrival && window.pendingOwlArrival.owlId) {
            moveBody.order_warehouse_line_id = window.pendingOwlArrival.owlId;
            moveBody.arrival_context = 'order_queue';
            if (window.pendingOwlArrival.orderId) {
                moveBody.order_id = String(window.pendingOwlArrival.orderId);
            }
            if (window.pendingOwlArrival.documentId) {
                moveBody.document_id = String(window.pendingOwlArrival.documentId);
            }
            if (window.pendingOwlArrival.orderLineKey) {
                moveBody.order_line_key = String(window.pendingOwlArrival.orderLineKey);
            }
            if (!moveBody.ref_kind && window.pendingOwlArrival.documentId) {
                moveBody.ref_kind = 'order';
                moveBody.ref_id = String(window.pendingOwlArrival.documentId);
            }
        }
        try {
            var res = await fetch('./api/inventory_movement.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(moveBody)
            });
            var json = await res.json();
            if (!json.success) {
                alert(json.message || 'Ошибка движения');
                return;
            }
            window.pendingOwlArrival = null;
            // После прихода по очереди всегда перезагружаем источник истины «Под заказ» (order_purchase_queue.php).
            if (typeof reloadOrderPurchaseQueue === 'function') {
                await reloadOrderPurchaseQueue();
            }
            await loadInventory();
            if (typeof renderInventory === 'function' && window.currentFilter === 'for-order') {
                renderInventory();
            }
            if (typeof closeAdjustModal === 'function') closeAdjustModal();
        } catch (e) {
            console.error(e);
            alert('Ошибка: ' + e.message);
        }
    };

    window.openPurchaseArrivalFromQueue = async function (owlId) {
        var lines = window.orderPurchaseQueueLines || [];
        var line = lines.find(function (l) {
            return Number(l.owl_id) === Number(owlId);
        });
        if (!line) {
            alert('Строка не найдена. Обновите страницу.');
            return;
        }
        var iidRaw = line.inventory_item_id;
        if (iidRaw === undefined || iidRaw === null || iidRaw === '') {
            iidRaw = line.item_id;
        }
        var iid = parseInt(String(iidRaw || ''), 10) || 0;
        if (iid <= 0) {
            alert('Нет привязки к карточке склада. Укажите артикул (SKU) в Track для этой позиции или выберите позицию из подсказки при сохранении.');
            var si = document.getElementById('searchInput');
            if (si) {
                si.value = line.name || '';
                if (typeof loadInventory === 'function') loadInventory();
            }
            return;
        }
        var ensured = await ensureInventoryItemForId(iid);
        if (!ensured) {
            alert('Не удалось загрузить карточку склада #' + iid + '. Проверьте, что позиция есть в каталоге.');
            return;
        }
        if (typeof window.openAdjustModal === 'function') {
            window.openAdjustModal(iid, 'in');
        } else {
            currentAdjustId = iid;
            var form = document.getElementById('adjustForm');
            if (form) form.reset();
            var at = document.getElementById('adjustType');
            if (at) at.value = 'add';
            var modal = document.getElementById('adjustModal');
            if (modal) modal.classList.add('show');
        }
        window.pendingOwlArrival = {
            owlId: Number(line.owl_id),
            orderId: String(line.order_id || ''),
            documentId: String(line.document_id || ''),
            orderLineKey: line.order_line_key != null && String(line.order_line_key).trim() !== ''
                ? String(line.order_line_key).trim()
                : ''
        };
        var aq = document.getElementById('adjQtyInput');
        var q = Number(line.qty);
        if (aq) aq.value = String(q > 0 ? q : 1);
        var ar = document.getElementById('adjustReason');
        if (ar) ar.value = 'Приход по заказу ' + (line.order_id || '');
        var rk = document.getElementById('moveRefKind');
        if (rk) rk.value = 'order';
        var ri = document.getElementById('moveRefId');
        if (ri) ri.value = String(line.document_id || line.order_id || '');
        var modal = document.getElementById('adjustModal');
        if (modal) modal.classList.add('show');
    };

    window.dismissPurchaseQueueFromQueue = async function (owlId) {
        if (!confirm('Удалить эту позицию из заказа? Она исчезнет и из Track, и из очереди "Под заказ".')) {
            return;
        }
        try {
            var res = await fetch('./api/order_purchase_queue_action.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'remove_from_order', owl_id: owlId })
            });
            var json = await res.json();
            if (!json || !json.success) {
                throw new Error(json && json.message ? json.message : 'Ошибка');
            }
            if (typeof reloadOrderPurchaseQueue === 'function') {
                await reloadOrderPurchaseQueue();
            }
        } catch (e) {
            alert(e.message || 'Ошибка');
        }
    };

    window.saveInventory = async function () {
        try {
            localStorage.setItem('fixarivan_inventory', JSON.stringify(inventory));
        } catch (e) { /* ignore */ }
    };

    window.syncInventory = async function () {
        try {
            await loadInventory();
            if (typeof updateStatsFromServer === 'function') {
                await updateStatsFromServer();
            }
            alert('✅ Данные обновлены с сервера (SQLite)');
        } catch (e) {
            alert('❌ ' + e.message);
        }
    };

    window.clearInventory = function () {
        alert('Массовая очистка склада в SQLite на этом этапе не выполняется из этой кнопки. Удаление позиций — позже из админки или отдельного API.');
    };

    window.deleteInventoryItem = async function (itemId) {
        var item = inventory.find(function (i) { return i.id === itemId; });
        var itemName = item ? item.name : ('ID ' + itemId);
        if (!confirm('Удалить позицию склада: ' + itemName + '?')) return;
        var pass = prompt('Введите пароль удаления:');
        if (!pass) return;
        try {
            var res = await fetch('./api/inventory_list.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id: itemId, delete_password: pass })
            });
            var txt = await res.text();
            var json = parseInventoryJsonResponse(txt);
            if (!json.success) {
                alert(json.message || 'Ошибка удаления');
                return;
            }
            await loadInventory();
            if (typeof updateStatsFromServer === 'function') {
                await updateStatsFromServer();
            }
        } catch (e) {
            alert('Ошибка удаления: ' + e.message);
        }
    };

    setupMovementModeControls();

})();
