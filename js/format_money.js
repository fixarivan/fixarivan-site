/**
 * EUR display by UI language (matches api/lib/format_money.php / dt_format_currency).
 * FI / RU: 300,00 €
 * EN: €300.00
 *
 * @param {number|string} amount
 * @param {'ru'|'fi'|'en'|string} [lang='ru']
 * @returns {string}
 */
function formatMoney(amount, lang) {
    const n = Number(amount);
    const a = Number.isFinite(n) ? n : 0;
    const l = String(lang == null ? 'ru' : lang).toLowerCase();
    const isEn = l === 'en';
    const s = a.toFixed(2);
    const parts = s.split('.');
    // Match PHP number_format(..., 2, '.', ' '): space as thousands separator.
    const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    if (isEn) {
        return '\u20AC' + intPart + '.' + parts[1];
    }
    return intPart + ',' + parts[1] + ' \u20AC';
}
