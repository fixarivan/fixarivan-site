/**
 * Шаблоны диагноза и рекомендаций для diagnostic_mobile.html / diagnostic_pc.html.
 * Подписи кнопок — на русском (мастер); вставляемый текст — по языку отчёта (ru/fi/en).
 */
(function (global) {
    'use strict';

    function tpl(id, labelRu, emoji, texts) {
        return {
            id: id,
            emoji: emoji || '',
            label: labelRu,
            i18n: {
                ru: { text: texts.ru },
                fi: { text: texts.fi },
                en: { text: texts.en },
            },
        };
    }

    const DIAGNOSIS = [
        tpl('display', 'Дисплей', '📱', {
            ru: 'Неисправен дисплей',
            fi: 'Näyttö viallinen',
            en: 'Display defective',
        }),
        tpl('battery', 'АКБ', '🔋', {
            ru: 'Неисправен аккумулятор',
            fi: 'Akku viallinen',
            en: 'Battery defective',
        }),
        tpl('charge_port', 'Зарядка', '🔌', {
            ru: 'Неисправен разъём зарядки',
            fi: 'Latausportti viallinen',
            en: 'Charging port defective',
        }),
        tpl('liquid', 'Жидкость', '💧', {
            ru: 'Попадание жидкости',
            fi: 'Nestevaurio',
            en: 'Liquid damage',
        }),
        tpl('motherboard', 'Мат. плата', '⚡', {
            ru: 'Неисправность материнской платы',
            fi: 'Emolevyviallinen',
            en: 'Motherboard fault',
        }),
        tpl('software', 'ПО', '💿', {
            ru: 'Программная неисправность',
            fi: 'Ohjelmistovika',
            en: 'Software issue',
        }),
        tpl('body', 'Корпус', '📦', {
            ru: 'Повреждение корпуса',
            fi: 'Kotelovaurio',
            en: 'Housing damage',
        }),
        tpl('other', 'Другое', '❓', {
            ru: 'Другая неисправность',
            fi: 'Muu vika',
            en: 'Other fault',
        }),
    ];

    const RECOMMENDATIONS = [
        tpl('replace_display', 'Замена дисплея', '📱', {
            ru: 'Рекомендуется замена дисплея',
            fi: 'Suositellaan näytön vaihtoa',
            en: 'Display replacement recommended',
        }),
        tpl('replace_battery', 'Замена АКБ', '🔋', {
            ru: 'Рекомендуется замена аккумулятора',
            fi: 'Suositellaan akun vaihtoa',
            en: 'Battery replacement recommended',
        }),
        tpl('replace_charge', 'Замена порта', '🔌', {
            ru: 'Рекомендуется замена разъёма зарядки',
            fi: 'Suositellaan latausportin vaihtoa',
            en: 'Charging port replacement recommended',
        }),
        tpl('cleaning', 'Чистка', '🧽', {
            ru: 'Рекомендуется чистка устройства',
            fi: 'Suositellaan laitteen puhdistusta',
            en: 'Device cleaning recommended',
        }),
        tpl('reinstall', 'Переустановка ОС', '💿', {
            ru: 'Рекомендуется переустановка системы',
            fi: 'Suositellaan järjestelmän uudelleinasennusta',
            en: 'Operating system reinstall recommended',
        }),
        tpl('uneconomical', 'Нецелесообразно', '⚠️', {
            ru: 'Экономически ремонт нецелесообразен',
            fi: 'Korjaus ei ole taloudellisesti järkevä',
            en: 'Repair not economically viable',
        }),
        tpl('ok', 'Исправно', '✅', {
            ru: 'Устройство полностью исправно',
            fi: 'Laite on täysin kunnossa',
            en: 'Device fully functional',
        }),
    ];

    let pageConfig = null;

    function normalizeLang(raw) {
        const l = String(raw || 'ru').toLowerCase().trim();
        return (l === 'en' || l === 'fi') ? l : 'ru';
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function getText(row, lang) {
        const l = normalizeLang(lang);
        const t = row.i18n && row.i18n[l] ? row.i18n[l].text : '';
        if (t) return t;
        return row.i18n && row.i18n.ru ? row.i18n.ru.text : row.label;
    }

    function allTexts(row) {
        const out = [];
        ['ru', 'fi', 'en'].forEach((code) => {
            const t = row.i18n && row.i18n[code] ? String(row.i18n[code].text || '').trim() : '';
            if (t) out.push(t);
        });
        return out;
    }

    function splitParts(value) {
        return String(value || '')
            .split(/\s*;\s*/)
            .map((p) => p.trim())
            .filter(Boolean);
    }

    function joinParts(parts) {
        return parts.filter(Boolean).join('; ');
    }

    function findTemplateByPart(part, list) {
        const p = String(part || '').trim();
        if (!p) return null;
        for (let i = 0; i < list.length; i++) {
            const texts = allTexts(list[i]);
            if (texts.indexOf(p) !== -1) return list[i];
        }
        return null;
    }

    function remapTextarea(textarea, list, lang) {
        if (!textarea) return;
        const parts = splitParts(textarea.value);
        if (!parts.length) return;
        const mapped = parts.map((part) => {
            const row = findTemplateByPart(part, list);
            return row ? getText(row, lang) : part;
        });
        textarea.value = joinParts(mapped);
    }

    function syncChipActiveState(container, textarea, list, lang) {
        if (!container || !textarea) return;
        const parts = splitParts(textarea.value);
        container.querySelectorAll('.diag-template-chip').forEach((btn) => {
            const text = String(btn.getAttribute('data-text') || '').trim();
            btn.classList.toggle('is-active', text !== '' && parts.indexOf(text) !== -1);
        });
    }

    function insertTemplateText(textarea, text) {
        const insert = String(text || '').trim();
        if (!insert || !textarea) return;
        const parts = splitParts(textarea.value);
        if (parts.indexOf(insert) !== -1) return;
        if (!parts.length && !textarea.value.trim()) {
            textarea.value = insert;
        } else if (parts.length && !textarea.value.includes(';')) {
            textarea.value = joinParts(parts.concat([insert]));
        } else {
            textarea.value = textarea.value.trim()
                ? joinParts(parts.concat([insert]))
                : insert;
        }
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function removeTemplateText(textarea, text) {
        const insert = String(text || '').trim();
        const parts = splitParts(textarea.value);
        const idx = parts.indexOf(insert);
        if (idx === -1) return;
        parts.splice(idx, 1);
        textarea.value = joinParts(parts);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function toggleTemplateText(textarea, text) {
        const insert = String(text || '').trim();
        const parts = splitParts(textarea.value);
        if (parts.indexOf(insert) !== -1) removeTemplateText(textarea, insert);
        else insertTemplateText(textarea, insert);
    }

    function renderBar(container, textarea, list, lang) {
        if (!container || !textarea) return;
        const l = normalizeLang(lang);
        container.innerHTML = list.map((row) => {
            const label = [row.emoji, row.label].filter(Boolean).join(' ').trim();
            const text = getText(row, l);
            return '<button type="button" class="diag-template-chip" data-id="' + esc(row.id) +
                '" data-text="' + esc(text) + '" title="' + esc(text) + '">' + esc(label) + '</button>';
        }).join('');
        container.querySelectorAll('.diag-template-chip').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                toggleTemplateText(textarea, btn.getAttribute('data-text') || '');
                syncChipActiveState(container, textarea, list, l);
            });
        });
        if (!textarea.dataset.diagTplSync) {
            textarea.dataset.diagTplSync = '1';
            textarea.addEventListener('input', () => syncChipActiveState(container, textarea, list, l));
        }
        syncChipActiveState(container, textarea, list, l);
    }

    function bindBlock(barSel, fieldSel, list) {
        const bar = typeof barSel === 'string' ? document.querySelector(barSel) : barSel;
        const field = typeof fieldSel === 'string' ? document.querySelector(fieldSel) : fieldSel;
        if (!bar || !field) return null;
        return { bar: bar, field: field, list: list };
    }

    function init(config) {
        pageConfig = config || {};
        const lang = pageConfig.getLang ? normalizeLang(pageConfig.getLang()) : 'ru';
        pageConfig._blocks = [
            bindBlock(pageConfig.diagnosisBar, pageConfig.diagnosisField, DIAGNOSIS),
            bindBlock(pageConfig.recommendationsBar, pageConfig.recommendationsField, RECOMMENDATIONS),
        ].filter(Boolean);
        pageConfig._blocks.forEach((b) => renderBar(b.bar, b.field, b.list, lang));
        applyLanguageFromQuery();
    }

    function refreshAll() {
        if (!pageConfig || !pageConfig._blocks) return;
        const lang = pageConfig.getLang ? normalizeLang(pageConfig.getLang()) : 'ru';
        pageConfig._blocks.forEach((b) => {
            remapTextarea(b.field, b.list, lang);
            renderBar(b.bar, b.field, b.list, lang);
        });
    }

    function applyLanguageFromQuery() {
        const params = new URLSearchParams(window.location.search || '');
        const raw = (params.get('lang') || params.get('language') || '').trim();
        if (!raw || !pageConfig || typeof pageConfig.setLang !== 'function') return;
        pageConfig.setLang(normalizeLang(raw), { fromQuery: true });
    }

    global.FixariVanDiagnosticTemplates = {
        DIAGNOSIS: DIAGNOSIS,
        RECOMMENDATIONS: RECOMMENDATIONS,
        normalizeLang: normalizeLang,
        init: init,
        refreshAll: refreshAll,
        applyLanguageFromQuery: applyLanguageFromQuery,
    };

    global.FixariVanDiagnosticTests = {
        init(root) {
            const scope = root ? (typeof root === 'string' ? document.querySelector(root) : root) : document;
            if (!scope) return;
            scope.querySelectorAll('.test-btn').forEach((btn) => {
                if (btn.dataset.diagTestBound === '1') return;
                btn.dataset.diagTestBound = '1';
                btn.addEventListener('click', function () {
                    this.classList.add('is-picking');
                    setTimeout(() => this.classList.remove('is-picking'), 260);
                    this.classList.toggle('good');
                    this.classList.remove('bad');
                    if (typeof global.updateTestSelection === 'function') global.updateTestSelection();
                });
                btn.addEventListener('dblclick', function (e) {
                    e.preventDefault();
                    this.classList.add('is-picking');
                    setTimeout(() => this.classList.remove('is-picking'), 260);
                    this.classList.toggle('bad');
                    this.classList.remove('good');
                    if (typeof global.updateTestSelection === 'function') global.updateTestSelection();
                });
            });
        },
    };
})(typeof window !== 'undefined' ? window : globalThis);
