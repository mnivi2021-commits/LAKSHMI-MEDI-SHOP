# Marketing CRM

Sales performance, payment collection, pending orders, samples & DC, outstanding (90 / 150 days),
leads, mail and SMS for a multi-branch sales team. **Web CRM + mobile app**, one PHP backend, one MySQL database.

> **Status:** all 25 phases built. Screenshots of every screen are in [`screenshots/`](screenshots/).
> Guides: [User guide](docs/USER_GUIDE.md) · [Deployment](docs/DEPLOYMENT.md) · [Decisions & defaults](docs/DECISIONS.md) · [Database & KPI definitions](docs/DATABASE.md) · [Mobile app](mobile/README.md)

**The rule behind every screen:** numbers must be accurate and traceable. Every dashboard figure is a
`SUM()` over source documents (invoices, receipts, order lines, samples, DCs). Each figure is a link
to the records behind it. Reports, Sales Details and the mobile app use the same calculations,
and automated tests prove they agree.

---

## What it does

| Module | Highlights |
|---|---|
| **Dashboard** | Sales vs target, payment collection, branch pending orders, outstanding 90 DAYS / 150 DAYS, Email categories (New Enquiry, Order, New Lead, Payment Advice, Other), sales-representative panel, customer / product panels; filters FY · month · branch · employee · customer · product; quick **+ ADD** for targets, sales, collections, orders, samples, DC |
| **Sales Details** | One grid by employee / branch / month: target, sales, achieved %, collection, pending, samples, DC, outstanding, 91-150, 150+ (totals = dashboard) |
| **Excel Upload** | `.xlsx` / CSV for pending orders, samples, DC, payments, sales invoices, outstanding statement, customers, leads: templates, column matching, row-by-row check, preview, one-transaction import, problem-rows report |
| **Customers / Products / Branches / Leads** | Masters with codes, scope, export; lead pipeline, follow-ups, lead → customer conversion |
| **HRM** | Employees (auto code, reporting manager, sales-rep flag, resignation disables login), departments, designations |
| **Mail** | Inbox sorted by keyword rules with confidence; correction, assignment, status, link customer, create lead; IMAP or manual entry |
| **SMS** | Templates, single send with preview, campaigns (customers / leads / employees, opt-out, scheduling), history with gateway log; **test gateway by default** |
| **Reports** | 12 reports (sales register, by customer / product, target vs achievement, collection register, outstanding ageing, pending orders, samples & DC, lead conversion, follow-ups due, SMS usage, email summary) with Excel / CSV |
| **Access** | Users, roles, permission matrix, per-user overrides, data scope (all / branch / team / own) |
| **Settings & Audit** | Company name, sales basis (excl./incl. GST), outstanding source & ageing, financial years (lock), read-only audit log |
| **Mobile app** | Expo app: dashboard, customers + open bills, leads & follow-ups due; also a phone-browser version at `/app/` |

## Three ways to use it

| Way | Address |
|---|---|
| Office PC browser | `http://localhost/marketing_crm/` |
| Any phone / laptop on office Wi-Fi | `http://<PC-IP>/marketing_crm/` (e.g. `http://192.168.29.12/marketing_crm/`) after running `tools/allow-wifi-access.ps1` |
| Mobile app | Expo app (see [mobile/README.md](mobile/README.md)) or phone browser `http://<PC-IP>/marketing_crm/app/` |

The public page at `/` introduces the system and links to **Sign in**.

---

## Architecture

```
                 ┌──────────────────────────────┐
                 │  MySQL 8  (marketing_crm)     │  single source of truth (47 tables, 8 KPI views)
                 └──────────────▲───────────────┘
                                │ PDO, prepared statements
                 ┌──────────────┴───────────────┐
                 │  PHP 8 backend (this repo)    │  business rules, permissions, data scope,
                 │  app/  config/  routes/       │  KPI calculations, audit log
                 └──────▲───────────────▲───────┘
        server-rendered │               │ JSON REST /api/*  (Bearer tokens)
        pages + session │               │
                 ┌──────┴─────┐  ┌──────┴──────────────┐
                 │  Web CRM   │  │ Mobile app (Expo,    │
                 │  (browser) │  │ React Native, TS)    │
                 └────────────┘  └─────────────────────┘
```

* No framework and no Composer dependencies: a small custom MVC (`app/Core`) that runs on plain XAMPP.
* Calculations live only in the backend (`app/Modules/Dashboard/Kpi`); the app and reports reuse them.
* Mail and SMS providers sit behind interfaces in `app/Services`. Secrets come only from `.env`.

