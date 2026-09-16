/**
 * Track — удаление заявок, массовые операции, завершение по оплате счёта.
 */
(function (window) {
    'use strict';

    let backdropEl = null;
    let busy = false;

    function esc(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }

    function ensureModal() {
        if (backdropEl) return backdropEl;
        backdropEl = document.createElement('div');
        backdropEl.className = 'track-ops-modal-backdrop';
        backdropEl.hidden = true;
        backdropEl.innerHTML = `
            <div class="track-ops-modal" role="dialog" aria-modal="true">
                <div class="track-ops-modal-head"><h3 class="track-ops-modal-title"></h3><button type="button" class="track-ops-modal-x" aria-label="Закрыть">×</button></div>
                <div class="track-ops-modal-body"></div>
                <div class="track-ops-modal-foot"></div>
            </div>`;
        document.body.appendChild(backdropEl);
        backdropEl.querySelector('.track-ops-modal-x').addEventListener('click', closeModal);
        backdropEl.addEventListener('click', (e) => { if (e.target === backdropEl) closeModal(); });
        return backdropEl;
    }

    function closeModal() {
        if (backdropEl) backdropEl.hidden = true;
    }

    function openModal(title, bodyHtml, footHtml) {
        const el = ensureModal();
        el.querySelector('.track-ops-modal-title').textContent = title;
        el.querySelector('.track-ops-modal-body').innerHTML = bodyHtml;
        el.querySelector('.track-ops-modal-foot').innerHTML = footHtml || '';
        el.hidden = false;
    }

    async function apiPost(payload) {
        const res = await fetch('./api/order_ops.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); } catch (_) { throw new Error(text.slice(0, 200) || `HTTP ${res.status}`); }
        if (!json.success) {
            const msg = json.message || (json.errors && json.errors[0]) || 'Ошибка операции';
            const err = new Error(msg);
            err.code = json.code || (json.meta && json.meta.code) || '';
            err.needConfirm = !!(json.need_confirm || (json.meta && json.meta.need_confirm));
            err.payload = json;
            throw err;
        }
        return json;
    }

    function toast(msg, type) {
        if (window.showToast) {
            window.showToast(msg, type || 'info');
        } else {
            alert(msg);
        }
    }

    function requestId(prefix) {
        return prefix + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);
    }

    async function deleteOrder(meta, onDone) {
        if (busy) return;
        const docId = String(meta.document_id || meta.documentId || '');
        if (!docId) return;
        busy = true;
        try {
            const prev = await apiPost({ action: 'preview', document_id: docId });
            const d = prev.data || {};
            const chk = d.delete_check || {};
            const label = `${d.order_id || docId} · ${d.client_name || '—'} · ${d.client_phone || '—'}`;
            if (!chk.allowed) {
                openModal('Удаление недоступно', `<p>${esc(chk.message || 'Есть связанные документы')}</p>`, '<button type="button" class="btn-secondary track-ops-close">Закрыть</button>');
                backdropEl.querySelector('.track-ops-close').addEventListener('click', closeModal);
                return;
            }
            const pwdField = chk.requires_password
                ? '<label class="track-ops-label">Пароль удаления<input type="password" class="track-ops-input" id="trackOpsDeletePwd" autocomplete="off"></label>'
                : '';
            openModal(
                chk.is_lead ? 'Удалить предварительную заявку?' : 'Архивировать заявку?',
                `<p><strong>${esc(label)}</strong></p><p class="muted">${esc(chk.message || '')}</p>${pwdField}`,
                `<button type="button" class="btn-secondary track-ops-cancel">Отмена</button>
                 <button type="button" class="track-ops-danger track-ops-confirm-del">Удалить</button>`
            );
            const confirmBtn = backdropEl.querySelector('.track-ops-confirm-del');
            const cancelBtn = backdropEl.querySelector('.track-ops-cancel');
            cancelBtn.addEventListener('click', closeModal);
            confirmBtn.addEventListener('click', async () => {
                confirmBtn.disabled = true;
                try {
                    const pwdEl = document.getElementById('trackOpsDeletePwd');
                    const pwd = pwdEl ? pwdEl.value : '';
                    const rid = requestId('del');
                    const out = await apiPost({
                        action: 'delete',
                        document_id: docId,
                        delete_password: pwd,
                        request_id: rid,
                    });
                    closeModal();
                    toast(out.message || 'Удалено', 'success');
                    if (out.data && out.data.client_empty) {
                        setTimeout(() => {
                            if (confirm('У клиента больше нет заказов. Удалить пустую карточку клиента в разделе «Клиенты»?')) {
                                window.location.href = 'clients.html';
                            }
                        }, 300);
                    }
                    if (typeof onDone === 'function') onDone(out);
                } catch (e) {
                    confirmBtn.disabled = false;
                    toast(e.message || String(e), 'error');
                }
            });
        } catch (e) {
            toast(e.message || String(e), 'error');
        } finally {
            busy = false;
        }
    }

    async function bulkDelete(documentIds, onDone) {
        if (busy || !documentIds.length) return;
        openModal(
            'Удалить выбранные заявки?',
            `<p>Будет обработано записей: <strong>${documentIds.length}</strong></p>
             <p class="muted">Заявки со счетами, квитанциями или отчётами будут пропущены.</p>
             <label class="track-ops-label">Пароль удаления (если потребуется)<input type="password" class="track-ops-input" id="trackOpsBulkPwd" autocomplete="off"></label>`,
            `<button type="button" class="btn-secondary track-ops-cancel">Отмена</button>
             <button type="button" class="track-ops-danger track-ops-confirm-bulk">Удалить выбранные</button>`
        );
        backdropEl.querySelector('.track-ops-cancel').addEventListener('click', closeModal);
        backdropEl.querySelector('.track-ops-confirm-bulk').addEventListener('click', async () => {
            const btn = backdropEl.querySelector('.track-ops-confirm-bulk');
            btn.disabled = true;
            busy = true;
            try {
                const pwdEl = document.getElementById('trackOpsBulkPwd');
                const out = await apiPost({
                    action: 'bulk_delete',
                    document_ids: documentIds,
                    delete_password: pwdEl ? pwdEl.value : '',
                    request_id: requestId('bulk'),
                });
                closeModal();
                const d = out.data || out;
                toast(d.message || `Удалено: ${d.deleted || 0}`, d.deleted ? 'success' : 'warning');
                if (typeof onDone === 'function') onDone(d);
            } catch (e) {
                btn.disabled = false;
                toast(e.message || String(e), 'error');
            } finally {
                busy = false;
            }
        });
    }

    async function completePaid(documentId, onDone, confirmUnpaid) {
        if (busy) return;
        busy = true;
        try {
            const rid = requestId('complete');
            const out = await apiPost({
                action: 'complete_paid',
                document_id: documentId,
                confirm_unpaid: !!confirmUnpaid,
                request_id: rid,
            });
            closeModal();
            toast(out.message || 'Заказ завершён', 'success');
            if (typeof onDone === 'function') onDone(out);
        } catch (e) {
            if (e.needConfirm || e.code === 'confirm_unpaid') {
                openModal(
                    'Подтвердить оплату?',
                    '<p>Счёт ещё не отмечен как оплаченный.</p><p><strong>Подтвердить оплату и завершить заказ?</strong></p>',
                    `<button type="button" class="btn-secondary track-ops-cancel">Отмена</button>
                     <button type="button" class="primary track-ops-confirm-paid">Да, завершить</button>`
                );
                backdropEl.querySelector('.track-ops-cancel').addEventListener('click', closeModal);
                backdropEl.querySelector('.track-ops-confirm-paid').addEventListener('click', () => {
                    closeModal();
                    completePaid(documentId, onDone, true);
                });
            } else {
                toast(e.message || String(e), 'error');
            }
        } finally {
            busy = false;
        }
    }

    async function reopenOrder(documentId, onDone) {
        if (busy) return;
        if (!confirm('Вернуть заказ в работу?')) return;
        busy = true;
        try {
            const out = await apiPost({
                action: 'reopen',
                document_id: documentId,
                request_id: requestId('reopen'),
            });
            toast(out.message || 'Заказ в работе', 'success');
            if (typeof onDone === 'function') onDone(out);
        } catch (e) {
            toast(e.message || String(e), 'error');
        } finally {
            busy = false;
        }
    }

    function quickActionsHtml(orderDocId, o) {
        if (!orderDocId) return '';
        const pub = String(o.order_status || o.public_status || '').toLowerCase();
        const hasInv = (o.documents || []).some((d) => String(d.type || '').toLowerCase() === 'invoice');
        const isReview = pub === 'pending_review';
        const isClosed = pub === 'delivered' || pub === 'cancelled' || pub === 'done';
        let extra = '';
        if (hasInv && !isClosed) {
            extra += `<button type="button" class="track-qa-item track-ops-complete-paid" data-doc="${esc(orderDocId)}">✅ Счёт оплачен / Завершить</button>`;
        }
        if (isClosed) {
            extra += `<button type="button" class="track-qa-item track-ops-reopen" data-doc="${esc(orderDocId)}">↩ Вернуть в работу</button>`;
        }
        if (isReview) {
            extra += `<button type="button" class="track-qa-item track-ops-delete-lead" data-doc="${esc(orderDocId)}">🗑 Удалить предварительную заявку</button>`;
        } else if (!hasInv) {
            extra += `<button type="button" class="track-qa-item track-ops-delete-lead track-qa-danger" data-doc="${esc(orderDocId)}">🗑 Архивировать тестовую заявку</button>`;
        }
        return `<div class="track-quick-actions">
            <button type="button" class="track-qa-toggle" aria-haspopup="true">⚡ Действия</button>
            <div class="track-qa-menu">
                <button type="button" class="track-qa-item" data-action="scroll-docs">📄 К документам</button>
                ${extra}
            </div>
        </div>`;
    }

    function bindQuickActions(root, refreshFn) {
        root.querySelectorAll('.track-qa-toggle').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const menu = btn.parentElement;
                document.querySelectorAll('.track-quick-actions.is-open').forEach((m) => {
                    if (m !== menu) m.classList.remove('is-open');
                });
                menu.classList.toggle('is-open');
            });
        });
        root.querySelectorAll('[data-action="scroll-docs"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.order-card');
                const sec = card && card.querySelector('.track-section-documents, [data-section="documents"]');
                if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
                btn.closest('.track-quick-actions')?.classList.remove('is-open');
            });
        });
        root.querySelectorAll('.track-ops-delete-lead').forEach((btn) => {
            btn.addEventListener('click', () => {
                btn.closest('.track-quick-actions')?.classList.remove('is-open');
                const docId = btn.getAttribute('data-doc') || '';
                const card = btn.closest('.order-card');
                deleteOrder({ document_id: docId }, refreshFn);
            });
        });
        root.querySelectorAll('.track-ops-complete-paid').forEach((btn) => {
            btn.addEventListener('click', () => {
                btn.closest('.track-quick-actions')?.classList.remove('is-open');
                completePaid(btn.getAttribute('data-doc') || '', refreshFn);
            });
        });
        root.querySelectorAll('.track-ops-reopen').forEach((btn) => {
            btn.addEventListener('click', () => {
                btn.closest('.track-quick-actions')?.classList.remove('is-open');
                reopenOrder(btn.getAttribute('data-doc') || '', refreshFn);
            });
        });
    }

    document.addEventListener('click', () => {
        document.querySelectorAll('.track-quick-actions.is-open').forEach((m) => m.classList.remove('is-open'));
    });

    window.TrackOrderOps = {
        deleteOrder,
        bulkDelete,
        completePaid,
        reopenOrder,
        quickActionsHtml,
        bindQuickActions,
    };
})(window);
