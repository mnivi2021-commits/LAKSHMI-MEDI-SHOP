# Marketing CRM

Sales performance, payment collection, pending order, sample / DC, mail and SMS management for a multi-branch sales team.

> **Status:** Phase 6 – Sales Performance (Step A1): live KPI card, drill-down, month-wise chart, CSV export, mobile API.
> Built one module at a time; each phase is reviewed and approved before the next starts.

---

## Architecture

```
                 ┌──────────────────────────────┐
                 │  MySQL 8  (marketing_crm)     │  single source of truth
                 └──────────────▲───────────────┘
                                │ PDO, prepared statements
                 ┌──────────────┴───────────────┐
                 │  PHP 8 backend (this repo)    │  business rules, permissions,
                 │  app/  config/  routes/       │  KPI calculations, audit log
                 └──────▲───────────────▲───────┘
        server-rendered │               │ JSON REST  /api/*
        pages + session │               │ bearer tokens
                 ┌──────┴─────┐  ┌──────┴──────────────┐
                 │  Web CRM   │  │ Mobile app (Expo /   │  Phase 21
                 │  (browser) │  │ React Native, TS)    │
                 └────────────┘  └─────────────────────┘
```

* **One backend, one database.** The web CRM and the mobile app use the same PHP backend and MySQL database. Calculations live only in the backend, never only in the app.
* **Web UI** is server-rendered PHP with small vanilla-JS/Chart.js enhancements (Phase 5). It runs on plain XAMPP without a Node build step and keeps CSRF and session security simple. React is used where it earns its place: the Expo mobile app.
* **Transactions are the source of truth.** Dashboard numbers are `SUM()`s over invoices, receipts, order lines, samples and DCs. Nothing is a hand-maintained total, and every KPI can drill down to its source rows.
* **Provider abstractions.** Mail (IMAP / Gmail API / Microsoft Graph) and SMS gateways sit behind interfaces in `app/Services`. SMS defaults to a **mock** gateway that never sends real messages.

### Production deployment recommendation

Vercel is a serverless platform. It has no persistent disk for uploads, PHP is available only through a community runtime, PHP sessions don't persist between invocations, and it cannot host MySQL. For this system the reliable setup is:

| Part | Host |
|---|---|
| PHP backend + web CRM | Any PHP 8 host with Apache/Nginx (VPS, cPanel, AWS Lightsail, DigitalOcean) |
| MySQL 8 | Same server or a managed MySQL (e.g. AWS RDS, DigitalOcean Managed MySQL) |
| Mobile app | Expo EAS builds → Play Store / App Store, pointing at the HTTPS API URL |
| Vercel (optional) | Only a static marketing/landing site or an Expo-web build. Not the PHP backend |

Phase 25 covers the details.

---

## Project structure

```
marketing_crm/
├── app/
│   ├── Core/            Env, Config, Database (PDO), Router, Request, Response, View,
│   │                    Session, Csrf, Logger, ErrorHandler, FinancialYear, DateRange, HealthCheck
│   ├── Helpers/         functions.php: e(), url(), inr() Indian currency format
│   ├── Middleware/      auth / permission middleware            (Phase 3-4)
│   ├── Modules/         one folder per business module           (Phase 5+)
│   ├── Services/        DataIntegrity.php (cross-table rules)
│   ├── Services/Mail/   mail provider abstraction                (Phase 18)
│   ├── Services/Sms/    SMS gateway abstraction (mock first)     (Phase 19)
│   └── Views/           layouts/, errors/ (403, 404, 500), home, health
├── bootstrap/app.php    autoloader, .env, config, timezone, error handler
├── cli/
│   ├── install.php      creates schema (+ --seed demo data)
│   ├── migrate.php      applies database/migrations (--status to list)
│   ├── verify-data.php  cross-table integrity rules
│   └── health.php       terminal health check
├── config/              app, database, security, services (reads .env)
├── database/
│   ├── schema.sql       47 tables + 8 KPI views
│   ├── seed.sql         demo data (DEV ONLY)
│   ├── setup_user.sql   creates DB + least-privilege MySQL user
│   ├── migrations/      incremental changes after install (YYYY_MM_DD_HHMMSS_name.sql)
│   └── legacy/          earlier draft schema (reference only, not used)
├── docs/DATABASE.md     table map + exact SQL definition of every dashboard KPI
├── logs/                app-YYYY-MM-DD.log (not web-accessible)
├── public/              ← the ONLY web root: index.php, .htaccess, assets/
├── routes/web.php
├── tests/run.php        dependency-free tests
├── uploads/             imports/, attachments/ (not web-accessible)
├── .env.example
└── composer.json        optional; the built-in autoloader works without Composer
```

