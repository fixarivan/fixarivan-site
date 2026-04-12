/**
 * Единая привязка квитанций / отчётов / счёта к заказу (document_id + order_id + client_token в URL).
 */
(function (global) {
    'use strict';

    function sanitize(s) {
        var t = String(s || '').replace(/[^a-zA-Z0-9_-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
        return t || 'order';
    }

    function parseQuery() {
        var p = new URLSearchParams(window.location.search || '');
        return {
            documentId: (p.get('document_id') || '').trim(),
            orderId: (p.get('order_id') || '').trim(),
            clientToken: (p.get('client_token') || '').trim(),
            clientName: (p.get('client_name') || '').trim(),
            clientPhone: (p.get('client_phone') || '').trim(),
            clientEmail: (p.get('client_email') || '').trim(),
            deviceModel: (p.get('device_model') || '').trim(),
            problemDescription: (p.get('problem_description') || p.get('problem') || '').trim()
        };
    }

    function hasOrderBinding(ctx) {
        return ctx.documentId !== '' && ctx.orderId !== '';
    }

    function receiptDocId(orderDocId) {
        return 'RCT-' + sanitize(orderDocId);
    }

    function reportDocId(orderDocId) {
        return 'PR-' + sanitize(orderDocId);
    }

    function invoiceDocId(orderDocId) {
        return 'INV-' + sanitize(orderDocId);
    }

    global.FixariVanOrderDoc = {
        parseQuery: parseQuery,
        hasOrderBinding: hasOrderBinding,
        sanitize: sanitize,
        receiptDocId: receiptDocId,
        reportDocId: reportDocId,
        invoiceDocId: invoiceDocId
    };
})(typeof window !== 'undefined' ? window : globalThis);
