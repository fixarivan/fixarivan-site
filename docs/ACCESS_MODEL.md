# FixariVan — access model (stabilization)

## PUBLIC

- Static assets and HTML that do not expose private data.
- `api/health.php`, `api/index.php` (endpoint catalog), `api/admin_session_status.php` (session probe).
- Client viewer pages: `order_view.php`, `receipt_view.php`, `report_view.php` — access is **token in URL** (TOKEN-ONLY), not admin session.

## TOKEN-ONLY

- Viewer flows: document actions allowed only with a valid `client_token` / `token` issued for that document.
- `api/save_order_fixed.php` — **client** updates (`isMasterForm` false): token-based; **not** admin session.

## ADMIN

- After `admin/login.php`, PHP session cookie (`admin_logged_in`).
- Admin HTML: served via `index.php` (dashboard) or `auth_check.js` + `FIXARIVAN_REQUIRE_ADMIN_SESSION` + `api/admin_session_status.php`.
- APIs: `api/lib/require_admin_session.php` — `save_*` (master), `get_*` lists, `delete_*`, `update_*`, inventory, `company_profile.php`, `notes.php` (заметки мастера в SQLite), etc.

## PDF (`api/generate_dompdf_fixed.php`)

- **ADMIN** session, or **TOKEN-ONLY**: request must include `clientToken` matching the document row in SQLite (`fixarivan_pdf_generation_allowed` in `api/lib/pdf_request_auth.php`).

**Do not** use `localStorage` flags as authorization; they are UI-only (display name).