---

## Local setup (Windows + XAMPP + MySQL 8)

This machine runs the **MySQL 8.0 Windows service (`MySQL80`)** on port 3306. Use it, not XAMPP's bundled MariaDB: the schema needs MySQL 8 (JSON_TABLE, CHECK constraints, generated columns). Don't start MySQL from the XAMPP panel while `MySQL80` is running, because both use port 3306.

### 1. Create the database and the app user

Open `database/setup_user.sql`, replace `CHANGE_ME_STRONG_PASSWORD`, then run it as root:

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p < "D:\AI PROJECT\SMS SYSTEM\database\setup_user.sql"
```

(or paste it into MySQL Workbench and execute).

### 2. Configure `.env`

`.env` already exists (copied from `.env.example`, with a random `APP_KEY`). Set:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=marketing_crm
DB_USERNAME=crm_app
DB_PASSWORD=<the password from step 1>
```

### 3. Install the schema and demo data

```powershell
cd "D:\AI PROJECT\SMS SYSTEM"
C:\xampp\php\php.exe cli\install.php --seed
```

The installer refuses to run on a database that already has tables. `--fresh --seed` rebuilds from scratch and only works when `APP_ENV=local`.

### 4. Serve through XAMPP Apache

The project lives on `D:`. Apache serves it through a directory junction that points **only at `public/`**:

```
C:\xampp\htdocs\marketing_crm  →  D:\AI PROJECT\SMS SYSTEM\public
```

(created with `mklink /J C:\xampp\htdocs\marketing_crm "D:\AI PROJECT\SMS SYSTEM\public"`). Start **Apache** in the XAMPP Control Panel, then open:

* http://localhost/marketing_crm/: home
* http://localhost/marketing_crm/health: system health page
* http://localhost/marketing_crm/api/health: JSON health

### 5. Verify from the terminal

```powershell
C:\xampp\php\php.exe tests\run.php     # financial-year, currency, password policy, redirect tests
C:\xampp\php\php.exe cli\health.php    # same checks as /health
C:\xampp\php\php.exe cli\verify-data.php   # cross-table data integrity rules
C:\xampp\php\php.exe cli\migrate.php --status
C:
mppphpphp.exe testsdb.php      # permissions + data scope (seeded DB, rolled back)
bash tests/e2e/auth.sh                 # web + API sign-in flow   (needs a FRESHLY seeded local DB)
bash tests/e2e/access.sh               # users, roles, enforcement (needs a FRESHLY seeded local DB)
C:
mpp\php\php.exe tests\dashboard.php   # dashboard context + quick ADD (seeded DB, rolled back)
bash tests/e2e/dashboard.sh            # dashboard, scoped lookups, quick ADD over HTTP (FRESH seed)
C:
mpp\php\php.exe tests\sales_kpi.php   # Step A1 figures vs independent raw SQL (rolled back)
bash tests/e2e/sales.sh                # Step A1 card, drill-down, CSV, API (FRESH seed)
```

### 6. Open it from phones and laptops on the same Wi-Fi

1. Run once in PowerShell **as Administrator**:
   `powershell -ExecutionPolicy Bypass -File "D:\AI PROJECT\SMS SYSTEM\tools\allow-wifi-access.ps1"`
   This allows port 80 only on **Private** networks and only from the **local subnet**.
2. On the other device, open `http://<this PC's IP>/marketing_crm/`. The script prints the address, e.g. `http://192.168.29.12/marketing_crm/`.
3. Reserve the PC's IP in the Wi-Fi router (DHCP reservation) so the address stays the same.

