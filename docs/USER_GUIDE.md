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

## Dashboard: Branch Performance (02-05)

The first page shows **Branch Performance** from the daily entry sheets (see **+ ADD** below).
Filters: Financial year · Month · Branch · Sales employee.
* **No branch chosen:** one row per branch, plus a total.
* **A branch chosen:** its sales employees, with the total row being that branch's one row.
* **One employee chosen:** a single row.

1. **Sales performance**
   * Annual target and sales as on the previous day (FY start → yesterday).
   * Month target and this month's sales up to yesterday (value, NOB = number of bills, NOC = number of customers).
   * % = month sales ÷ month target × 100. Today's sales are shown separately.
   * % figures are coloured against how much of the month has passed: green = ahead of pace, red = well behind.
2. **Pending order · Enquiry · Lead**
   * Pending orders (no stock / price issue / doubtful).
   * Enquiries pending (new customer / new product).
   * Leads created this month (new customer / new product).
3. **Payment collection**
   * Month opening outstanding.
   * Collection this month up to yesterday (with NOB / NOC, % of collection target and % of opening outstanding).
   * Today's collection.
   * Overdue payment, and outstanding of 90 days (91-150) and 150 days (over 150).
4. **Open DC · Samples:** open DC with order / mail confirmation / rep's inform; samples returnable / non-returnable.

**Email** cards stay at the end of the page.

* Every figure is a link to the sheet rows behind it, showing who entered them and when.
* **Positions** (pending orders, enquiries, DC, samples, outstanding) are not added up over days. Each employee's **latest figure on or before the as-on date** is used, then employees are added together.

### + ADD: the entry sheets

**+ ADD** opens a sheet like Excel, with one row per sales employee:

* **Month start** (opens first until the month's figures are complete): Sales target, Collection target, Opening outstanding.
  These are totals per employee, not bill-wise. Needs *Add Sales Targets* permission.
* **Daily entry** (choose the date):
  * **Sales and collection** for the day: value, NOB and NOC (NOC cannot be more than NOB).
  * **Leads created** that day.
  * **The position at the end of the day:** pending orders, enquiries, open DC, samples, overdue / 90 / 150 days.
    Leave a position cell blank if it hasn't changed; the grey figure (the last one entered) stays in use.
* Saving the same date again updates it (needs *Edit Daily Entry Sheet*). Rows left blank are not saved.
* A Sales Executive sees and fills only their own row.

### Bill-wise detail

The **Bill-wise detail** tab keeps the earlier dashboard, built from uploaded or added bills (invoices, receipts, orders, samples, DC).
It has the Customer and Product filters, the drill-down pages (06-09) and the bill-by-bill **+ ADD** panel. Use it when bills are uploaded through Excel Upload.

## Follow up (69-75)

Choose a **sales employee** (a sales rep sees only themself), then what to follow up:

- **Lead gen pending**: open leads and enquiries (not won or lost) with products, expected value and the next follow-up date.
- **Sales pending**: order lines not yet supplied, with the pending value, delivery due date and PO reference.
- **Sample / O.P pending**: samples and open DCs still with the customer, with days out. Samples awaiting a manager's approval are flagged.
- **Dispatch details**: invoices and DCs sent out between two dates.
- **Payment follow up**: pick one of that employee's customers with dues. The statement shows S.No, invoice no, bill date, P.O reference, invoice value, due balance and due date, with totals. Then:
  - **Mail statement** opens your mail program with the statement filled in.
  - **Send payment due SMS** opens the SMS page.
  - **Print statement**.
  - **Record the follow-up** (mail / phone call / direct visit / SMS) with what the customer said and an optional next date. Previous payment follow-ups are listed below.

Overdue dates are shown in red. The sales Excel upload accepts an optional **Customer PO No** column for the P.O reference.

## Requests (59-68)

One screen for everything the team asks the office to record. Pick a tab:

- **New lead**: who informed (Manager / Rep), the customer, products and an approximate price. The lead sheet is internal and nothing is sent to the customer.
- **Enquiry**: how it came (customer request mail / direct visit to office), who informed, whether it is for a new customer or a new product, then customer, contact, products, quantity and offer price. Saving prepares a printable **offer** (ENQ-number) with a "Send enquiry SMS" button.
- **Order**: how the order came (customer PO reference / customer mail / phone call / advance payment) with the reference detail (PO number, mail details, who called, or the advance amount), who informed, products and rates (ORD-number).
- **DC request**: needs the customer's mail or the M.D's approval mail, with the mail details. It can be linked to an order number (DCR-number).
- **Sample request**: Returnable or Non-returnable. A manager must approve it (Approve / Reject in the list or on the request). A manager's own request is approved at once. Only approved samples count as pending samples (SMR-number).

The customer is picked from the customer master (type a name, code or mobile). For a customer who is not in the master, open "New customer". Orders, DC and samples add the customer to the master at once. A user without the right to add customers is asked to get the customer master updated first. Leads and enquiries keep the new customer on the lead only.

Every request has a printable view (Print button).

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

### Sales person details (80-82)

Go to HRM, then **Sales person details**, and choose a branch (or all branches). The team is shown in four groups:

- **Manager**
- **Sales Executives**: name, age, date of birth, area and coordinator. Click a name to open that person's dashboard. **+ ADD target** opens the month target sheet for the branch.
- **Sales Support Admin**: name, age, date of birth.
- **Sales Coordinator**: their areas and the area sales persons they look after.

Set each person's date of birth, sales role, area and sales coordinator on the employee's Edit page. Age is worked out from the date of birth. The employee export includes these columns.

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

### SMS from a record (76-79)

Three templates were added to the template list: **Enquiry Offer**, **Purchase Order Received** and **Payment Due**.

- From an enquiry's offer page, **Send enquiry SMS** opens Send SMS with the Enquiry Offer template. It fills in the enquiry no, products, offer value and sales employee.
- From an order's page, **Send order SMS** uses Purchase Order Received. It fills in the customer's PO reference, order no and value.
- From Follow up → Payment follow up, **Send payment due SMS** uses Payment Due. It fills in the total due, number of bills and oldest due date.

You can still pick any other template; the record's values stay available. New placeholders: {enquiry_no} {products} {po_ref} {due_date} {bill_count}.

## Reports (43-48)

Twelve reports grouped by Sales, Collection, Orders & supply, Leads and Communication. Choose the period or
as-on date and filters, then **Show**. **Excel** and **CSV** downloads include a TOTAL row; Excel amounts are real numbers you can sum.

### Rep-wise details (83-88)

At the top of Reports, choose a **Branch** and a **Sales executive**. The choice is applied to whichever report you open.

- **Sales details**: every invoice line with rep, invoice no, customer, P.O ref, product, quantity, price and value.
- **Target commitment**: each rep's monthly target, achieved, balance and status. **Closed** means the target was reached; **Open** means sales are still short.
- **Pending order details**: rep, order, customer, P.O ref, product, quantities, price, pending value and expected date.
- **Sample / Open DC details**: rep, type, document, customer, product, quantity, price and value.
- **Payment pending details**: rep, customer, invoice no, bill date, P.O ref, invoice value, due balance, due date and overdue days.

Every report has totals and Excel / CSV export.

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