```
app/Core/            Env, Config, Database, Router, Request, Response, View, Session, Csrf, Auth, Gate,
                     DataScope, Audit, Money (integer paise), FinancialYear, Settings, Spreadsheet/ (xlsx without ext-zip)
app/Modules/         Dashboard (+Kpi), Sales, Imports, Customers, Products, Branches, Leads, Hrm, Mail, Sms,
                     Reports, Settings, Access, Auth, Api
app/Services/        Mail (IMAP / manual), Sms (test gateway)
app/Views/           server-rendered pages (CSP: no inline scripts or styles)
cli/                 install, create-admin, migrate, verify-data, health, mail-sync, sms-dispatch
database/            schema.sql, base.sql (roles, permissions, settings), seed.sql (DEMO ONLY), setup_user.sql, migrations/
mobile/              Expo app
public/              the ONLY web root (index.php, assets/, app/ = phone-browser build)
tests/               unit/accuracy tests (*.php), end-to-end suites (e2e/*.sh), run-all.sh
tools/               allow-wifi-access.ps1
```

---

## Local setup (Windows + XAMPP + MySQL 8)

Use the **MySQL 8.0 Windows service (`MySQL80`)** on port 3306, not XAMPP's MariaDB. The schema needs
MySQL 8 features (JSON_TABLE, CHECK constraints, generated columns). Don't start XAMPP's MySQL while `MySQL80` runs.

1. **Database user:** open `database/setup_user.sql`, replace `CHANGE_ME_STRONG_PASSWORD`, run it as root:
   ```powershell
   & "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p < "D:\AI PROJECT\SMS SYSTEM\database\setup_user.sql"
   ```
2. **`.env`:** set `DB_PORT=3306`, `DB_USERNAME=crm_app`, `DB_PASSWORD=<from step 1>` (copy from `.env.example` if missing; keep the random `APP_KEY`).
3. **Install:**
   ```powershell
   cd "D:\AI PROJECT\SMS SYSTEM"
   C:\xampp\php\php.exe cli\install.php            # real use: schema + roles, permissions, settings (no demo data)
   C:\xampp\php\php.exe cli\create-admin.php       # first Admin Head; prints a one-time temporary password
   ```
   For a demo / training copy use `cli\install.php --seed` instead (demo branches, customers, sales and the logins below).
4. **Apache:** the junction `C:\xampp\htdocs\marketing_crm → D:\AI PROJECT\SMS SYSTEM\public` serves only `public/`.
   Start Apache in XAMPP and open `http://localhost/marketing_crm/`.
5. **Wi-Fi access:** in PowerShell **as Administrator**:
   `powershell -ExecutionPolicy Bypass -File "D:\AI PROJECT\SMS SYSTEM\tools\allow-wifi-access.ps1"`
   (port 80, Private networks, local subnet only). Reserve the PC's IP in the router.
6. **Background jobs** (only when used): `php cli/sms-dispatch.php` every minute (scheduled SMS) and
   `php cli/mail-sync.php` every 5 minutes (IMAP mailboxes). See [Deployment](docs/DEPLOYMENT.md#scheduled-jobs).

### Demo logins (seed data only; each must set a new password on first sign-in)

| Username | Password | Role | Sees |
|---|---|---|---|
| admin | Admin@2026 | Admin Head | everything |
| coordinator | Coord@2026 | Admin Coordinator | all branches, view-mostly (matrix is editable) |
| jana | Sales@2026 | Sales Executive | own customers / sales only |

**Never load `seed.sql` into a live system.** The installer blocks `--seed` when `APP_ENV=production`.

---

## Tests

```bash
ALLOW_DB_RESET=yes bash tests/run-all.sh          # everything: PHP lint, 15 unit suites, 17 end-to-end suites, app typecheck
ALLOW_DB_RESET=yes bash tests/run-all.sh --unit   # about 1 minute
```

**The tests ERASE the database in `.env` and load demo data.** Run them only against a test copy, never the office data.

* **Unit / accuracy tests** (`tests/*.php`, run inside a transaction and rolled back) recompute every KPI from raw SQL and prove:
  * dashboard = Sales Details grid = reports = mobile API
  * imports move the KPIs by exactly the imported amounts
* **End-to-end suites** (`tests/e2e/*.sh`) drive the real web pages and API over HTTP: permissions, data scope, CSRF, exports and screens.
* A few checks compare against exact demo figures and skip themselves on dates other than 06-10-2026 (the demo data's "today").

## Security summary

* PDO prepared statements, STRICT sql_mode, bcrypt passwords, password policy, login throttling.
* Hardened sessions, CSRF on every form, CSP without inline script, output escaping.
* `public/` is the only web root; uploads, logs and `.env` are outside it.
* Permissions are checked on the server for every route and API call; hiding buttons is only a convenience.
* Data scope is applied to every list, KPI, report, export and API query; an unresolvable scope shows nothing.
* Audit log of sign-ins, changes, imports, exports and permission changes (read-only in the app).
* Secrets (DB, SMS keys, mail passwords) only in `.env`, never in git, the database or the logs. Phone numbers are masked in SMS logs.

## Git conventions

`feat: …`, `fix: …`, `docs: …`, `chore: …`. Never commit `.env`, passwords, API keys or database dumps (`.gitignore` covers them).
