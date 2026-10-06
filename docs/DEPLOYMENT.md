# Deployment

## Where to host it

| Part | Recommended host |
|---|---|
| PHP backend + web CRM | Any PHP 8.2+ host with Apache or Nginx: a VPS (DigitalOcean, AWS Lightsail, Hostinger VPS), or cPanel hosting that allows MySQL 8 |
| MySQL 8 | Same server, or a managed MySQL 8 (AWS RDS, DigitalOcean Managed MySQL) |
| Mobile app | Expo EAS build → Play Store / App Store (or a shared APK), pointing at the HTTPS address |

**Why not Vercel:**
* Vercel runs serverless functions. There is no persistent disk for uploads, and PHP only runs through a community runtime.
* PHP sessions don't survive between requests, cron jobs are limited, and Vercel cannot host MySQL.
* It suits a static landing page or the phone-browser build, **not this backend**.

## Option A: keep it on the office PC (current setup)

Fine for use inside the office Wi-Fi.

1. MySQL80 service and XAMPP Apache set to start automatically:
   * Windows *Services* → **MySQL80** → *Automatic*.
   * Install Apache as a service from the XAMPP Control Panel (the ✕ next to Apache → *Service*).
2. Reserve the PC's IP in the router. Run `tools/allow-wifi-access.ps1` once.
3. Schedule the background jobs (see [Scheduled jobs](#scheduled-jobs)) and [backups](#backups).
4. Keep the PC on during working hours. Use a UPS: a power cut during a save is handled by transactions, but a dead PC means no CRM.

Plain HTTP is acceptable only inside the office network. For access from outside, use option B. **Never port-forward
the PC to the internet.**

## Option B: a hosted server with HTTPS

1. **Server:** Ubuntu 24.04, `apache2`, `php8.3` with extensions `pdo_mysql mbstring xml xmlreader simplexml zlib fileinfo` (+ `imap` if mail sync is used), MySQL 8.
2. **Code:** `git clone https://github.com/mnivi2021-commits/LAKSHMI-MEDI-SHOP.git /var/www/crm`.
   Point the Apache `DocumentRoot` at **`/var/www/crm/public`**, never the project root, with `AllowOverride All`
   (the `.htaccess` files are needed) and `mod_rewrite`, `mod_headers` enabled.
3. **Database:** run `database/setup_user.sql` (strong password), then `php cli/install.php` (**no `--seed`**). It installs
   the tables plus `database/base.sql` (roles, permission matrix, settings, email categories, lead sources, SMS templates)
   and the current financial year, but no users and no business data.
4. **`.env`** (copy `.env.example`; file mode 600, owned by the web user):
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://crm.example.com
   APP_KEY=<php -r "echo bin2hex(random_bytes(32));">
   SESSION_SECURE_COOKIE=true
   DB_...=...
   ```
5. **HTTPS:** `certbot --apache -d crm.example.com` (free Let's Encrypt certificate, auto-renewing).
6. **Permissions:** `logs/` and `uploads/` writable by the web user; everything else read-only.
7. **Check:**
   * `php cli/health.php` (all green)
   * open `/health` and sign in
   * `php cli/verify-data.php` after the first data import
8. **First user:** `php cli/create-admin.php` creates the Admin Head and prints a one-time temporary password.
   Sign in, choose your own password, then create users for everyone (Access → Users). Never use the demo accounts in production.

### Updating

```bash
cd /var/www/crm && git pull && php cli/migrate.php && php cli/health.php
```

`cli/migrate.php --status` lists applied database changes. Take a backup before every update.

## Scheduled jobs

| Job | Command | How often | Needed when |
|---|---|---|---|
| Scheduled / large SMS campaigns | `php cli/sms-dispatch.php` | every minute | SMS campaigns are used |
| Fetch email (IMAP) | `php cli/mail-sync.php` | every 5 minutes | a mailbox uses IMAP |

* **Linux (crontab -e as the web user):**
  ```
  * * * * *   cd /var/www/crm && php cli/sms-dispatch.php >> logs/cron.log 2>&1
  */5 * * * * cd /var/www/crm && php cli/mail-sync.php   >> logs/cron.log 2>&1
  ```
* **Windows (Task Scheduler):** create a task that runs `C:\xampp\php\php.exe` with arguments
  `"D:\AI PROJECT\SMS SYSTEM\cli\sms-dispatch.php"`, triggered daily and repeated every 1 minute (5 minutes for mail-sync).
  Choose *Run whether user is logged on or not*.

Both jobs are safe if two runs overlap: each SMS is claimed before it is sent, and emails are de-duplicated.

## Backups

* **Daily database dump**, kept for 30 days, with one copy **off the machine** (cloud drive / another PC):
  ```
  mysqldump --single-transaction --routines -u crm_app -p marketing_crm | gzip > backup_YYYY-MM-DD.sql.gz
  ```
  On Windows, `mysqldump.exe` is in `C:\Program Files\MySQL\MySQL Server 8.0\bin`. Put the command in a `.bat` file
  and run it with Task Scheduler.
* **Also back up `uploads/`** (import files) and **`.env`** (store `.env` securely, it holds the passwords).
* **Test a restore** every few months into a test database. A backup that was never restored is a guess.
* Dumps contain customer data: never commit them to git (`*.sql.gz` is ignored) and never email them unencrypted.

## Real SMS and mail

* **SMS (India):**
  1. Register the company, sender ID (6 letters) and every template on the **DLT** portal of your provider.
  2. Set `SMS_GATEWAY`, `SMS_API_KEY`, `SMS_SENDER_ID`, `SMS_DLT_ENTITY_ID` in `.env`.
  3. Add the provider class beside `app/Services/Sms/MockGateway.php` and register it in `Gateways.php`.
  4. Put each DLT template ID on the matching CRM template.
  5. Send a test to your own number before any campaign.
* **Mail:**
  1. Enable `extension=imap` in `php.ini` and restart Apache.
  2. Create an **app password** for the mailbox (Gmail / Outlook / Zoho) and set `MAIL_IMAP_HOST`, `MAIL_IMAP_PORT=993`, `MAIL_IMAP_USERNAME`, `MAIL_IMAP_PASSWORD`.
  3. In **Mail → Mailboxes & rules**, add the mailbox with *IMAP*, then press **Fetch now**.

## Mobile app release

1. Set the production HTTPS address in the app's sign-in screen (users type it once; it is remembered).
2. `cd mobile && npx eas-cli@latest build --platform android --profile preview` for a shareable APK,
   or `--profile production` for the Play Store (needs a Google Play developer account).
3. iOS needs an Apple developer account and `--platform ios`.
4. The phone-browser version: `npm run build:web` on the server, then open `https://crm.example.com/app/`.

## Security checklist before going live

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS with `SESSION_SECURE_COOKIE=true`
- [ ] Web root is `public/` only; `/.env`, `/logs`, `/uploads` return 403/404 from outside
- [ ] Database user is `crm_app` (least privilege), not root; strong unique passwords
- [ ] No demo data or demo accounts; every user has their own login; the Admin Head has a strong password
- [ ] Roles reviewed (Access → Roles & permissions); Sales Executives on *own* scope
- [ ] Backups running and a restore tested
- [ ] `API_ALLOWED_ORIGINS` lists only your own web origins (the native app needs none)
- [ ] Firewall allows only 80/443 (and SSH from your IP)
