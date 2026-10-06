# User guide

Screen-by-screen guide to the Marketing CRM. Pictures of every screen are in [`../screenshots/`](../screenshots/)
(the file number is given in brackets). What you see always depends on your **role** (permissions) and
**data scope**: a Sales Executive sees only their own customers and figures, everywhere.

## Signing in (01)

* Use your **username or email** and password. After 5 wrong attempts the username is locked for 15 minutes.
* The first time, and after the Admin Head resets your password, you must choose a new one
  (10+ characters, letters and numbers, not your name or a common password).
* Forgot your password? Ask the Admin Head to reset it (**Access → Users**).
* **Sign out** is at the top right. Changing your password signs you out everywhere else, including the mobile app.

## Dashboard (02-05)

**Filters:** Financial year · Month · Branch · Sales employee · Customer · Product. The page address keeps the
filters, so you can bookmark or share a view.

* **As on:** the current month shows today's figures (**Live**). A past month shows figures as on its last day.
* **Sales Performance:**
  * annual target and sales so far (FY start → yesterday, plus today; never double-counted)
  * target achieved % and target pending
  * average monthly sales and the monthly sales still needed
* **Payment Collection:** this month's collection against the collection target, today's receipts, overdue bills.
* **Branch Pending Order:** value not yet supplied, current month's orders, oldest order, ageing.
* **Email:** New Enquiry · Order · New Lead · Payment Advice · Other. Each card shows the month count, as-on-day count and open emails.
* **Sales representative:** pick a rep (a Sales Executive sees their own panel automatically). The panel shows sales, collection, pending orders, samples and DC, and the overdue split up to 90 / 91-150 / 150+ days.
* **Customer / Product:** choosing a customer or product in the filter opens its panel.
* **Every amount is a link** to the list of documents behind it. Lists show **matches dashboard** when they add up to the card.
* **+ ADD** (top right) quickly records a target, sale, collection, pending order, sample or DC.
  Collections are adjusted against the customer's oldest open bills automatically.

Drill-down pages: Sales (06), Collection (07), Pending orders (08), Outstanding 90/150 days (09). Each has an **Export CSV**.

## Sales Details (12-14)

One table **by employee**, **by branch** or **by month**: target, sales, achieved %, collection, pending orders,
samples, DC, outstanding, 91-150 days, 150+ days. The totals row equals the dashboard for the same filters.
Click a figure to see its records. By month shows only target, sales and collection (balances have no month split).

## Excel Upload (15-17)

1. Choose what to upload. Download the **template (.xlsx)** the first time: it has the right column names and an
   *Instructions* sheet.
2. Upload your `.xlsx` or CSV (max 5 MB, 5,000 rows, first sheet only). Old `.xls` files must be saved as `.xlsx` first.
3. **Match columns:** headings with familiar names are matched for you. Check them, then press **Check all rows**.
4. **Preview:** every row is marked **Ready**, **Error** (with the reason) or **Duplicate** (already in the CRM, or repeated in the file).
   *Download problem rows* gives a CSV of the rows to fix.
5. **Import now** saves all ready rows in one go. If anything fails, nothing is saved.

Rules worth knowing:
* **Dates:** DD-MM-YYYY (05-04-2026, 05/04/2026 and 05-Apr-2026 also work).
* **Amounts:** may use commas or ₹ / Rs.
* **Lines:** for orders, invoices, samples and DCs, rows with the same number become one document with several lines.
  If one line has an error, the whole document waits.
* Existing records are never overwritten. Duplicates are skipped.
* Payments are adjusted against the invoice you name, or else the customer's oldest bills.
* Re-uploading the same file is detected and warned about.

## Customers, Products, Branches (10-11, 28-30, 33)

Search, filter, add, edit, export. Codes are automatic (CUS-00001). A customer with sales or receipts cannot be
deleted; mark it inactive instead. The **SMS opt-out** tick stops promotional and campaign SMS to that customer.

## Leads (31-32)

* **Pipeline:** New → Contacted → Interested → Follow-up → Quotation → Negotiation → Won / Lost (Lost needs a reason).
* **Follow-ups:** schedule calls and visits, then mark them done with the outcome.
* **Convert to customer** creates the customer and links it. A mobile number that already belongs to a customer is refused.

## HRM (18-20)

* Employees have an automatic code, branch, department, designation, reporting manager and joining / relieving dates.
* Tick **Sales representative** for people who appear in the dashboard's rep section; they need a short name, e.g. JANA.
* Marking someone **Resigned** disables their CRM login and mobile app at once.
* Departments and designations: add, rename, deactivate.

## Mail (21-24)

* The inbox shows emails sorted into the dashboard categories by **keyword rules**, with how sure the rules were.
  Tick **Needs review** to see emails the rules were unsure about.
* On an email:
  * **Correct** the category (the original is kept in the history)
  * **Assign** it to a colleague (they can then see it)
  * set the status and follow-up
  * **Link** it to a customer, or **Create lead**
* **Add email** records an email by hand (until a mailbox is connected).
* **Mailboxes & rules** (Admin Head): connect a mailbox by IMAP and edit the keywords for each category (`purchase order : 4`).

## SMS (35-42)

The yellow **TEST MODE** bar means no real SMS is sent (until an SMS provider is connected).

* **Send SMS:** enter a customer code (fills the mobile and details) or a mobile number. Start from a template and
  press **Preview** to see the exact text and how many SMS parts it uses. A ₹ sign or a Tamil character makes the message
  Unicode (70 characters per SMS instead of 160).
* **Campaigns:**
  1. Choose who receives it (customers, open leads or employees; branch, employee, *only customers with overdue bills*) and the template or text.
  2. A draft shows every recipient's message, and who was left out and why (opted out, no mobile, same mobile twice).
  3. **Send now** or set a schedule. Promotional SMS only go out between 09:00 and 21:00.
* **History:** every message with its status. Open one to see the gateway log (numbers are masked).
* **Templates** (Admin Head): placeholders `{name} {customer_name} {company} {employee_name} {mobile} {date} {amount} {invoice_no} {order_no} {delivery_date}`.

## Reports (43-48)

Twelve reports grouped by Sales, Collection, Orders & supply, Leads and Communication. Choose the period or
as-on date and filters, then **Show**. **Excel** and **CSV** downloads include a TOTAL row; Excel amounts are real numbers you can sum.

## Access (25-27)

**Admin Head** only:
* **Users:** add (a temporary password is shown once), edit, disable, reset password, unlock.
* **Roles & permissions:** tick what each role may view, add, edit, delete, import or export, and its data scope.
* **Permission overrides:** exceptions for one person.

Nobody can give permissions they don't have themselves. The last Admin Head cannot be removed.

## Settings & Audit log

* **Settings** (Admin Head):
  * company name
  * whether sales figures exclude or include GST
  * where outstanding comes from (CRM invoices, or an uploaded statement)
  * ageing basis (invoice date / due date) and ageing columns
  * **Financial years:** add the next year, and lock a finished year so nothing can be dated in it
* **Audit log:** every sign-in, change, import, export and permission change. It shows who, when, the IP address and the before → after values. Filter, then export. It cannot be edited.

## Mobile app (49-54)

See [mobile/README.md](../mobile/README.md). Sign in with your CRM username. The server address is the office PC address
(e.g. `192.168.29.12/marketing_crm`). The app shows the dashboard, customers with their open bills (tap to call),
and follow-ups due. Data entry stays on the web.
