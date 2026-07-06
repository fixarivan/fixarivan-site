/**
 * Быстрые шаблоны «Описание / неисправность» (order_new.html).
 */
(function (global) {
    'use strict';

    let cachedTemplates = [];
    let activeCategoryId = '';

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function getLang() {
        if (global.FixariVanClientOrderI18n && typeof global.FixariVanClientOrderI18n.getClientLang === 'function') {
            return global.FixariVanClientOrderI18n.getClientLang();
        }
        const sel = document.getElementById('clientLanguage');
        return sel ? String(sel.value || 'ru') : 'ru';
    }

    function splitDescriptionParts(value) {
        return String(value || '')
            .split(/\s*;\s*/)
            .map((p) => p.trim())
            .filter(Boolean);
    }

    function joinDescriptionParts(parts) {
        return parts.filter(Boolean).join('; ');
    }

    function allTemplateTexts(templates) {
        const texts = [];
        (templates || []).forEach((t) => {
            if (t.text) texts.push(String(t.text).trim());
            (t.variants || []).forEach((v) => {
                if (v.text) texts.push(String(v.text).trim());
            });
        });
        return texts.filter(Boolean);
    }

    function syncChipActiveState(container, textarea, templates) {
        if (!container || !textarea) return;
        const parts = splitDescriptionParts(textarea.value);
        container.querySelectorAll('.problem-template-chip, .problem-template-variant-chip').forEach((btn) => {
            const text = String(btn.getAttribute('data-text') || '').trim();
            btn.classList.toggle('is-active', text !== '' && parts.indexOf(text) !== -1);
        });
        container.querySelectorAll('.problem-template-chip[data-has-variants="1"]').forEach((btn) => {
            const catId = btn.getAttribute('data-id') || '';
            const cat = (templates || cachedTemplates).find((t) => t.id === catId);
            if (!cat || !cat.variants || !cat.variants.length) return;
            const anyVariantActive = cat.variants.some((v) => parts.indexOf(String(v.text || '').trim()) !== -1);
            btn.classList.toggle('is-active', anyVariantActive || btn.classList.contains('is-open'));
        });
    }

    function insertTemplateText(textarea, text) {
        if (!textarea || text == null) return;
        const insert = String(text).trim();
        if (!insert) return;

        const parts = splitDescriptionParts(textarea.value);
        if (parts.indexOf(insert) !== -1) return;

        const start = textarea.selectionStart ?? textarea.value.length;
        const end = textarea.selectionEnd ?? start;
        const val = textarea.value;
        const before = val.slice(0, start);
        const after = val.slice(end);

        if (parts.length === 0 && before.trim() === '' && after.trim() === '') {
            textarea.value = insert;
        } else if (parts.length > 0 && before.trim() === '' && after.trim() === '') {
            textarea.value = joinDescriptionParts(parts.concat([insert]));
        } else {
            let prefix = '';
            if (before.length > 0) {
                const tail = before.slice(-1);
                if (tail !== '\n' && tail !== ';') {
                    prefix = before.endsWith(' ') || before.endsWith(';') ? ' ' : '; ';
                } else if (tail === ';') {
                    prefix = ' ';
                }
            }
            const chunk = prefix + insert;
            textarea.value = before + chunk + after;
        }

        const pos = textarea.value.length;
        textarea.selectionStart = pos;
        textarea.selectionEnd = pos;
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function removeTemplateText(textarea, text) {
        if (!textarea || text == null) return false;
        const insert = String(text).trim();
        if (!insert) return false;

        const parts = splitDescriptionParts(textarea.value);
        const idx = parts.indexOf(insert);
        if (idx === -1) return false;

        parts.splice(idx, 1);
        textarea.value = joinDescriptionParts(parts);
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
    }

    function toggleTemplateText(textarea, text) {
        const insert = String(text || '').trim();
        if (!insert) return;
        const parts = splitDescriptionParts(textarea.value);
        if (parts.indexOf(insert) !== -1) {
            removeTemplateText(textarea, insert);
        } else {
            insertTemplateText(textarea, insert);
        }
    }

    function renderVariantBar(container, textarea, template) {
        let variantBar = container.querySelector('.problem-template-variant-bar');
        if (!template || !template.variants || !template.variants.length) {
            if (variantBar) variantBar.remove();
            activeCategoryId = '';
            return;
        }
        if (!variantBar) {
            variantBar = document.createElement('div');
            variantBar.className = 'problem-template-variant-bar';
            variantBar.setAttribute('aria-label', 'Уточнение неисправности');
            container.appendChild(variantBar);
        }
        variantBar.innerHTML = template.variants.map((v) => {
            const label = v.label || v.text;
            return `<button type="button" class="problem-template-variant-chip" data-text="${esc(v.text || '')}" title="${esc(v.text || '')}">${esc(label)}</button>`;
        }).join('');

        variantBar.querySelectorAll('.problem-template-variant-chip').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                toggleTemplateText(textarea, btn.getAttribute('data-text') || '');
                syncChipActiveState(container, textarea, cachedTemplates);
            });
        });
    }

    function renderChips(container, textarea, templates) {
        if (!container) return;
        cachedTemplates = Array.isArray(templates) ? templates : [];
        const rows = cachedTemplates;
        if (!rows.length) {
            container.innerHTML = '';
            container.hidden = true;
            return;
        }
        container.hidden = false;
        const chipsHtml = rows.map((t) => {
            const label = [t.emoji, t.label].filter(Boolean).join(' ').trim() || t.text;
            const hasVariants = Array.isArray(t.variants) && t.variants.length > 0;
            return `<button type="button" class="problem-template-chip${activeCategoryId === t.id ? ' is-open' : ''}" data-id="${esc(t.id || '')}" data-has-variants="${hasVariants ? '1' : '0'}" data-text="${esc(t.text || t.label || '')}" title="${esc(t.text || '')}">${esc(label)}</button>`;
        }).join('');
        container.innerHTML = chipsHtml;

        container.querySelectorAll('.problem-template-chip').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const catId = btn.getAttribute('data-id') || '';
                const tpl = rows.find((t) => t.id === catId);
                const hasVariants = btn.getAttribute('data-has-variants') === '1';

                if (hasVariants && tpl) {
                    if (activeCategoryId === catId) {
                        activeCategoryId = '';
                        btn.classList.remove('is-open');
                        renderVariantBar(container, textarea, null);
                    } else {
                        activeCategoryId = catId;
                        container.querySelectorAll('.problem-template-chip').forEach((b) => b.classList.remove('is-open'));
                        btn.classList.add('is-open');
                        renderVariantBar(container, textarea, tpl);
                    }
                    syncChipActiveState(container, textarea, cachedTemplates);
                    return;
                }

                activeCategoryId = '';
                renderVariantBar(container, textarea, null);
                toggleTemplateText(textarea, btn.getAttribute('data-text') || '');
                syncChipActiveState(container, textarea, cachedTemplates);
            });
        });

        if (activeCategoryId) {
            const openTpl = rows.find((t) => t.id === activeCategoryId);
            renderVariantBar(container, textarea, openTpl || null);
        }

        if (textarea && !textarea.dataset.tplSyncBound) {
            textarea.dataset.tplSyncBound = '1';
            textarea.addEventListener('input', () => syncChipActiveState(container, textarea, cachedTemplates));
        }

        syncChipActiveState(container, textarea, cachedTemplates);
    }

    async function loadAndRender(options) {
        const container = options.container;
        const textarea = options.textarea;
        const lang = options.lang || getLang();
        const url = (options.url || './api/order_problem_templates.php') + '?lang=' + encodeURIComponent(lang);
        if (!container || !textarea) return [];

        try {
            const res = await fetch(url, { credentials: 'same-origin' });
            const json = await res.json();
            const templates = json && json.success && Array.isArray(json.templates) ? json.templates : [];
            activeCategoryId = '';
            renderChips(container, textarea, templates);
            return templates;
        } catch (e) {
            console.warn('problem templates load failed', e);
            container.hidden = true;
            return [];
        }
    }

    function bindLanguageReload(options) {
        if (options && options._langBound) return;
        if (options) options._langBound = true;
        global.addEventListener('fixarivan:client-lang-change', function () {
            loadAndRender(options || {});
        });
    }

    global.FixariVanProblemTemplates = {
        esc,
        getLang,
        insertTemplateText,
        removeTemplateText,
        toggleTemplateText,
        renderChips,
        loadAndRender,
        bindLanguageReload,
    };
})(typeof window !== 'undefined' ? window : globalThis);
