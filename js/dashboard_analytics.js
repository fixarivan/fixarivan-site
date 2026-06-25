/**
 * FixariVan — расширенная аналитика дашборда (KPI, графики, фильтры).
 */
(function (global) {
    'use strict';

    var charts = {};
    var state = {
        preset: '30d',
        chartRange: '30d',
        customFrom: '',
        customTo: ''
    };

    var PRESETS = [
        { id: 'today', label: 'Сегодня' },
        { id: 'yesterday', label: 'Вчера' },
        { id: '7d', label: '7 дней' },
        { id: '30d', label: '30 дней' },
        { id: 'month', label: 'Этот месяц' },
        { id: 'prev_month', label: 'Пред. месяц' },
        { id: 'year', label: 'Этот год' },
        { id: 'custom', label: 'Период' }
    ];

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatEuro(v) {
        if (global.FixariVan && global.FixariVan.format && typeof global.FixariVan.format.euro === 'function') {
            return global.FixariVan.format.euro(v);
        }
        var n = Number(v);
        if (!isFinite(n)) return '0 €';
        return n.toLocaleString('fi-FI', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' €';
    }

    function trendBadge(trend) {
        if (!trend) return '<span class="dash-trend dash-trend--flat">—</span>';
        var dir = trend.direction || 'flat';
        var pct = trend.pct != null ? trend.pct : 0;
        var arrow = dir === 'up' ? '↑' : (dir === 'down' ? '↓' : '→');
        var cls = 'dash-trend dash-trend--' + dir;
        if (dir === 'flat' || pct === 0) {
            return '<span class="dash-trend dash-trend--flat">→ 0% к пред. периоду</span>';
        }
        return '<span class="' + cls + '">' + arrow + ' ' + pct + '% к пред. периоду</span>';
    }

    function statisticCard(opts) {
        return ''
            + '<div class="dash-kpi-card ' + esc(opts.tone || '') + '">'
            + '  <div class="dash-kpi-head"><span class="dash-kpi-icon">' + esc(opts.icon || '') + '</span>'
            + '  <span class="dash-kpi-title">' + esc(opts.title || '') + '</span></div>'
            + '  <div class="dash-kpi-value">' + esc(opts.value != null ? opts.value : '—') + '</div>'
            + '  <div class="dash-kpi-hint">' + esc(opts.hint || '') + '</div>'
            + '  <div class="dash-kpi-trend">' + (opts.trendHtml || '') + '</div>'
            + '</div>';
    }

    function renderOrderKpis(stats) {
        var host = document.getElementById('dashOrderKpiGrid');
        if (!host) return;
        var trends = stats.trends || {};
        host.innerHTML = [
            statisticCard({
                tone: 'tone-waiting',
                icon: '⏳',
                title: 'Ожидают',
                value: stats.waiting ?? stats.pending ?? 0,
                hint: 'Новые и ожидание запчастей',
                trendHtml: trendBadge(trends.waiting || trends.pending)
            }),
            statisticCard({
                tone: 'tone-progress',
                icon: '🔧',
                title: 'В работе',
                value: stats.in_progress ?? 0,
                hint: 'Активные заказы',
                trendHtml: trendBadge(trends.in_progress)
            }),
            statisticCard({
                tone: 'tone-done',
                icon: '✅',
                title: 'Завершено',
                value: stats.completed ?? 0,
                hint: 'Готово / выдано',
                trendHtml: trendBadge(trends.completed)
            }),
            statisticCard({
                tone: 'tone-cancel',
                icon: '🚫',
                title: 'Отменено',
                value: stats.cancelled ?? 0,
                hint: 'Отменённые заказы',
                trendHtml: trendBadge(trends.cancelled)
            }),
            statisticCard({
                tone: 'tone-total',
                icon: '📊',
                title: 'Всего заказов',
                value: stats.total ?? stats.total_orders ?? 0,
                hint: 'За выбранный период',
                trendHtml: trendBadge(trends.total)
            })
        ].join('');
    }

    function renderInventoryKpis(inv) {
        var host = document.getElementById('dashInventoryKpiGrid');
        if (!host || !inv) return;
        var total = Number(inv.total || 0);
        var inStock = Number(inv.in_stock || 0);
        var outStock = Number(inv.out_of_stock != null ? inv.out_of_stock : Math.max(0, total - inStock));
        var margin = Number(inv.profit_potential || 0);
        host.innerHTML = [
            statisticCard({
                tone: 'tone-total',
                icon: '📦',
                title: 'Всего позиций',
                value: total,
                hint: 'Карточки склада',
                trendHtml: ''
            }),
            statisticCard({
                tone: 'tone-done',
                icon: '✅',
                title: 'В наличии',
                value: inStock,
                hint: 'Остаток > 0',
                trendHtml: ''
            }),
            statisticCard({
                tone: 'tone-cancel',
                icon: '❌',
                title: 'Нет на складе',
                value: outStock,
                hint: 'Нулевой остаток',
                trendHtml: ''
            }),
            statisticCard({
                tone: 'tone-finance',
                icon: '💰',
                title: 'Маржа (€)',
                value: formatEuro(margin),
                hint: (formatEuro(inv.purchase_value || 0) + ' → ' + formatEuro(inv.sale_value || 0)),
                trendHtml: ''
            })
        ].join('');
    }

    function renderFinancialBlock(fin) {
        var host = document.getElementById('dashFinancialGrid');
        if (!host || !fin) return;
        host.innerHTML = [
            statisticCard({ tone: 'tone-finance', icon: '💶', title: 'Выручка', value: formatEuro(fin.revenue), hint: 'Касса за период', trendHtml: trendBadge(fin.trends && fin.trends.revenue) }),
            statisticCard({ tone: 'tone-done', icon: '📈', title: 'Прибыль (≈)', value: formatEuro(fin.profit), hint: 'Выручка − расход запчастей', trendHtml: trendBadge(fin.trends && fin.trends.profit) }),
            statisticCard({ tone: 'tone-progress', icon: '📊', title: 'Маржа', value: (fin.margin_pct != null ? fin.margin_pct + '%' : '—'), hint: 'От выручки', trendHtml: '' }),
            statisticCard({ tone: 'tone-total', icon: '🧾', title: 'Средний чек', value: formatEuro(fin.avg_order_value), hint: 'Выручка / заказы', trendHtml: '' }),
            statisticCard({ tone: 'tone-waiting', icon: '💡', title: 'Прибыль / заказ', value: formatEuro(fin.avg_profit_per_order), hint: 'Средняя на заказ', trendHtml: '' })
        ].join('');
    }

    function renderIntegrityPanel(integrity, cache, generatedAt) {
        var host = document.getElementById('dashIntegrityPanel');
        if (!host) return;
        var checks = (integrity && integrity.checks) || [];
        var html = checks.map(function (c) {
            var ok = !!c.ok;
            return '<div class="dash-integrity-row ' + (ok ? 'ok' : 'warn') + '">'
                + '<span>' + esc(c.label) + '</span>'
                + '<span>' + (ok ? '✔' : '⚠') + ' ' + esc(c.detail || (ok ? 'OK' : 'Проверка')) + '</span>'
                + '</div>';
        }).join('');
        html += '<div class="dash-integrity-meta">'
            + '<div>Последняя проверка: <strong>' + esc((integrity && integrity.verified_at) || '—') + '</strong></div>'
            + '<div>Обновление: <strong>' + esc(generatedAt || '—') + '</strong></div>'
            + '<div>Источник: <strong>' + esc((cache && cache.source) || 'sqlite') + '</strong>'
            + ' • Расчёт: <strong>' + esc((cache && cache.calculation_ms != null ? cache.calculation_ms + ' ms' : '—')) + '</strong></div>'
            + '</div>';
        host.innerHTML = html;
    }

    function renderListBlock(hostId, rows, mapRow) {
        var host = document.getElementById(hostId);
        if (!host) return;
        if (!rows || !rows.length) {
            host.innerHTML = '<div class="dash-mini-empty">Нет данных</div>';
            return;
        }
        host.innerHTML = rows.map(mapRow).join('');
    }

    function renderInventoryAnalytics(analytics) {
        if (!analytics) return;
        renderListBlock('dashTopCategories', analytics.top_categories, function (r) {
            return '<div class="dash-mini-row"><span>' + esc(r.category) + '</span><span>' + esc(r.card_count) + ' • ' + formatEuro(r.purchase_value) + '</span></div>';
        });
        renderListBlock('dashMostUsedParts', analytics.most_used_parts, function (r) {
            return '<div class="dash-mini-row"><span>' + esc(r.name || r.sku) + '</span><span>' + esc(r.used_qty) + ' шт.</span></div>';
        });
        renderListBlock('dashLeastUsedParts', analytics.least_used_parts, function (r) {
            return '<div class="dash-mini-row"><span>' + esc(r.name || r.sku) + '</span><span>' + esc(r.used_qty) + ' шт.</span></div>';
        });
        renderListBlock('dashLowStockItems', analytics.low_stock_items, function (r) {
            return '<div class="dash-mini-row"><span>' + esc(r.name || r.sku) + '</span><span>' + esc(r.qty) + ' / min ' + esc(r.min_stock) + '</span></div>';
        });
        renderListBlock('dashRecentParts', analytics.recent_items, function (r) {
            return '<div class="dash-mini-row"><span>' + esc(r.name || r.sku) + '</span><span>' + esc((r.created_at || '').slice(0, 10)) + '</span></div>';
        });
    }

    function destroyChart(key) {
        if (charts[key]) {
            charts[key].destroy();
            charts[key] = null;
        }
    }

    function renderOrdersChart(series) {
        var canvas = document.getElementById('dashOrdersChart');
        if (!canvas || !global.Chart || !series) return;
        destroyChart('orders');
        charts.orders = new global.Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: series.labels || [],
                datasets: [
                    { label: 'Ожидают', data: series.waiting || [], borderColor: '#fbbf24', tension: 0.3, fill: false },
                    { label: 'В работе', data: series.in_progress || [], borderColor: '#60a5fa', tension: 0.3, fill: false },
                    { label: 'Завершено', data: series.completed || [], borderColor: '#34d399', tension: 0.3, fill: false },
                    { label: 'Всего', data: series.total || [], borderColor: '#a78bfa', tension: 0.3, fill: false, borderDash: [4, 4] }
                ]
            },
            options: chartOptions()
        });
    }

    function renderStatusDonut(dist) {
        var canvas = document.getElementById('dashStatusDonut');
        if (!canvas || !global.Chart || !dist) return;
        destroyChart('status');
        charts.status = new global.Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Ожидают', 'В работе', 'Завершено', 'Отменено'],
                datasets: [{
                    data: [dist.waiting || 0, dist.in_progress || 0, dist.completed || 0, dist.cancelled || 0],
                    backgroundColor: ['#fbbf24', '#60a5fa', '#34d399', '#f87171']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1', boxWidth: 12 } } }
            }
        });
    }

    function renderFinanceChart(series) {
        var canvas = document.getElementById('dashFinanceChart');
        if (!canvas || !global.Chart || !series) return;
        destroyChart('finance');
        charts.finance = new global.Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: series.labels || [],
                datasets: [
                    { label: 'Выручка', data: series.revenue || [], borderColor: '#38bdf8', tension: 0.3, fill: false },
                    { label: 'Прибыль', data: series.profit || [], borderColor: '#34d399', tension: 0.3, fill: false }
                ]
            },
            options: chartOptions()
        });
    }

    function renderInventoryMovementChart(movement) {
        var canvas = document.getElementById('dashInventoryMovementChart');
        if (!canvas || !global.Chart || !movement) return;
        destroyChart('inventoryMove');
        charts.inventoryMove = new global.Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: movement.labels || [],
                datasets: [
                    { label: 'Приход', data: movement.incoming || [], backgroundColor: 'rgba(52, 211, 153, 0.65)' },
                    { label: 'Списание', data: movement.consumed || [], backgroundColor: 'rgba(248, 113, 113, 0.65)' }
                ]
            },
            options: chartOptions(true)
        });
    }

    function chartOptions(stacked) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { color: '#94a3b8', maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }, grid: { color: 'rgba(148,163,184,0.08)' } },
                y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.08)' }, stacked: !!stacked }
            },
            plugins: { legend: { labels: { color: '#cbd5e1', boxWidth: 12 } } }
        };
    }

    function renderPeriodMeta(stats) {
        var el = document.getElementById('ordersMeta');
        if (!el) return;
        var period = stats.period || {};
        var cache = stats.cache || {};
        var parts = [];
        if (period.label) parts.push('Период: ' + period.label);
        if (stats.generated_at) parts.push('Обновлено ' + stats.generated_at);
        parts.push('Источник: SQLite');
        if (cache.status) parts.push('Кэш: ' + cache.status);
        el.textContent = parts.join(' • ');
    }

    function buildStatsUrl() {
        var params = new URLSearchParams();
        params.set('preset', state.preset || '30d');
        params.set('chart_range', state.chartRange || '30d');
        if (state.preset === 'custom' && state.customFrom && state.customTo) {
            params.set('from', state.customFrom);
            params.set('to', state.customTo);
        }
        return './api/get_fast_stats.php?' + params.toString();
    }

    function bindFilters(onChange) {
        var bar = document.getElementById('dashPeriodFilter');
        if (!bar) return;
        bar.innerHTML = PRESETS.map(function (p) {
            var active = state.preset === p.id ? ' is-active' : '';
            return '<button type="button" class="dash-period-btn' + active + '" data-preset="' + esc(p.id) + '">' + esc(p.label) + '</button>';
        }).join('')
            + '<div class="dash-period-custom" id="dashPeriodCustom" ' + (state.preset === 'custom' ? '' : 'hidden') + '>'
            + '<input type="date" id="dashPeriodFrom" value="' + esc(state.customFrom) + '">'
            + '<span>—</span>'
            + '<input type="date" id="dashPeriodTo" value="' + esc(state.customTo) + '">'
            + '<button type="button" class="dash-period-apply" id="dashPeriodApply">OK</button>'
            + '</div>';

        bar.querySelectorAll('.dash-period-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var preset = btn.getAttribute('data-preset') || '30d';
                state.preset = preset;
                var custom = document.getElementById('dashPeriodCustom');
                if (custom) custom.hidden = preset !== 'custom';
                bar.querySelectorAll('.dash-period-btn').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                if (preset !== 'custom' && typeof onChange === 'function') onChange();
            });
        });

        var apply = document.getElementById('dashPeriodApply');
        if (apply) {
            apply.addEventListener('click', function () {
                state.customFrom = (document.getElementById('dashPeriodFrom') || {}).value || '';
                state.customTo = (document.getElementById('dashPeriodTo') || {}).value || '';
                if (typeof onChange === 'function') onChange();
            });
        }

        var chartBtns = document.getElementById('dashChartRangeBtns');
        if (chartBtns) {
            chartBtns.querySelectorAll('[data-chart-range]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    state.chartRange = btn.getAttribute('data-chart-range') || '30d';
                    chartBtns.querySelectorAll('[data-chart-range]').forEach(function (b) { b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    if (typeof onChange === 'function') onChange();
                });
            });
        }
    }

    function renderAll(stats) {
        renderPeriodMeta(stats);
        renderOrderKpis(stats);
        renderInventoryKpis(stats.inventory || {});
        renderFinancialBlock(stats.financial || {});
        renderInventoryAnalytics(stats.inventory_analytics || {});
        renderIntegrityPanel(stats.integrity, stats.cache, stats.generated_at);
        if (global.Chart) {
            renderOrdersChart(stats.orders_chart);
            renderStatusDonut(stats.status_distribution);
            renderFinanceChart((stats.financial || {}).series);
            renderInventoryMovementChart(((stats.inventory_analytics || {}).movement));
        }
    }

    global.DashboardAnalytics = {
        state: state,
        init: bindFilters,
        render: renderAll,
        buildStatsUrl: buildStatsUrl,
        destroyCharts: function () {
            Object.keys(charts).forEach(destroyChart);
        }
    };
})(window);
