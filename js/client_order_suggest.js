(function () {
    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatPhoneDisplay(p) {
        if (!p) {
            return '—';
        }
        var d = String(p).replace(/\D/g, '');
        if (d.length > 3 && d.indexOf('358') === 0) {
            return '+358 ' + d.slice(3);
        }
        if (d.length) {
            return '+' + d;
        }
        return p;
    }

    function debounce(fn, ms) {
        var t = 0;
        return function () {
            var args = arguments;
            var self = this;
            clearTimeout(t);
            t = setTimeout(function () {
                fn.apply(self, args);
            }, ms);
        };
    }

    function attachDropdown(inputEl) {
        var parent = inputEl.parentNode;
        var wrap = parent;
        if (!wrap.classList || !wrap.classList.contains('client-suggest-wrap')) {
            wrap = document.createElement('div');
            wrap.className = 'client-suggest-wrap';
            parent.insertBefore(wrap, inputEl);
            wrap.appendChild(inputEl);
        }
        var list = wrap.querySelector('.client-suggest-dropdown');
        if (!list) {
            list = document.createElement('div');
            list.className = 'client-suggest-dropdown';
            list.setAttribute('role', 'listbox');
            list.hidden = true;
            wrap.appendChild(list);
        }
        return { wrap: wrap, list: list };
    }

    function initClientOrderSuggest() {
        var nameEl = document.getElementById('clientName');
        var phoneEl = document.getElementById('clientPhone');
        var emailEl = document.getElementById('clientEmail');
        if (!nameEl || !phoneEl) {
            return;
        }

        var api = './api/client_suggest.php';
        var nameDd = attachDropdown(nameEl);
        var phoneDd = attachDropdown(phoneEl);

        var activeTarget = null;
        var activeIdx = -1;
        var lastItems = [];
        var hideTimer = 0;

        function hideList(dd) {
            if (!dd) {
                return;
            }
            dd.list.hidden = true;
            dd.list.innerHTML = '';
            if (dd === activeTarget) {
                activeIdx = -1;
                lastItems = [];
            }
        }

        function hideAll() {
            hideList(nameDd);
            hideList(phoneDd);
            activeTarget = null;
        }

        function showList(dd, items) {
            if (!items || !items.length) {
                hideList(dd);
                return;
            }
            if (dd === nameDd) {
                hideList(phoneDd);
            }
            if (dd === phoneDd) {
                hideList(nameDd);
            }
            activeTarget = dd;
            lastItems = items;
            activeIdx = -1;
            dd.list.innerHTML = items
                .map(function (c, i) {
                    return (
                        '<button type="button" class="client-suggest-item" role="option" data-i="' +
                        i +
                        '">' +
                        '<span class="client-suggest-line-name">' +
                        escHtml(c.full_name || '') +
                        '</span>' +
                        '<span class="client-suggest-line-meta">' +
                        escHtml(formatPhoneDisplay(c.phone)) +
                        (c.email ? ' · ' + escHtml(c.email) : '') +
                        '</span></button>'
                    );
                })
                .join('');
            dd.list.hidden = false;
        }

        function applyClient(c) {
            if (c.full_name) {
                nameEl.value = c.full_name;
            }
            if (c.phone) {
                phoneEl.value = c.phone;
            }
            if (emailEl && c.email) {
                emailEl.value = c.email;
            }
            ['input', 'change'].forEach(function (ev) {
                nameEl.dispatchEvent(new Event(ev, { bubbles: true }));
                phoneEl.dispatchEvent(new Event(ev, { bubbles: true }));
                if (emailEl) {
                    emailEl.dispatchEvent(new Event(ev, { bubbles: true }));
                }
            });
            hideAll();
        }

        var fetchSuggest = debounce(function (raw, dd) {
            var q = String(raw || '').trim();
            if (q.length < 2) {
                hideList(dd);
                return;
            }
            fetch(api + '?q=' + encodeURIComponent(q) + '&limit=12', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (r) {
                    return r.json();
                })
                .then(function (j) {
                    if (!j || j.success !== true) {
                        hideList(dd);
                        return;
                    }
                    var data = j.data && typeof j.data === 'object' ? j.data : {};
                    var clients = data.clients || j.clients || [];
                    showList(dd, clients);
                })
                .catch(function () {
                    hideList(dd);
                });
        }, 280);

        function scheduleHide() {
            if (hideTimer) {
                clearTimeout(hideTimer);
            }
            hideTimer = window.setTimeout(function () {
                hideAll();
            }, 200);
        }

        function cancelHide() {
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = 0;
            }
        }

        nameEl.setAttribute('autocomplete', 'off');
        phoneEl.setAttribute('autocomplete', 'off');

        nameEl.addEventListener('input', function () {
            cancelHide();
            fetchSuggest(nameEl.value, nameDd);
        });
        phoneEl.addEventListener('input', function () {
            cancelHide();
            fetchSuggest(phoneEl.value, phoneDd);
        });

        nameEl.addEventListener('focus', function () {
            cancelHide();
            if (String(nameEl.value || '').trim().length >= 2) {
                fetchSuggest(nameEl.value, nameDd);
            }
        });
        phoneEl.addEventListener('focus', function () {
            cancelHide();
            if (String(phoneEl.value || '').trim().length >= 2) {
                fetchSuggest(phoneEl.value, phoneDd);
            }
        });

        nameEl.addEventListener('blur', scheduleHide);
        phoneEl.addEventListener('blur', scheduleHide);

        [nameDd, phoneDd].forEach(function (dd) {
            dd.list.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });
            dd.list.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('.client-suggest-item') : null;
                if (!btn) {
                    return;
                }
                var i = parseInt(btn.getAttribute('data-i'), 10);
                if (!lastItems[i]) {
                    return;
                }
                applyClient(lastItems[i]);
            });
        });

        function moveHighlight(dd, delta) {
            if (!dd || dd.list.hidden || !lastItems.length) {
                return;
            }
            var items = dd.list.querySelectorAll('.client-suggest-item');
            if (!items.length) {
                return;
            }
            activeIdx += delta;
            if (activeIdx < 0) {
                activeIdx = items.length - 1;
            }
            if (activeIdx >= items.length) {
                activeIdx = 0;
            }
            items.forEach(function (el, idx) {
                el.classList.toggle('active', idx === activeIdx);
            });
        }

        function onKeydown(e, dd) {
            if (!dd || dd.list.hidden) {
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                moveHighlight(dd, 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                moveHighlight(dd, -1);
            } else if (e.key === 'Enter' && activeIdx >= 0 && lastItems[activeIdx]) {
                e.preventDefault();
                applyClient(lastItems[activeIdx]);
            } else if (e.key === 'Escape') {
                hideList(dd);
            }
        }

        nameEl.addEventListener('keydown', function (e) {
            onKeydown(e, nameDd);
        });
        phoneEl.addEventListener('keydown', function (e) {
            onKeydown(e, phoneDd);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClientOrderSuggest);
    } else {
        initClientOrderSuggest();
    }
})();
