# Marketing CRM: Database & KPI Definitions

MySQL 8 · InnoDB · utf8mb4 · 47 tables · 8 KPI views · 150+ foreign keys

This document is the **contract for every number on the dashboard**. If a figure looks wrong, find it below, run the SQL, and compare.

---

## 1. Principles

1. **Transactions are the source of truth.** Sales = invoices, Collection = receipts, Pending = order lines, Sample/DC = documents. No hand-maintained totals.
2. **One definition of "what counts".** Views (`v_*`) apply the rules once: cancelled and soft-deleted documents never count, credit notes are negative, bounced cheques never count, and only open orders with a balance are pending. Dashboard, reports and the mobile API all read the views.
3. **History is frozen.** Each transaction stores `branch_id` and `employee_id` as they were on that day. Re-assigning a customer later does not move past sales.
4. **Traceable.** Every imported row keeps `import_batch_id`, and `import_rows` stores the original spreadsheet row.
5. **Exact money.** `DECIMAL(15,2)`; formatting in PHP uses string arithmetic, never floats.

## 2. Table map

| Domain | Tables | Notes |
|---|---|---|
| Access | `users`, `roles`, `permissions`, `role_permissions`, `user_permissions`, `user_branches`, `login_attempts`, `api_tokens` | `roles.data_scope` = all / branch / team / own. `user_permissions` grants or denies a single permission per user |
| Organisation | `financial_years`, `branches`, `departments`, `designations`, `employees` | `employees.is_sales_rep` drives the Sales Representative section |
| Masters | `customers`, `products`, `lead_sources` | `customers.credit_days` gives the due date when an invoice has none |
| Targets | `sales_targets` | One row per employee per month (1st of month). Annual = SUM of the months |
| Sales | `sales_invoices`, `sales_invoice_items` | `document_type` = invoice / credit_note (credit notes stored positive, subtracted by views) |
| Collection | `collections`, `collection_allocations` | Allocation = which receipt paid which invoice |
| Outstanding | (computed) `v_invoice_balances`; (imported) `outstanding_bills` | Choose with setting `outstanding.source` |
| Pending orders | `pending_orders`, `pending_order_items` | `pending_value`, `pending_qty` are **generated columns** |
| Samples / DC | `samples`, `sample_items`, `dc_records`, `dc_items` | A DC may link to the sample it supplied and the invoice that cleared it |
| Leads | `leads`, `followups` | Lead may link to the email that created it and the customer it became |
| Mail | `email_accounts`, `email_categories`, `email_messages`, `email_assignments`, `email_attachments`, `email_activity` | Classifier keeps its original guess (`auto_category_id`) after a manual correction |
| SMS | `sms_templates`, `sms_campaigns`, `sms_messages`, `sms_logs` | `is_test = 1` for the mock gateway |
| Imports | `import_batches`, `import_rows` | Upload → map → validate → import, with per-row errors |
| System | `settings`, `audit_logs`, `notifications`, `number_sequences`, `schema_migrations` | |

Names in the original brief → tables here: *sales / sales_transactions* → `sales_invoices` + `sales_invoice_items`; *outstanding* → `v_invoice_balances` / `outstanding_bills`; *imports* → `import_batches`; *reports* are generated on demand (exports are recorded in `audit_logs`).

## 3. KPI views

| View | Grain | Rule applied |
|---|---|---|
| `v_sales_documents` | invoice / credit note | active, not deleted; credit notes negative |
| `v_sales_lines` | invoice line (product) | same, per product |
| `v_valid_collections` | receipt | status received/cleared, not deleted; shows unallocated amount |
| `v_invoice_balances` | invoice | total − valid allocations − credit notes; due date defaulted from credit days |
| `v_outstanding_latest` | bill | latest imported snapshot, pending > 0 |
| `v_pending_order_lines` | order line | order open/partial, not deleted, pending_value > 0 |
| `v_pending_sample_lines` | sample line | pending_status = pending, not deleted |
| `v_pending_dc_lines` | DC line | pending_status = pending, not deleted |

## 4. Dashboard KPI definitions

Date windows come from `App\Core\FinancialYear` for the "as on" date (normally today). Example as on **06-10-2026**: FY = 01-04-2026..31-03-2027, *previous day* = 05-10-2026.
`:sales_col` = `taxable_value` (default, excl. GST) or `total_value`, from setting `finance.sales_amount_basis`.
Every query also takes the dashboard filters (branch, employee, customer, product) and the user's data scope.

