(function () {
    function pickInput(ids) {
        for (var i = 0; i < ids.length; i += 1) {
            var el = document.getElementById(ids[i]);
            if (el) return el;
        }
        return null;
    }

    function setIfEmpty(el, value) {
        if (!el || !value) return;
        if (String(el.value || '').trim() !== '') return;
        el.value = String(value);
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function initClientAutofill() {
        var phoneEl = pickInput(['clientPhone', 'phone']);
        var emailEl = pickInput(['clientEmail', 'email']);
        var nameEl = pickInput(['clientName', 'name']);
        var modelEl = pickInput(['deviceModel', 'model', 'computerModel']);
        var problemEl = pickInput(['problemDescription', 'deviceProblem', 'diagnosis']);
        var orderEl = pickInput(['orderId', 'order_id']);

        function fillFromQuery() {
            var params = new URLSearchParams(window.location.search || '');
            var qName = (params.get('client_name') || '').trim();
            var qPhone = (params.get('client_phone') || '').trim();
            var qEmail = (params.get('client_email') || '').trim();
            var qModel = (params.get('device_model') || '').trim();
            var qProblem = (params.get('problem') || '').trim();
            var qOrder = (params.get('order_id') || '').trim();

            setIfEmpty(nameEl, qName);
            setIfEmpty(phoneEl, qPhone);
            setIfEmpty(emailEl, qEmail);
            setIfEmpty(modelEl, qModel);
            setIfEmpty(problemEl, qProblem);
            setIfEmpty(orderEl, qOrder);
        }
        fillFromQuery();

        if (!phoneEl && !emailEl) return;

        var timer = 0;
        function lookup() {
            var phone = phoneEl ? String(phoneEl.value || '').trim() : '';
            var email = emailEl ? String(emailEl.value || '').trim() : '';
            if (phone === '' && email === '') return;

            var params = new URLSearchParams();
            if (phone !== '') params.set('phone', phone);
            if (email !== '') params.set('email', email);

            fetch('./api/client_lookup.php?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || j.success !== true) return;
                    var data = (j.data && typeof j.data === 'object') ? j.data : {};
                    var latest = (data.latest_order && typeof data.latest_order === 'object') ? data.latest_order : {};

                    setIfEmpty(nameEl, latest.client_name);
                    setIfEmpty(modelEl, latest.device_model);
                    setIfEmpty(problemEl, latest.problem_description);
                    setIfEmpty(orderEl, latest.order_id);
                })
                .catch(function () {
                    // Silent fallback: never block old flows.
                });
        }

        function scheduleLookup() {
            if (timer) {
                clearTimeout(timer);
            }
            timer = window.setTimeout(lookup, 220);
        }

        if (phoneEl) {
            phoneEl.addEventListener('blur', scheduleLookup);
            phoneEl.addEventListener('change', scheduleLookup);
        }
        if (emailEl) {
            emailEl.addEventListener('blur', scheduleLookup);
            emailEl.addEventListener('change', scheduleLookup);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClientAutofill);
    } else {
        initClientAutofill();
    }
})();
