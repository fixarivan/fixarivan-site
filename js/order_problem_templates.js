/**
 * Быстрые шаблоны «Описание / неисправность» (order_new.html).
 */
(function (global) {
    'use strict';

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
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

    function syncChipActiveState(container, textarea) {
        if (!container || !textarea) return;
        const parts = splitDescriptionParts(textarea.value);
        container.querySelectorAll('.problem-template-chip').forEach((btn) => {
            const text = String(btn.getAttribute('data-text') || '').trim();
            btn.classList.toggle('is-active', text !== '' && parts.indexOf(text) !== -1);
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

    function renderChips(container, textarea, templates) {
        if (!container) return;
        const rows = Array.isArray(templates) ? templates : [];
        if (!rows.length) {
            container.innerHTML = '';
            container.hidden = true;
            return;
        }
        container.hidden = false;
        container.innerHTML = rows.map((t) => {
            const label = [t.emoji, t.label].filter(Boolean).join(' ').trim() || t.text;
            return `<button type="button" class="problem-template-chip" data-text="${esc(t.text || t.label || '')}" title="${esc(t.text || '')}">${esc(label)}</button>`;
        }).join('');

        container.querySelectorAll('.problem-template-chip').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                toggleTemplateText(textarea, btn.getAttribute('data-text') || '');
                syncChipActiveState(container, textarea);
            });
        });

        if (textarea && !textarea.dataset.tplSyncBound) {
            textarea.dataset.tplSyncBound = '1';
            textarea.addEventListener('input', () => syncChipActiveState(container, textarea));
        }

        syncChipActiveState(container, textarea);
    }

    async function loadAndRender(options) {
        const container = options.container;
        const textarea = options.textarea;
        const url = options.url || './api/order_problem_templates.php';
        if (!container || !textarea) return [];

        try {
            const res = await fetch(url, { credentials: 'same-origin' });
            const json = await res.json();
            const templates = json && json.success && Array.isArray(json.templates) ? json.templates : [];
            renderChips(container, textarea, templates);
            return templates;
        } catch (e) {
            console.warn('problem templates load failed', e);
            container.hidden = true;
            return [];
        }
    }

    global.FixariVanProblemTemplates = {
        esc,
        insertTemplateText,
        removeTemplateText,
        toggleTemplateText,
        renderChips,
        loadAndRender,
    };
})(typeof window !== 'undefined' ? window : globalThis);