### A1: Sales Performance

| Figure | Definition |
|---|---|
| Annual Target | `SUM(sales_targets.sales_target)` where `financial_year_id` = FY |
| Sales As On Previous Day | `SUM(:sales_col) FROM v_sales_documents` where `invoice_date BETWEEN fy_start AND yesterday` |
| {Month} Sales As On {yesterday} | same, `BETWEEN month_start AND yesterday` (empty on the 1st) |
| Today's Sales | same, `invoice_date = today` |
| Total Sales | Sales As On Previous Day + Today's Sales (the windows never overlap) |
| Target Achieved % | Total Sales ÷ Annual Target × 100 (blank if target is 0) |
| Target Pending | max(Annual Target − Total Sales, 0) |
| Average Monthly Sales | sales from FY start to end of last month ÷ completed months (blank in April) |
| Required Monthly Sales | Target Pending ÷ remaining months, including the current month |

### A2: Payment Collection

| Figure | Definition |
|---|---|
| Previous Period Collection | `SUM(amount) FROM v_valid_collections` where `receipt_date BETWEEN month_start AND yesterday` |
| Today's Collection | same, `receipt_date = today` |
| Total Collection | Previous + Today |
| Collection Target | `SUM(sales_targets.collection_target)` for the current month |
| Collection % / Pending | Total ÷ Target × 100 / max(Target − Total, 0) |
| Number of Customers / Payments | `COUNT(DISTINCT customer_id)` / `COUNT(*)` over month-to-date receipts |
| Overdue Collection | `SUM(balance) FROM v_invoice_balances WHERE balance > 0 AND due_date < today` |

### A3: Branch Pending Order

| Figure | Definition |
|---|---|
| Total Pending Order Value | `SUM(pending_value) FROM v_pending_order_lines` |
| Number of Pending Orders / Customers | `COUNT(DISTINCT order_id)` / `COUNT(DISTINCT customer_id)` |
| Oldest Pending Order | the order with `MIN(order_date)` |
| Current Pending Order | pending value of orders dated in the current month |
| Aging | `DATEDIFF(today, order_date)` → 0-30, 31-60, 61-90, 91-150, 150+ |

### Outstanding aging (90 / 150 days)

Bill age = `DATEDIFF(today, invoice_date)` (or `due_date`, per setting `outstanding.aging_basis`), over bills with `balance > 0` in `v_invoice_balances` (or `pending_amount` in `v_outstanding_latest` when imported).
Buckets (setting `outstanding.aging_buckets` = `[30,60,90,150]`): 0-30, 31-60, 61-90, 91-150, 150+.

> **Pending confirmation:** the "90 DAYS" box shows bills in the **91-150** bucket, and the "150 DAYS" box shows **151+**. Change this before Phase 10 if management reads them differently (e.g. "older than 90" including 150+).

### Sample / DC

Pending Sample = `v_pending_sample_lines` (value, `COUNT(DISTINCT sample_id)`, `COUNT(DISTINCT customer_id)`). Pending DC = `v_pending_dc_lines` the same way.

### Mail counters

`COUNT(*) FROM email_messages WHERE received_at >= :today AND received_at < :tomorrow GROUP BY category_id` (half-open range so the index is used).

## 5. Integrity rules (`php cli/verify-data.php`)

MySQL constraints enforce single-row rules: positive amounts, supplied ≤ ordered, unique document numbers per FY, targets on the 1st of the month, foreign keys. The checker enforces rules that span tables:

* document date falls inside its `financial_year_id` (invoices, receipts, orders, samples, DCs, targets)
* no invoice settled beyond its total; no receipt allocated beyond its amount
* allocations only to the same customer's invoices, never to credit notes or from void receipts
* credit notes reference an invoice of the same customer
* invoice header totals agree with tax and with line items
* order status agrees with pending balance; DCs marked invoiced have an invoice
* exactly one current FY, no overlapping FYs
* "own"/"team" scope users are linked to an employee; "branch" scope users have branches

It runs on the health page, after every import (Phase 14), and from the command line.

## 6. Changing the schema

Never edit `schema.sql` for a live database. Add `database/migrations/YYYY_MM_DD_HHMMSS_description.sql` and run `php cli/migrate.php`. A fresh install includes everything up to now. Editing an already-applied migration is detected and reported as `CHANGED`.
