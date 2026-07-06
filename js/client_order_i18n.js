/**
 * Язык клиента при создании заказа (ru / fi / en).
 * Админский UI остаётся на русском; словарь — для вставки текста и клиентской части.
 */
(function (global) {
    'use strict';

    const STORAGE_KEY = 'fixarivan_order_client_lang_v1';
    const LANGS = ['ru', 'fi', 'en'];

    const DEVICE_TYPES = {
        phone: { ru: 'Телефон', fi: 'Puhelin', en: 'Phone' },
        laptop: { ru: 'Ноутбук', fi: 'Kannettava tietokone', en: 'Laptop' },
        tablet: { ru: 'Планшет', fi: 'Tabletti', en: 'Tablet' },
        console: { ru: 'Консоль', fi: 'Pelikonsoli', en: 'Game console' },
        other: { ru: 'Другое', fi: 'Muu', en: 'Other' },
    };

    function normalizeLang(value) {
        const v = String(value || 'ru').toLowerCase().trim();
        return LANGS.indexOf(v) !== -1 ? v : 'ru';
    }

    function getClientLang() {
        try {
            return normalizeLang(localStorage.getItem(STORAGE_KEY) || 'ru');
        } catch (e) {
            return 'ru';
        }
    }

    function setClientLang(lang) {
        const l = normalizeLang(lang);
        try {
            localStorage.setItem(STORAGE_KEY, l);
        } catch (e) { /* ignore */ }
        const sel = document.getElementById('clientLanguage');
        if (sel && sel.value !== l) {
            sel.value = l;
        }
        global.dispatchEvent(new CustomEvent('fixarivan:client-lang-change', { detail: { lang: l } }));
        return l;
    }

    function getDeviceTypeLabel(code, lang) {
        const key = String(code || '').toLowerCase().trim();
        const l = normalizeLang(lang || getClientLang());
        const row = DEVICE_TYPES[key];
        if (row && row[l]) {
            return row[l];
        }
        return String(code || '').trim();
    }

    function bindLanguageSelect(selectId) {
        const sel = document.getElementById(selectId || 'clientLanguage');
        if (!sel || sel.dataset.i18nBound === '1') {
            return sel;
        }
        sel.dataset.i18nBound = '1';
        sel.value = getClientLang();
        sel.addEventListener('change', function () {
            setClientLang(sel.value);
        });
        return sel;
    }

    function init(options) {
        bindLanguageSelect(options && options.selectId);
        return getClientLang();
    }

    global.FixariVanClientOrderI18n = {
        LANGS,
        DEVICE_TYPES,
        normalizeLang,
        getClientLang,
        setClientLang,
        getDeviceTypeLabel,
        bindLanguageSelect,
        init,
    };
})(typeof window !== 'undefined' ? window : globalThis);
