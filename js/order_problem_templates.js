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

    function insertTemplateText(textarea, text) {
        if (!textarea || text == null) return;
        const insert = String(text).trim();
        if (!insert) return;

        const start = textarea.selectionStart ?? textarea.value.length;
        const end = textarea.selectionEnd ?? start;
        const val = textarea.value;
        const before = val.slice(0, start);
        const after = val.slice(end);

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
        const pos = before.length + chunk.length;
        textarea.selectionStart = pos;
        textarea.selectionEnd = pos;
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
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
            btn.addEventListener('click', () => {
                insertTemplateText(textarea, btn.getAttribute('data-text') || '');
            });
        });
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
        renderChips,
        loadAndRender,
    };
})(typeof window !== 'undefined' ? window : globalThis);
