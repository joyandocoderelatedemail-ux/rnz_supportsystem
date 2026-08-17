# AGENTS.md

Two separate PHP apps in one folder, no framework, no build step, no tests/lint:

- **Client Portal** (repo root): `index.php`, `tickets.php`, `ticket_detail.php`, `hardware.php`, `technotes.php`, `workorders.php`, `profile.php`, `login.php`
- **Support Center** (`backend/`): `index.php`, `tickets.php`, `ticket_detail.php`, `accounts.php`, `inventory.php`, `maintenance.php`, `login.php` + `hardware_logs.php`/`workorders.php`/`technotes.php` (redirect stubs to `accounts.php?tab=logs|orders|notes`)

## Run / DB
- Served by XAMPP from `C:\xampp\htdocs\rnz_supportsystem`. Client portal: `http://localhost/rnz_supportsystem/`, admin: `.../backend/`.
- MySQL db `vovoco5_rnz_supportsystem` (fallback `rnz_supportsystem` for local dev), credentials `root` / empty (`includes/config.php`, `backend/includes/config.php` — duplicated; each app has its own copy plus its own `auth.php`. Do not cross-require).
- Verify edits with `php -l <file>`. There is no test suite or lint config.

## PHP compatibility (critical)
Written for **PHP 5.6**. Do NOT add modern syntax — it breaks the live server:
`array()` not `[]`, no `??`, no `?:` shorthand exceptions, no arrow functions, no named/typed params, no null-coalescing assignments, no `str_contains`, etc. Use `array_*`, plain `isset()`, and PDO prepared statements with `:named` params (always pass params in `array(...)`).

## Auth (two independent systems)
- **Client** (`includes/auth.php`): logs in against `bucket_client` using **Trade Name (or client name) + Account Number as password**. Session keys `client_logged_in` / `client_data`; guard via `require_login()`.
- **Tech/Admin** (`backend/includes/auth.php`): logs in against table `user` (column `user` + plaintext `pass`), session keys `tech_logged_in` / `tech_data`; guard via `require_tech_login()`.

## Legacy `bucket_*` / `user` tables
Owned by the external POS system. Read freely; **do not change schemas**. The backend does write to them intentionally: `bucket_technotes` (tech-log form, `backend/includes/footer.php`) and `bucket_client` (`accounts.php` profile update).

## Support tables
`includes/db_init.php` & `backend/includes/inventory_init.php` auto-create `client_support_tickets`, `client_ticket_replies`, `hardware_troubleshooting_logs`, `client_maintenance_requests`, `support_inventory_items`, `support_inventory_logs` via `CREATE TABLE IF NOT EXISTS`. Ticket numbers: `RNZ-YYYY-#####`.

## Conventions to follow
- **Page pattern**: PHP logic/data-fetch top, then a full HTML doc including `includes/sidebar.php`, `includes/header.php`, `includes/footer.php`. Set `$active_page` (sidebar highlight) and `$page_title` BEFORE the includes.
- **POST handling**: hidden `<input type="hidden" name="action" value="...">`, action checked in the PHP top, then redirect-after-POST. Match existing action names (`create_ticket`, `post_reply`, `send_tech_reply`, `update_ticket_status`, `quick_update_ticket`, `save_tech_note`, `update_client_profile`, hardware wizard: `start_troubleshoot`/`answer_question`/`step_feedback`).
- **Styling**: Tailwind via `cdn.tailwindcss.com` CDN with the `brand` palette + Plus Jakarta Sans inlined in every page head — replicate that block for new pages. Two themes: client = light orange/brown, backend = dark slate.
- **Helpers** (defined in both configs): `sanitize()` for all output, `format_date()`, `get_status_badge_class()`, `get_db_connection()`.
- **Hardware wizard**: data lives in `includes/hardware_data.php` (device keys like `thermal-printer`, with `questions`/`steps`/`common_issues`); images in `hardware_photos/`. Add new devices there, not in `hardware.php`.