Plain HTTP on the office Wi-Fi is fine for development. Production must use HTTPS (Phase 25).

### Three ways to use the CRM

| Way | Address | Status |
|---|---|---|
| Office PC browser | `http://localhost/marketing_crm/` | ✅ |
| Any device on office Wi-Fi | `http://<PC-IP>/marketing_crm/` | ✅ after step 6 |
| Mobile app (Expo, React Native) | API `http://<PC-IP>/marketing_crm/api/` | API sign-in ready; app in Phase 21 |

The public page at `/` introduces the system and links to **Sign in**.

### Demo logins (seed data, must change on first login)

| Username | Password | Role |
|---|---|---|
| admin | Admin@2026 | Admin Head (full access) |
| coordinator | Coord@2026 | Admin Coordinator (partial) |
| jana | Sales@2026 | Sales Executive (own data) |

**Never load `seed.sql` into production.** The installer blocks `--seed` when `APP_ENV=production`.

---

## Authentication

| Rule | Detail |
|---|---|
| Sign in | Username **or** email + password, CSRF-protected form; new session id on login |
| Wrong credentials | Always "Incorrect username or password." (does not reveal whether the user exists) |
| Brute force | 5 failures in 15 min locks that username; 20 failures locks the IP (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_MINUTES`) |
| First login | Seeded / admin-reset accounts must set their own password before anything else |
| Password policy | 10+ characters, letters and numbers, not username/email, not a common or demo password |
| Session | HttpOnly, SameSite=Lax cookie; 120 min idle timeout; 12 h absolute limit |
| Password change | Signs out all other browsers and revokes all mobile tokens |
| Disabled user | Signed out on their next click |
| Sign out | POST + CSRF (a link cannot sign you out) |
| Forgot password | Admin Head resets it in User Management (Phase 4). Email reset comes once mail is connected |
| Audit | `login`, `login.failed`, `login.throttled`, `login.blocked`, `account.locked`, `logout`, `password.changed` |

### API (mobile app)

```
POST /api/auth/login    {"username":"jana","password":"…","device_name":"Jana's phone"}
                        → {"data":{"token":"…","token_type":"Bearer","expires_at":"…","user":{…}}}
GET  /api/auth/me       Authorization: Bearer <token>
POST /api/auth/logout   Authorization: Bearer <token>   (revokes the token)
```

Tokens are random 256-bit values. Only their SHA-256 is stored, and they expire after `API_TOKEN_TTL_DAYS` (30). The API sets no cookies. Error codes: 401 invalid credentials or token, 403 `password_change_required` / `account_disabled`, 429 too many attempts (with `Retry-After`). CORS is allowed only for `API_ALLOWED_ORIGINS`.

## Dashboard

* **Filters:** Financial Year · Month · Branch · Sales Employee · Customer · Product. They are GET parameters, so every view is a shareable link. Every filter is re-checked against the user's data scope, and a tampered URL is ignored and explained.
* **As-on date:** the current FY or month uses **today** ("Live"). A past month uses its last day, and a past FY uses 31 March ("Closed period"). All KPI windows (FY to previous day, month to previous day, today) are measured from this date and never overlap.
* **+ ADD** (right of the header) opens a side panel to enter:
  * a **Sales Target** for one month, or a yearly amount split exactly over 12 months (re-saving replaces it and needs `targets.edit`)
  * a **Sale** (GST from the product, due date from the customer's credit days)
  * a **Collection**, adjusted against the customer's oldest open bills and kept on account if larger
  * a **Pending Order**, **Sample** or **DC**

  Each tab appears only with its `*.add` permission, and the server re-checks permission, data scope, dates (no future dates, financial year not locked), duplicate document numbers and amounts. Amounts use exact integer paise.
* **Latest entries in your view** shows how current the data is before you trust a number.

### Step A1 – Sales Performance

The card shows: annual target, sales as on previous day (FY start → yesterday), this month as on previous day, today, total (= previous day + today, never double-counted), target achieved %, target pending (or exceeded by), average monthly sales (completed months) and required monthly sales (pending ÷ remaining months).

* Sales are **net of credit notes**. Cancelled and deleted invoices never count. The basis is taxable value, or total incl. GST via setting `finance.sales_amount_basis`.
* Targets are per employee, so a **customer or product filter shows sales without a target**. A product filter uses invoice line items.
* **Every amount is a link.** **View details** (`/dashboard/sales`) shows a month-wise target vs sales chart and table, breakdowns by branch and by employee, and the invoices and credit notes behind each window. Each window is labelled **matches dashboard** when its list adds up to the card.
* **Export CSV** (needs `sales.export`) contains every document in the window plus a total row. It is protected against spreadsheet formula injection, and the export is audited.
* **Mobile:** `GET /api/dashboard/sales` (Bearer token) returns the same figures, filters and data scope.

## Roles, permissions & data scope

**Permissions** (`module.action`, e.g. `sales.view`, `users.delete`). A user's effective permissions are:

> role permissions **+** per-user *grant* overrides **−** per-user *deny* overrides

* **Admin Head** always has every permission. The role can't be edited, so a matrix mistake can never lock the system.
* **Admin Head** sets the matrix per role at **Access → Roles & permissions**. This is where the Admin Coordinator's access is configured. Exceptions for one person go under **Access → Users → Permission overrides**.
* **Enforcement** happens on the server for every route (`can:` middleware → 403), not just by hiding buttons. The menu shows only modules the user can open.
* **Escalation guards:**
  * nobody can grant, or manage a user who has, permissions they don't hold themselves
  * only an Admin Head can assign the Admin Head role
  * nobody can change their own role, disable or delete themselves, or remove the last active Admin Head

**Data scope** (`roles.data_scope`) decides *whose records* a user sees. Every dashboard, list, report and API query applies `App\Core\DataScope`:

| Scope | Sees | Needs |
|---|---|---|
| all | every branch and employee | – |
| branch | branches ticked on the user | ≥ 1 branch |
| team | own employee + everyone reporting to them (whole tree) | linked employee |
| own | own employee's records only | linked employee |

A user whose scope can't be resolved sees **nothing**, never everything.

**User management:** add (temporary password shown once), edit, enable/disable (signs out immediately and revokes mobile tokens), reset password, unlock, and delete (soft delete).

Every change is audited: `user.created`, `user.updated`, `user.disabled`, `user.enabled`, `user.password_reset`, `user.unlocked`, `user.deleted`, `role.created`, `role.updated`, `role.deleted`, `permission.changed`, `user_permission.changed`.

## Key business rules built into the foundation

* **Financial year** is computed from any date (`App\Core\FinancialYear`) with a configurable start month (April by default). Nothing is hard-coded to FY 2026-27.
* **Dashboard windows never overlap**, so today is never counted twice. On 06-10-2026:
  * FY to previous day: 01-04-2026 to 05-10-2026
  * Month to previous day: 01-10-2026 to 05-10-2026
  * Today: 06-10-2026
* **Targets** are stored per employee per month. Annual target = sum of months. Branch target = sum of its employees.
* **Pending value** is a generated column (`order_value − supplied_value`), so it cannot drift.
* **Outstanding** is computed as invoice − allocated receipts (default), or taken from an imported bill-wise ERP statement (`settings: outstanding.source`). Aging buckets are 0-30 / 31-60 / 61-90 / 91-150 / 150+.
* **Money** is `DECIMAL(15,2)` and is formatted in Indian grouping (₹10,00,000.00) without float conversion.
* Transactions store **branch and employee snapshots** plus `import_batch_id`, so every figure traces back to its source.

## Security baseline

PDO real prepared statements · STRICT sql_mode · `password_hash()` (bcrypt) · hardened sessions (HttpOnly, SameSite, strict mode, idle timeout) · CSRF tokens · output escaping via `e()` · CSP and security headers · web root limited to `public/` · only `index.php` executable · uploads/logs/.env outside the web root · generic 403/404/500 pages with an error reference, details only in `logs/` · secrets redacted in logs · least-privilege MySQL user.

## Git conventions

`feat: …`, `fix: …`, `refactor: …`, `docs: …`, `chore: …`. Never commit `.env`, real passwords, API keys or database dumps (already in `.gitignore`).
