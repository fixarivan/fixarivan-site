/**
 * Подставляет загруженный в настройках логотип (PNG и др.) вместо стандартного знака + текста.
 */
(function () {
    'use strict';

    function applyBrandLogo(logoUrl) {
        if (!logoUrl) return;
        var url = String(logoUrl).replace(/^\.\//, '');
        if (!/^assets\//.test(url) && !/^https?:\/\//.test(url)) {
            url = './' + url;
        } else if (!/^https?:\/\//.test(url)) {
            url = './' + url.replace(/^\.\//, '');
        }

        document.querySelectorAll('[data-fv-brand-logo]').forEach(function (host) {
            var custom = host.querySelector('.fv-brand-logo-custom');
            if (!custom) return;
            custom.src = url;
            custom.removeAttribute('hidden');
            custom.classList.remove('is-hidden');
            host.classList.add('has-custom-brand-logo');
            host.querySelectorAll('.fv-brand-default').forEach(function (el) {
                el.classList.add('is-hidden');
                el.setAttribute('aria-hidden', 'true');
            });
            custom.removeAttribute('aria-hidden');
        });
    }

    function loadBrandLogo() {
        fetch('./api/company_brand.php', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) return;
                var data = j.data && typeof j.data === 'object' ? j.data : j;
                var logoUrl = data.logo_url || '';
                if (logoUrl) applyBrandLogo(logoUrl);
            })
            .catch(function () { /* стандартный логотип */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadBrandLogo);
    } else {
        loadBrandLogo();
    }
})();
