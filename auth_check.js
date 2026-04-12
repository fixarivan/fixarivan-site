/**
 * FixariVan — UI helpers only. Authorization for admin is PHP session (admin/login.php).
 * Never treat localStorage as proof of access; sensitive APIs enforce require_admin_session.php.
 *
 * Access tiers:
 * - PUBLIC: static pages, health, manifest
 * - TOKEN-ONLY: order_view.php, receipt_view.php, report_view.php (token in URL)
 * - ADMIN: session cookie after admin/login.php — api/save_* (master), dashboard, track, inventory, etc.
 */

const AuthManager = {
    DISPLAY_NAME_KEY: 'fixarivan_display_name',

    /** UI-only: page may render without implying server auth */
    checkAuth() {
        return true;
    },

    /**
     * Legacy hook — does not validate password. Real login is admin/login.php.
     * Kept so old inline handlers do not throw; prefer redirect to admin/login.php.
     */
    login(username, _password) {
        const name = (username || '').trim();
        try {
            if (name) {
                localStorage.setItem(this.DISPLAY_NAME_KEY, name);
                localStorage.setItem('fixarivan_username', name);
            }
        } catch (e) {
            /* ignore */
        }
        return true;
    },

    logout() {
        try {
            localStorage.removeItem(this.DISPLAY_NAME_KEY);
            localStorage.removeItem('fixarivan_username');
            localStorage.removeItem('fixarivan_login_time');
        } catch (e) {
            /* ignore */
        }
        if (typeof showAuthModal === 'function') {
            showAuthModal();
        }
    },

    getUsername() {
        try {
            return localStorage.getItem('fixarivan_username') || localStorage.getItem(this.DISPLAY_NAME_KEY) || 'Guest';
        } catch (e) {
            return 'Guest';
        }
    },

    getAuthToken() {
        return null;
    },

    checkSession() {
        return true;
    },
};

function fixarivanRedirectToAdminLogin() {
    window.location.href = 'admin/login.php?next=' + encodeURIComponent('../' + (window.location.pathname.split('/').pop() || 'index.php'));
}

document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        if (!window.FIXARIVAN_REQUIRE_ADMIN_SESSION) {
            AuthManager.checkAuth();
            return;
        }
        fetch('api/admin_session_status.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                return r.json();
            })
            .then(function (j) {
                if (!j || !j.ok) {
                    fixarivanRedirectToAdminLogin();
                    return;
                }
                try {
                    if (j.username) {
                        localStorage.setItem('fixarivan_username', j.username);
                        localStorage.setItem('fixarivan_display_name', j.username);
                    }
                } catch (e) {
                    /* ignore */
                }
                AuthManager.checkAuth();
            })
            .catch(function () {
                fixarivanRedirectToAdminLogin();
            });
    }, 100);
});

window.AuthManager = AuthManager;
