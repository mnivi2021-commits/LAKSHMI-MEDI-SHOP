# Decisions and defaults

During the build, the owner asked for decisions to be made with sensible defaults and listed here (rather than stopping
for approval at each step). Each item says **what was chosen** and **where to change it** if the business prefers otherwise.

## Branch Performance (dashboard first page) - decided with the owner on 07-10-2026

| # | Decision |
|---|---|
| B1 | The first page is built from **daily entry sheets with totals per sales employee** (owner's choice), not from individual bills. The bill-wise dashboard remains as the *Bill-wise detail* tab. |
| B2 | Opening outstanding is **one total per employee per month**, typed on the month-start sheet (owner's choice). |
| B3 | Sales and collection carry **NOB** (number of bills) and **NOC** (number of customers). NOC may not exceed NOB; a value needs at least one bill. |
| B4 | "Sales as on previous day" and "This month sales" stop at yesterday; today is shown separately, so nothing is counted twice. |
| B5 | Positions (pending orders, enquiries, open DC, samples, overdue, 90 / 150 days) use each employee's **latest entered figure on or before the as-on date**. A blank cell means "unchanged". They are never added up over days. |
| B6 | "Enquiry pending" and "Leads created" are counts. Leads created are added up for the month; enquiries pending are a position. |
| B7 | "90 days" = 91-150 days and "150 days" = over 150 days, as elsewhere in the CRM; the employee types these totals from the accounts software. |
| B8 | % figures are coloured against the share of the month already gone (green = ahead, red = below 80% of pace), so early-month figures are not shown red unfairly. |
| B9 | The month sheet writes targets into the same targets table used everywhere, so targets agree across the dashboard, Sales Details and reports. |
| B10 | New permission **Daily Entry Sheet** (view / add / edit): given to Admin Coordinator, Sales Manager, Branch Manager and Sales Executive (own row only). The month sheet needs *Add Sales Targets*. |

## Figures and periods

| # | Decision | Change it |
|---|---|---|
| 1 | **Sales are counted on the taxable value (excluding GST).** Credit notes subtract; cancelled or deleted invoices never count. | Settings → *Sales figures use* |
| 2 | **"90 DAYS" box = bills 91–150 days old; "150 DAYS" box = over 150 days.** The rep panel adds an *up to 90 days* column. | Fixed definition (docs/DATABASE.md) |
| 3 | **Bill age is measured from the invoice date.** | Settings → *Age bills from* (due date) |
| 4 | Ageing columns in reports: 0-30 / 31-60 / 61-90 / 91-150 / 150+. | Settings → *Ageing columns* |
| 5 | Windows never overlap: *FY to previous day* + *today* = total, so today is never counted twice. A past month shows figures as on its last day. | — |
| 6 | Outstanding is computed from CRM invoices minus allocated receipts and credit notes. An uploaded accounts statement can be used instead. | Settings → *Outstanding comes from* |
| 7 | Targets are per employee per month. A **customer or product filter shows no target** (n/a), and the customer panel shows target n/a. | — |
| 8 | Collection and outstanding **do not apply to a product filter** (payments are per customer, not per product); the card says so. | — |
| 9 | Report periods count a monthly target in full when the month starts inside the period. | — |
| 10 | Financial year = 1 April – 31 March. The running year cannot be locked; years can be added at most two years ahead. | Settings → Financial years |

## Data entry and masters

| # | Decision |
|---|---|
| 11 | Codes are automatic and never reused, even after a delete: CUS-00001, LD-00001, EMP010. |
| 12 | Records with sales, receipts, targets, customers or a login cannot be deleted; mark them inactive / resigned instead. |
| 13 | **A resigned employee's CRM login is disabled and their mobile tokens are revoked at once.** |
| 14 | GST rates are limited to the Indian slabs 0 / 0.25 / 3 / 5 / 12 / 18 / 28. |
| 15 | A lost lead needs a reason. Lead → customer conversion refuses a mobile number that already belongs to a customer. |
| 16 | Collections (quick add and import) without a named invoice are adjusted against the customer's **oldest open bills**; any extra stays *on account*. Cheque / DD receipts start as *received*, electronic ones as *cleared*. |
| 17 | Phone numbers are stored as the last 10 digits (+91 / 0 removed). |

## Excel upload

| # | Decision |
|---|---|
| 18 | `.xlsx` and CSV are read by built-in code (the server has no PHP zip extension). Only the first sheet is read. Old `.xls` must be re-saved as `.xlsx`. Max 5 MB / 5,000 rows. |
| 19 | Dates are read day-first (05/04/2026 = 5 April), Indian style. |
| 20 | Rows with the same document number form one document; **one bad line holds back the whole document**. |
| 21 | **Existing records are skipped as duplicates, never overwritten.** |
| 22 | Everything is re-checked at import time and saved in one transaction: all or nothing. |
| 23 | Invoices imported without product codes count in sales totals but not in product-wise figures. |

## Mail

| # | Decision |
|---|---|
| 24 | Emails are sorted by **keyword rules** (no AI service). Below 50% confidence an email is flagged *needs review*. |
| 25 | Only a 1,000-character text preview is kept, never the full email body or attachment files. |
| 26 | **Default: emails are entered by hand.** IMAP (with an app password, credentials in `.env`) is built in. Gmail API / Microsoft Graph sign-in is not set up; use IMAP for those mailboxes. |
| 27 | An email assigned to someone becomes visible to them even if it is outside their data scope. |

## SMS

| # | Decision |
|---|---|
| 28 | **The built-in test gateway is the default: nothing is really sent** (TEST MODE banner). A real provider needs DLT registration and one small gateway class. |
| 29 | Customers who opted out never receive campaigns or promotional / service messages; single **transactional** messages are still allowed. |
| 30 | Campaigns are saved as a draft with a full preview first. Up to 500 messages are sent immediately; the rest go through `cli/sms-dispatch.php`. |
| 31 | Own-text campaigns are treated as promotional: only 09:00–21:00. |
| 32 | Amounts in SMS are written "Rs 3,30,164.00" without the ₹ sign, which would make every message Unicode (fewer characters per SMS). |
| 33 | People with company-wide scope see all SMS history; others see the messages they sent. |

## Access

| # | Decision |
|---|---|
| 34 | Admin Coordinator (demo matrix): sees all branches with view permissions, can import pending orders / samples / DC, add and edit leads and send single SMS. The Admin Head can change this matrix at any time. |
| 35 | A user whose data scope cannot be resolved sees nothing (never everything). |

## Mobile app

| # | Decision |
|---|---|
| 36 | The app is for viewing: dashboard, customers with open bills, leads and follow-ups. Data entry, imports, SMS and settings stay on the web. |
| 37 | A phone-browser version is published at `/marketing_crm/app/` (`npm run build:web`). |
| 38 | Installed Android apps need HTTPS (or a special office-only build). Expo Go testing on Wi-Fi works over HTTP. |

## Open items for the owner

1. Run `database/setup_user.sql` (with your own strong password) and put that password in `.env` (see README → Local setup).
2. Run `tools/allow-wifi-access.ps1` once as Administrator for Wi-Fi access.
3. Before real use:
   * load real masters (Excel Upload: customers, then opening invoices / outstanding statement)
   * remove the demo data (install without `--seed`)
   * set real passwords
4. To send real SMS:
   * choose a provider and complete DLT registration (entity ID, sender ID, templates)
   * then add the gateway class (see `app/Services/Sms/Gateways.php`)
5. To read a mailbox automatically: enable PHP's imap extension and set `MAIL_IMAP_*` in `.env`.
6. For use outside the office: a hosted server with HTTPS (see [DEPLOYMENT.md](DEPLOYMENT.md)).
