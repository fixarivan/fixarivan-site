/*
 * Общие помощники для работы с документами FixariVan
 */

(function(window) {
    async function fetchJson(url, options = {}) {
        if (window.FixariVan && window.FixariVan.http && typeof window.FixariVan.http.fetchJson === 'function') {
            return window.FixariVan.http.fetchJson(url, options);
        }

        const response = await fetch(url, { credentials: 'same-origin', ...options });
        const text = await response.text();
        if (!response.ok) {
            const snippet = (text || '').trim().slice(0, 200);
            throw new Error(snippet || `HTTP ${response.status}`);
        }
        try {
            const parsed = JSON.parse(text);
            if (parsed && typeof parsed === 'object' && Object.prototype.hasOwnProperty.call(parsed, 'success') && parsed.success === false) {
                const msg = parsed.message || (Array.isArray(parsed.errors) && parsed.errors[0]) || 'Ошибка запроса';
                throw new Error(msg);
            }
            return parsed;
        } catch (error) {
            if (error instanceof SyntaxError) {
                const snippet = (text || '').trim().slice(0, 200);
                throw new Error(snippet || 'Сервер вернул некорректный ответ');
            }
            throw error;
        }
    }

    const FIELD_SCHEMAS = {
        order: [
            { key: 'client_name', label: 'Клиент', type: 'text', required: true },
            { key: 'client_phone', label: 'Телефон', type: 'text' },
            { key: 'client_email', label: 'Email', type: 'email' },
            { key: 'device_model', label: 'Модель устройства', type: 'text', required: true },
            { key: 'device_serial', label: 'Серийный номер', type: 'text' },
            { key: 'device_type', label: 'Тип устройства', type: 'text' },
            { key: 'problem_description', label: 'Описание проблемы', type: 'textarea' },
            { key: 'device_password', label: 'Пароль/код блокировки', type: 'text' },
            { key: 'device_condition', label: 'Состояние устройства', type: 'textarea' },
            { key: 'accessories', label: 'Аксессуары', type: 'textarea' },
            { key: 'priority', label: 'Приоритет', type: 'select', options: ['low','normal','high','urgent'] },
            { key: 'status', label: 'Статус', type: 'select', options: ['pending','in_progress','completed','cancelled'] },
            { key: 'place_of_acceptance', label: 'Место приёма', type: 'text' },
            { key: 'date_of_acceptance', label: 'Дата приёма', type: 'date' },
            { key: 'unique_code', label: 'Уникальный код', type: 'text' },
            { key: 'technician_name', label: 'Мастер', type: 'text' },
            { key: 'work_date', label: 'Дата работы', type: 'date' },
            { key: 'language', label: 'Язык', type: 'select', options: ['ru','en','fi'] }
        ],
        receipt: [
            { key: 'client_name', label: 'Клиент', type: 'text', required: true },
            { key: 'client_phone', label: 'Телефон', type: 'text' },
            { key: 'client_email', label: 'Email', type: 'email' },
            { key: 'device_model', label: 'Модель устройства', type: 'text' },
            { key: 'device_serial', label: 'Серийный номер', type: 'text' },
            { key: 'services_rendered', label: 'Оказанные услуги', type: 'textarea' },
            { key: 'total_amount', label: 'Сумма (€)', type: 'number', step: '0.01' },
            { key: 'payment_method', label: 'Способ оплаты', type: 'text' },
            { key: 'notes', label: 'Заметки', type: 'textarea' },
            { key: 'status', label: 'Статус', type: 'select', options: ['pending','completed','cancelled'] },
            { key: 'priority', label: 'Приоритет', type: 'select', options: ['low','normal','high','urgent'] },
            { key: 'place_of_acceptance', label: 'Место приёма', type: 'text' },
            { key: 'date_of_acceptance', label: 'Дата приёма', type: 'date' },
            { key: 'unique_code', label: 'Уникальный код', type: 'text' },
            { key: 'language', label: 'Язык', type: 'select', options: ['ru','en','fi'] }
        ],
        report: [
            { key: 'client_name', label: 'Клиент', type: 'text', required: true },
            { key: 'client_phone', label: 'Телефон', type: 'text' },
            { key: 'client_email', label: 'Email', type: 'email' },
            { key: 'device_model', label: 'Модель устройства', type: 'text', required: true },
            { key: 'device_serial', label: 'Серийный номер', type: 'text' },
            { key: 'diagnosis', label: 'Диагноз', type: 'textarea' },
            { key: 'recommendations', label: 'Рекомендации', type: 'textarea' },
            { key: 'repair_cost', label: 'Стоимость ремонта (€)', type: 'number', step: '0.01' },
            { key: 'repair_time', label: 'Время ремонта (часы)', type: 'number', step: '1' },
            { key: 'warranty', label: 'Гарантия', type: 'select', options: ['0','1'] },
            { key: 'status', label: 'Статус', type: 'select', options: ['pending','in_progress','completed','cancelled'] },
            { key: 'priority', label: 'Приоритет', type: 'select', options: ['low','normal','high','urgent'] },
            { key: 'place_of_acceptance', label: 'Место диагностики', type: 'text' },
            { key: 'date_of_acceptance', label: 'Дата диагностики', type: 'date' },
            { key: 'unique_code', label: 'Уникальный код', type: 'text' },
            { key: 'technician_name', label: 'Мастер', type: 'text' },
            { key: 'work_date', label: 'Дата работы', type: 'date' },
            { key: 'language', label: 'Язык', type: 'select', options: ['ru','en','fi'] }
        ],
        invoice: [
            { key: 'invoice_id', label: 'Номер счёта', type: 'text' },
            { key: 'client_name', label: 'Клиент', type: 'text', required: true },
            { key: 'client_phone', label: 'Телефон', type: 'text' },
            { key: 'client_email', label: 'Email', type: 'email' },
            { key: 'service_object', label: 'Объект/услуга', type: 'text' },
            { key: 'due_date', label: 'Срок оплаты', type: 'date' },
            { key: 'payment_terms', label: 'Условия оплаты', type: 'text' },
            { key: 'total_amount', label: 'Итого (€)', type: 'number', step: '0.01' },
            { key: 'note', label: 'Примечание', type: 'textarea' },
            { key: 'status', label: 'Статус', type: 'select', options: ['draft','issued','paid','overdue','cancelled'] }
        ]
    };

    function getAuthHeaders() {
        return { 'Content-Type': 'application/json' };
    }

    function sanitizeText(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        if (Array.isArray(value)) {
            return value.map(item => sanitizeText(item)).join(', ');
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function prepareImageSource(value) {
        if (!value) {
            return '';
        }
        const trimmed = String(value).trim();
        if (trimmed.startsWith('data:image')) {
            return trimmed;
        }
        return sanitizeText(trimmed);
    }

    function buildFieldList(type, data) {
        const schema = FIELD_SCHEMAS[type] || [];
        return schema.map(field => ({
            label: field.label,
            value: sanitizeText(data[field.key])
        }));
    }

    function renderMediaBlocks(data) {
        const blocks = [];

        if (data && data.client_signature) {
            const src = prepareImageSource(data.client_signature);
            if (src) {
                blocks.push(`
                    <div class="modal-field">
                        <div class="modal-label">Подпись клиента</div>
                        <div class="modal-value"><img src="${src}" alt="client signature" style="max-width:100%;border-radius:12px;border:1px solid rgba(148,163,184,0.3);padding:8px;background:rgba(15,23,42,0.4);"></div>
                    </div>
                `);
            }
        }

        if (data && data.pattern_data) {
            const src = prepareImageSource(data.pattern_data);
            if (src) {
                blocks.push(`
                    <div class="modal-field">
                        <div class="modal-label">Графический пароль</div>
                        <div class="modal-value"><img src="${src}" alt="pattern" style="max-width:100%;border-radius:12px;border:1px solid rgba(148,163,184,0.3);padding:8px;background:rgba(15,23,42,0.4);"></div>
                    </div>
                `);
            }
        }

        if (!blocks.length) {
            return '';
        }

        return `<div class="modal-section">${blocks.join('')}</div>`;
    }

    function createOverlay(contentHtml) {
        const overlay = document.createElement('div');
        overlay.className = 'fixarivan-modal-overlay';
        overlay.innerHTML = contentHtml;

        function closeModal() {
            overlay.classList.remove('show');
            setTimeout(() => overlay.remove(), 200);
        }

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function escHandler(evt) {
            if (evt.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escHandler);
            }
        });

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('show'));
        return { overlay, closeModal };
    }

    const FixariVanDocuments = {
        async fetchDocument(documentId, documentType) {
            const result = await fetchJson(`./api/get_document.php?id=${encodeURIComponent(documentId)}&type=${documentType}`);
            if (!result.success) {
                throw new Error(result.message || 'Не удалось получить документ');
            }
            return result.data;
        },

        showViewModal(data, documentType) {
            const fields = buildFieldList(documentType, data);
            const extraInfo = [
                { label: 'Номер документа', value: sanitizeText(data.document_id) },
                { label: 'Дата создания', value: sanitizeText(data.date_created || '—') },
                { label: 'Дата обновления', value: sanitizeText(data.date_updated || '—') }
            ];

            const content = `
                <div class="fixarivan-modal">
                    <div class="modal-header">
                        <h2>Документ ${data.document_id}</h2>
                        <button class="modal-close" data-close>&times;</button>
                    </div>
                    <div class="modal-section">
                        ${extraInfo.map(item => `
                            <div class="modal-field">
                                <div class="modal-label">${item.label}</div>
                                <div class="modal-value">${item.value}</div>
                            </div>
                        `).join('')}
                    </div>
                    <div class="modal-section">
                        ${fields.map(field => `
                            <div class="modal-field">
                                <div class="modal-label">${field.label}</div>
                                <div class="modal-value">${field.value}</div>
                            </div>
                        `).join('')}
                    </div>
                    ${renderMediaBlocks(data)}
                    <div class="modal-actions">
                        <button class="btn" data-close>Закрыть</button>
                    </div>
                </div>
            `;

            const { overlay, closeModal } = createOverlay(content);
            overlay.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', closeModal));
        },

        showEditModal(data, documentType, onSuccess) {
            const schema = FIELD_SCHEMAS[documentType] || [];
            const content = `
                <div class="fixarivan-modal">
                    <div class="modal-header">
                        <h2>Редактирование ${data.document_id}</h2>
                        <button class="modal-close" data-close>&times;</button>
                    </div>
                    <form id="fixarivan-edit-form">
                        <div class="modal-section">
                            ${schema.map(field => {
                                const value = data[field.key] || '';
                                if (field.type === 'textarea') {
                                    return `
                                        <label class="modal-label" for="field-${field.key}">${field.label}</label>
                                        <textarea id="field-${field.key}" name="${field.key}" rows="3" ${field.required ? 'required' : ''}>${value}</textarea>
                                    `;
                                }
                                if (field.type === 'select') {
                                    const options = field.options.map(option => {
                                        const selected = String(value) === String(option) ? 'selected' : '';
                                        return `<option value="${option}" ${selected}>${option}</option>`;
                                    }).join('');
                                    return `
                                        <label class="modal-label" for="field-${field.key}">${field.label}</label>
                                        <select id="field-${field.key}" name="${field.key}" ${field.required ? 'required' : ''}>
                                            ${options}
                                        </select>
                                    `;
                                }
                                return `
                                    <label class="modal-label" for="field-${field.key}">${field.label}</label>
                                    <input id="field-${field.key}" name="${field.key}" type="${field.type}" value="${value}" ${field.step ? `step="${field.step}"` : ''} ${field.required ? 'required' : ''}>
                                `;
                            }).join('')}
                        </div>
                        <div class="modal-actions">
                            <button type="submit" class="btn primary">Сохранить</button>
                            <button type="button" class="btn" data-close>Отмена</button>
                        </div>
                    </form>
                </div>
            `;

            const { overlay, closeModal } = createOverlay(content);
            overlay.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', closeModal));

            const form = overlay.querySelector('#fixarivan-edit-form');
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const formData = new FormData(form);
                const updates = {};
                formData.forEach((value, key) => {
                    if (value === '' || value === null) {
                        updates[key] = null;
                    } else {
                        updates[key] = value;
                    }
                });

                try {
                    await FixariVanDocuments.updateDocument(data.document_id, documentType, updates);
                    closeModal();
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                } catch (error) {
                    alert(error.message);
                }
            });
        },

        async updateDocument(documentId, documentType, updates) {
            const result = await fetchJson('./api/update_document.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: getAuthHeaders(),
                body: JSON.stringify({
                    documentId,
                    documentType,
                    updates
                })
            });
            if (!result.success) {
                throw new Error(result.message || 'Не удалось обновить документ');
            }
            return result;
        },

        async exportDocument(documentId, documentType, button) {
            if (String(documentType || '').toLowerCase() === 'report') {
                throw new Error('PDF для диагностических отчётов временно отключён. Используйте web-ссылку отчёта.');
            }
            if (button) {
                button.disabled = true;
                button.dataset.originalText = button.innerHTML;
                button.innerHTML = '⏳ Генерация...';
            }

            try {
                const data = await FixariVanDocuments.fetchDocument(documentId, documentType);
                const result = await fetchJson('./api/generate_dompdf_fixed.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ documentId, documentType, language: data.language || 'ru' })
                });
                if (!result.success) {
                    throw new Error(result.message || 'Ошибка генерации PDF');
                }
                window.open(result.download_url || result.pdf_file, '_blank');
            } finally {
                if (button) {
                    button.disabled = false;
                    if (button.dataset.originalText) {
                        button.innerHTML = button.dataset.originalText;
                        delete button.dataset.originalText;
                    }
                }
            }
        },

        async deleteDocument(documentId, documentType) {
            const pass = typeof prompt === 'function'
                ? prompt('Введите пароль удаления:')
                : '';
            if (pass === null || pass === '') {
                return { success: false, cancelled: true };
            }
            const result = await fetchJson('./api/delete_document_safe_fixed.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: getAuthHeaders(),
                body: JSON.stringify({ documentId, documentType, delete_password: pass })
            });
            if (!result.success) {
                throw new Error(result.message || 'Ошибка удаления документа');
            }
            return result;
        }
    };

    window.FixariVanDocuments = FixariVanDocuments;

    // Добавляем стили модального окна, если их ещё нет
    if (!document.getElementById('fixarivan-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'fixarivan-modal-styles';
        style.textContent = `
            .fixarivan-modal-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.75);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                opacity: 0;
                transition: opacity 0.2s ease;
                z-index: 2000;
            }
            .fixarivan-modal-overlay.show {
                opacity: 1;
            }
            .fixarivan-modal {
                background: #0f172a;
                color: #e2e8f0;
                border-radius: 16px;
                box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.5);
                max-width: 720px;
                width: 100%;
                max-height: 90vh;
                overflow-y: auto;
                border: 1px solid rgba(148, 163, 184, 0.2);
            }
            .fixarivan-modal .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 20px 24px;
                border-bottom: 1px solid rgba(148, 163, 184, 0.15);
                background: rgba(30, 41, 59, 0.85);
            }
            .fixarivan-modal h2 {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
            }
            .fixarivan-modal .modal-close {
                background: transparent;
                border: none;
                color: #94a3b8;
                font-size: 26px;
                cursor: pointer;
            }
            .fixarivan-modal .modal-section {
                padding: 20px 24px;
                display: grid;
                gap: 16px;
                background: rgba(15, 23, 42, 0.9);
            }
            .fixarivan-modal .modal-field {
                display: grid;
                gap: 4px;
            }
            .fixarivan-modal .modal-label {
                font-size: 12px;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: #94a3b8;
            }
            .fixarivan-modal .modal-value {
                font-size: 15px;
                line-height: 1.4;
                color: #f8fafc;
                word-break: break-word;
                white-space: pre-wrap;
            }
            .fixarivan-modal input,
            .fixarivan-modal select,
            .fixarivan-modal textarea {
                width: 100%;
                background: rgba(30, 41, 59, 0.7);
                border: 1px solid rgba(148, 163, 184, 0.25);
                border-radius: 10px;
                padding: 10px 14px;
                color: #e2e8f0;
                font-size: 14px;
            }
            .fixarivan-modal textarea {
                resize: vertical;
            }
            .fixarivan-modal .modal-actions {
                display: flex;
                gap: 12px;
                padding: 20px 24px;
                border-top: 1px solid rgba(148, 163, 184, 0.12);
                background: rgba(15, 23, 42, 0.95);
                justify-content: flex-end;
            }
            .fixarivan-modal .btn {
                border-radius: 12px;
                padding: 10px 18px;
                border: 1px solid rgba(148, 163, 184, 0.3);
                background: rgba(15, 23, 42, 0.6);
                color: #f8fafc;
                cursor: pointer;
                transition: background 0.2s ease, transform 0.2s ease;
            }
            .fixarivan-modal .btn:hover {
                background: rgba(59, 130, 246, 0.2);
                transform: translateY(-1px);
            }
            .fixarivan-modal .btn.primary {
                background: linear-gradient(135deg, #3b82f6, #6366f1);
                border: none;
            }
        `;
        document.head.appendChild(style);
    }
})(window);

