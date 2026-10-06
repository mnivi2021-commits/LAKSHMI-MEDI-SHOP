-- =============================================================================
-- MARKETING CRM - Seed data (DEVELOPMENT / DEMO ONLY)
-- Loaded AFTER database/base.sql (roles, permissions, settings ...).
-- -----------------------------------------------------------------------------
-- NEVER load this file into a production database: it creates users with
-- known passwords (all flagged must_change_password = 1).
--
--   admin        / Admin@2026   Admin Head        (full access)
--   coordinator  / Coord@2026   Admin Coordinator (partial access)
--   jana         / Sales@2026   Sales Executive   (own records only)
--
-- Business data is deterministic (fixed dates in FY 2025-26 / FY 2026-27) so
-- dashboard totals can be verified by hand with plain SQL.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';


-- -----------------------------------------------------------------------------
-- Financial years
-- -----------------------------------------------------------------------------
INSERT INTO financial_years (id, label, start_date, end_date, is_current) VALUES
 (1, 'FY 2025-26', '2025-04-01', '2026-03-31', 0),
 (2, 'FY 2026-27', '2026-04-01', '2027-03-31', 1),
 (3, 'FY 2027-28', '2027-04-01', '2028-03-31', 0);

-- -----------------------------------------------------------------------------
-- Organisation
-- -----------------------------------------------------------------------------
INSERT INTO branches (id, branch_code, name, address, city, state, pincode, contact_number, email) VALUES
 (1, 'CHN', 'Chennai Head Office', '12, Anna Salai',        'Chennai',    'Tamil Nadu', '600002', '044-40000001', 'chennai@example.com'),
 (2, 'CBE', 'Coimbatore Branch',   '45, Avinashi Road',     'Coimbatore', 'Tamil Nadu', '641018', '0422-4000002', 'coimbatore@example.com'),
 (3, 'MDU', 'Madurai Branch',      '8, West Masi Street',   'Madurai',    'Tamil Nadu', '625001', '0452-4000003', 'madurai@example.com');



INSERT INTO employees (id, employee_code, name, short_name, mobile, email, branch_id, department_id, designation_id, reporting_manager_id, joining_date, is_sales_rep) VALUES
 (1, 'EMP001', 'Rajesh Kumar',  'RAJESH',   '9000000001', 'rajesh@example.com',   1, 1, 2, NULL, '2019-06-01', 0),
 (2, 'EMP002', 'Janakiraman S', 'JANA',     '9000000002', 'jana@example.com',     1, 1, 3, 1,    '2021-04-12', 1),
 (3, 'EMP003', 'Mukesh R',      'MUKESH',   '9000000003', 'mukesh@example.com',   1, 1, 3, 1,    '2022-01-10', 1),
 (8, 'EMP008', 'Senthil Nathan','SENTHIL',  '9000000008', 'senthil@example.com',  2, 1, 1, NULL, '2018-08-20', 0),
 (4, 'EMP004', 'Prakash M',     'PRAKASH',  '9000000004', 'prakash@example.com',  2, 1, 3, 8,    '2020-11-02', 1),
 (5, 'EMP005', 'Ananth K',      'ANANTH',   '9000000005', 'ananth@example.com',   2, 1, 3, 8,    '2023-02-15', 1),
 (9, 'EMP009', 'Karthik V',     'KARTHIK',  '9000000009', 'karthik@example.com',  3, 1, 1, NULL, '2019-03-11', 0),
 (6, 'EMP006', 'Muthuvel P',    'MUTHUVEL', '9000000006', 'muthuvel@example.com', 3, 1, 3, 9,    '2021-09-01', 1),
 (7, 'EMP007', 'Lakshmi Priya', 'LAKSHMI',  '9000000007', 'lakshmi@example.com',  1, 3, 4, NULL, '2022-07-04', 0);

UPDATE branches SET manager_employee_id = 1 WHERE id = 1;
UPDATE branches SET manager_employee_id = 8 WHERE id = 2;
UPDATE branches SET manager_employee_id = 9 WHERE id = 3;

-- -----------------------------------------------------------------------------
-- Users (passwords documented at top of file; must be changed on first login)
-- -----------------------------------------------------------------------------
INSERT INTO users (id, role_id, employee_id, name, username, email, mobile, password_hash, must_change_password) VALUES
 (1, 1, NULL, 'Admin Head',    'admin',       'admin@example.com',       NULL,         '$2y$12$Eye0vPG9Ik1pLmwxLdNBuOUMHaNE8bnVjdRUQlPVsV9NpAFl3gYHS', 1),
 (2, 2, 7,    'Lakshmi Priya', 'coordinator', 'coordinator@example.com', '9000000007', '$2y$12$paIRKB.CdANcBNpXefT4/OHnra3.vBepNcPyzXNFRCvBbI9NBNMB2', 1),
 (3, 4, 2,    'Janakiraman S', 'jana',        'jana@example.com',        '9000000002', '$2y$12$WmxOJ053yhZ4k/5e4N.rMOw8Iu83ChrDGK45TOE.HfRUFby4Jk5x.', 1);

-- -----------------------------------------------------------------------------
-- Products
-- -----------------------------------------------------------------------------
INSERT INTO products (id, product_code, name, category, unit, hsn_code, rate, gst_rate) VALUES
 (1, 'P001', 'Corrugated Box 5-Ply',    'Packaging',  'Nos',  '4819', 42.00, 18.00),
 (2, 'P002', 'Stretch Film 23 Micron',  'Films',      'Roll', '3920', 1150.00, 18.00),
 (3, 'P003', 'BOPP Tape 48mm',          'Tapes',      'Roll', '3919', 38.00, 18.00),
 (4, 'P004', 'Bubble Wrap Roll 1m',     'Films',      'Roll', '3920', 980.00, 18.00),
 (5, 'P005', 'HDPE Bag 20x30',          'Bags',       'Kg',   '3923', 160.00, 18.00),
 (6, 'P006', 'Wooden Pallet 4-Way',     'Pallets',    'Nos',  '4415', 1450.00, 12.00),
 (7, 'P007', 'PP Strapping Roll 12mm',  'Strapping',  'Roll', '3920', 720.00, 18.00),
 (8, 'P008', 'Air Pillow Film',         'Films',      'Roll', '3920', 2100.00, 18.00);

-- -----------------------------------------------------------------------------
-- Customers  (branch + assigned sales employee)
-- -----------------------------------------------------------------------------
INSERT INTO customers (id, customer_code, name, company_name, mobile, email, address, city, state, pincode, gstin, branch_id, employee_id, credit_days) VALUES
 (1,  'CUS-00001', 'Sri Balaji Traders',      'Sri Balaji Traders',            '9100000001', 'accounts@balaji.example.com',   'Guindy',          'Chennai',    'Tamil Nadu', '600032', '33AAAAA0001A1Z1', 1, 2, 30),
 (2,  'CUS-00002', 'Annai Exports',           'Annai Exports Pvt Ltd',         '9100000002', 'purchase@annai.example.com',    'Ambattur',        'Chennai',    'Tamil Nadu', '600058', '33AAAAA0002A1Z2', 1, 2, 45),
 (3,  'CUS-00003', 'Kaveri Foods',            'Kaveri Foods LLP',              '9100000003', 'stores@kaveri.example.com',     'Sriperumbudur',   'Chennai',    'Tamil Nadu', '602105', '33AAAAA0003A1Z3', 1, 3, 30),
 (4,  'CUS-00004', 'Velan Pharma',            'Velan Pharma Ltd',              '9100000004', 'scm@velan.example.com',         'Perungudi',       'Chennai',    'Tamil Nadu', '600096', '33AAAAA0004A1Z4', 1, 3, 60),
 (5,  'CUS-00005', 'Marina Electronics',      'Marina Electronics',            '9100000005', 'buy@marina.example.com',        'Oragadam',        'Chennai',    'Tamil Nadu', '602105', '33AAAAA0005A1Z5', 1, 2, 30),
 (6,  'CUS-00006', 'Kongu Textiles',          'Kongu Textiles Pvt Ltd',        '9100000006', 'po@kongu.example.com',          'Tiruppur Road',   'Coimbatore', 'Tamil Nadu', '641603', '33AAAAA0006A1Z6', 2, 4, 45),
 (7,  'CUS-00007', 'Siruvani Pumps',          'Siruvani Pumps',                '9100000007', 'stores@siruvani.example.com',   'Ganapathy',       'Coimbatore', 'Tamil Nadu', '641006', '33AAAAA0007A1Z7', 2, 4, 30),
 (8,  'CUS-00008', 'Nilgiri Tea Packers',     'Nilgiri Tea Packers',           '9100000008', 'pack@nilgiri.example.com',      'Mettupalayam',    'Coimbatore', 'Tamil Nadu', '641301', '33AAAAA0008A1Z8', 2, 5, 30),
 (9,  'CUS-00009', 'Ganga Auto Components',   'Ganga Auto Components Ltd',     '9100000009', 'purchase@ganga.example.com',    'SIDCO',           'Coimbatore', 'Tamil Nadu', '641021', '33AAAAA0009A1Z9', 2, 5, 60),
 (10, 'CUS-00010', 'Meenakshi Agencies',      'Meenakshi Agencies',            '9100000010', 'office@meenakshi.example.com',  'Kappalur',        'Madurai',    'Tamil Nadu', '625008', '33AAAAA0010A1Z0', 3, 6, 30),
 (11, 'CUS-00011', 'Vaigai Spinning Mills',   'Vaigai Spinning Mills',         '9100000011', 'mill@vaigai.example.com',       'Kochadai',        'Madurai',    'Tamil Nadu', '625016', '33AAAAA0011A1Z1', 3, 6, 45),
 (12, 'CUS-00012', 'Pandian Chemicals',       'Pandian Chemicals',             '9100000012', 'buy@pandian.example.com',       'Kappalur',        'Madurai',    'Tamil Nadu', '625008', '33AAAAA0012A1Z2', 3, 6, 30);

-- -----------------------------------------------------------------------------
-- Monthly targets FY 2026-27 (annual = SUM of the 12 months)
--   JANA 90,000/m  MUKESH 80,000/m  PRAKASH 75,000/m  ANANTH 70,000/m  MUTHUVEL 65,000/m
-- -----------------------------------------------------------------------------
INSERT INTO sales_targets (financial_year_id, employee_id, branch_id, target_month, sales_target, collection_target, created_by)
WITH RECURSIVE months AS (
    SELECT DATE('2026-04-01') AS m
    UNION ALL
    SELECT DATE_ADD(m, INTERVAL 1 MONTH) FROM months WHERE m < '2027-03-01'
),
reps AS (
              SELECT 2 AS employee_id, 90000.00 AS monthly
    UNION ALL SELECT 3, 80000.00
    UNION ALL SELECT 4, 75000.00
    UNION ALL SELECT 5, 70000.00
    UNION ALL SELECT 6, 65000.00
)
SELECT 2, r.employee_id, e.branch_id, months.m, r.monthly, ROUND(r.monthly * 0.90, 2), 1
FROM months CROSS JOIN reps r JOIN employees e ON e.id = r.employee_id;

-- -----------------------------------------------------------------------------
-- Sales invoices
--   * 6 old invoices in FY 2025-26 (unpaid -> 150+ day outstanding)
--   * 136 generated invoices 01-04-2026 .. 02-10-2026
--   * 4 explicit invoices: 2 on 05-10-2026 (yesterday) and 2 on 06-10-2026 (today)
-- -----------------------------------------------------------------------------
INSERT INTO sales_invoices (id, financial_year_id, invoice_no, invoice_date, due_date, customer_id, branch_id, employee_id, taxable_amount, tax_amount, total_amount, source, created_by) VALUES
 (1, 1, 'INV/25-26/0901', '2026-01-12', '2026-02-11', 1,  1, 2, 40000.00, 7200.00, 47200.00, 'manual', 1),
 (2, 1, 'INV/25-26/0915', '2026-02-03', '2026-03-20', 4,  1, 3, 65000.00, 11700.00, 76700.00, 'manual', 1),
 (3, 1, 'INV/25-26/0933', '2026-02-21', '2026-04-07', 6,  2, 4, 52000.00, 9360.00, 61360.00, 'manual', 1),
 (4, 1, 'INV/25-26/0950', '2026-03-09', '2026-05-08', 9,  2, 5, 30000.00, 5400.00, 35400.00, 'manual', 1),
 (5, 1, 'INV/25-26/0968', '2026-03-18', '2026-04-17', 10, 3, 6, 22000.00, 3960.00, 25960.00, 'manual', 1),
 (6, 1, 'INV/25-26/0977', '2026-03-28', '2026-05-12', 11, 3, 6, 47000.00, 8460.00, 55460.00, 'manual', 1);

INSERT INTO sales_invoices (id, financial_year_id, invoice_no, invoice_date, due_date, customer_id, branch_id, employee_id, taxable_amount, tax_amount, total_amount, source, created_by)
WITH RECURSIVE seq AS (
    SELECT 1 AS n UNION ALL SELECT n + 1 FROM seq WHERE n < 136
),
base AS (
    SELECT n,
           DATE_ADD('2026-04-01', INTERVAL FLOOR((n - 1) * 184 / 135) DAY) AS inv_date,
           1 + MOD(n * 5, 12) AS customer_id,
           ROUND((5000 + MOD(n * 7919, 45000)) / 100) * 100 AS taxable
    FROM seq
)
SELECT 100 + b.n, 2, CONCAT('INV/26-27/', LPAD(b.n, 4, '0')), b.inv_date,
       DATE_ADD(b.inv_date, INTERVAL c.credit_days DAY),
       b.customer_id, c.branch_id, c.employee_id,
       b.taxable, ROUND(b.taxable * 0.18, 2), ROUND(b.taxable * 1.18, 2), 'manual', 1
FROM base b JOIN customers c ON c.id = b.customer_id;

INSERT INTO sales_invoices (id, financial_year_id, invoice_no, invoice_date, due_date, customer_id, branch_id, employee_id, taxable_amount, tax_amount, total_amount, source, created_by) VALUES
 (301, 2, 'INV/26-27/0137', '2026-10-05', '2026-11-04', 1, 1, 2, 18500.00, 3330.00, 21830.00, 'manual', 1),
 (302, 2, 'INV/26-27/0138', '2026-10-05', '2026-11-04', 7, 2, 4, 26000.00, 4680.00, 30680.00, 'manual', 1),
 (303, 2, 'INV/26-27/0139', '2026-10-06', '2026-11-20', 2, 1, 2, 12400.00, 2232.00, 14632.00, 'manual', 1),
 (304, 2, 'INV/26-27/0140', '2026-10-06', '2026-11-05', 12, 3, 6, 9800.00, 1764.00, 11564.00, 'manual', 1);

-- Credit note (sales return) against unpaid invoice INV/26-27/0004 (id 104, Ganga Auto Components).
-- Reduces ANANTH's September sales by 2,000 (taxable) and the invoice balance by 2,360.
INSERT INTO sales_invoices (id, financial_year_id, document_type, invoice_no, invoice_date, customer_id, branch_id, employee_id, reference_invoice_id, taxable_amount, tax_amount, total_amount, remarks, source, created_by) VALUES
 (305, 2, 'credit_note', 'CN/26-27/0001', '2026-09-10', 9, 2, 5, 104, 2000.00, 360.00, 2360.00, 'Damaged cartons returned', 'manual', 1);

-- One line item per invoice (product rotates; rate derived from taxable value)
INSERT INTO sales_invoice_items (invoice_id, product_id, quantity, rate, taxable_amount, gst_rate, tax_amount, line_total)
SELECT i.id,
       1 + MOD(i.id, 8),
       10 + MOD(i.id, 40),
       ROUND(i.taxable_amount / (10 + MOD(i.id, 40)), 2),
       i.taxable_amount, 18.00, i.tax_amount, i.total_amount
FROM sales_invoices i;

-- -----------------------------------------------------------------------------
-- Collections
--   Generated invoices dated up to 31-08-2026 where n is not a multiple of 4 are
--   paid in full 30-44 days later (capped at today, 06-10-2026). Multiples of 4
--   stay unpaid -> outstanding ages 30..188 days. All later invoices stay open.
-- -----------------------------------------------------------------------------
INSERT INTO collections (id, financial_year_id, receipt_no, receipt_date, customer_id, branch_id, employee_id, amount, payment_mode, reference_no, status, created_by)
SELECT i.id,
       2,
       CONCAT('RCP/26-27/', LPAD(i.id - 100, 4, '0')),
       LEAST(DATE_ADD(i.invoice_date, INTERVAL 30 + MOD(i.id, 15) DAY), DATE('2026-10-06')),
       i.customer_id, i.branch_id, i.employee_id, i.total_amount,
       ELT(1 + MOD(i.id, 4), 'neft', 'rtgs', 'upi', 'cheque'),
       CONCAT('UTR', LPAD(i.id * 7331, 10, '0')),
       'cleared', 1
FROM sales_invoices i
WHERE i.id BETWEEN 101 AND 236
  AND MOD(i.id - 100, 4) <> 0
  AND i.invoice_date <= '2026-08-31';

-- Part payment against an old FY 2025-26 invoice, and two receipts today / yesterday
INSERT INTO collections (id, financial_year_id, receipt_no, receipt_date, customer_id, branch_id, employee_id, amount, payment_mode, reference_no, status, created_by) VALUES
 (401, 2, 'RCP/26-27/0901', '2026-05-15', 4,  1, 3, 30000.00, 'neft',   'UTR0000901001', 'cleared', 1),
 (402, 2, 'RCP/26-27/0902', '2026-10-05', 1,  1, 2, 21830.00, 'upi',    'UPI0000902002', 'cleared', 1),
 (403, 2, 'RCP/26-27/0903', '2026-10-06', 7,  2, 4, 15000.00, 'cheque', 'CHQ000903',     'received', 1),
 (404, 2, 'RCP/26-27/0904', '2026-09-20', 8,  2, 5, 12000.00, 'cheque', 'CHQ000904',     'bounced', 1);

INSERT INTO collection_allocations (collection_id, invoice_id, amount, created_by)
SELECT c.id, c.id, c.amount, 1 FROM collections c WHERE c.id BETWEEN 101 AND 236;

INSERT INTO collection_allocations (collection_id, invoice_id, amount, created_by) VALUES
 (401, 2,   30000.00, 1),
 (402, 301, 21830.00, 1),
 (403, 302, 15000.00, 1);
-- 404 is a bounced cheque: it has no allocation and is excluded from KPIs.

-- -----------------------------------------------------------------------------
-- Pending orders (spread across every aging bucket)
-- -----------------------------------------------------------------------------
INSERT INTO pending_orders (id, financial_year_id, order_no, order_date, customer_po_no, customer_id, branch_id, employee_id, expected_delivery_date, status, remarks, created_by) VALUES
 (1,  1, 'SO/25-26/0412', '2026-03-16', 'PO-VLN-881',  4,  1, 3, '2026-04-15', 'partial', 'Balance awaiting customer schedule', 1),
 (2,  2, 'SO/26-27/0021', '2026-04-22', 'PO-KNG-120',  6,  2, 4, '2026-05-20', 'partial', NULL, 1),
 (3,  2, 'SO/26-27/0048', '2026-06-10', 'PO-GAC-311',  9,  2, 5, '2026-07-10', 'open',    'Awaiting advance', 1),
 (4,  2, 'SO/26-27/0063', '2026-07-02', 'PO-ANX-077',  2,  1, 2, '2026-07-30', 'partial', NULL, 1),
 (5,  2, 'SO/26-27/0071', '2026-07-28', 'PO-VSM-450',  11, 3, 6, '2026-08-25', 'open',    NULL, 1),
 (6,  2, 'SO/26-27/0085', '2026-08-19', 'PO-KVF-019',  3,  1, 3, '2026-09-15', 'open',    NULL, 1),
 (7,  2, 'SO/26-27/0094', '2026-09-03', 'PO-SRP-208',  7,  2, 4, '2026-09-30', 'partial', NULL, 1),
 (8,  2, 'SO/26-27/0102', '2026-09-21', 'PO-MEA-066',  10, 3, 6, '2026-10-15', 'open',    NULL, 1),
 (9,  2, 'SO/26-27/0110', '2026-10-01', 'PO-SBT-412',  1,  1, 2, '2026-10-20', 'open',    NULL, 1),
 (10, 2, 'SO/26-27/0113', '2026-10-06', 'PO-NTP-090',  8,  2, 5, '2026-10-25', 'open',    'Received today', 1),
 (11, 2, 'SO/26-27/0030', '2026-05-05', 'PO-MRE-031',  5,  1, 2, '2026-05-30', 'closed',  'Fully supplied - excluded from pending', 1);

INSERT INTO pending_order_items (order_id, product_id, order_qty, supplied_qty, rate, order_value, supplied_value) VALUES
 (1,  6, 60,   40,   1450.00,  87000.00,  58000.00),
 (2,  2, 50,   30,   1150.00,  57500.00,  34500.00),
 (2,  3, 1000, 1000, 38.00,    38000.00,  38000.00),
 (3,  1, 3000, 0,    42.00,    126000.00, 0.00),
 (4,  4, 40,   25,   980.00,   39200.00,  24500.00),
 (5,  7, 80,   0,    720.00,   57600.00,  0.00),
 (6,  5, 300,  0,    160.00,   48000.00,  0.00),
 (6,  3, 500,  0,    38.00,    19000.00,  0.00),
 (7,  8, 20,   8,    2100.00,  42000.00,  16800.00),
 (8,  1, 1500, 0,    42.00,    63000.00,  0.00),
 (9,  2, 25,   0,    1150.00,  28750.00,  0.00),
 (10, 4, 30,   0,    980.00,   29400.00,  0.00),
 (11, 3, 400,  400,  38.00,    15200.00,  15200.00);

-- -----------------------------------------------------------------------------
-- Samples and Delivery Challans
-- -----------------------------------------------------------------------------
INSERT INTO samples (id, financial_year_id, document_no, document_date, customer_id, branch_id, employee_id, supply_status, pending_status, remarks, created_by) VALUES
 (1, 2, 'SMP/26-27/001', '2026-06-18', 3,  1, 3, 'supplied',     'pending',   'Trial on new line', 1),
 (2, 2, 'SMP/26-27/002', '2026-07-22', 8,  2, 5, 'supplied',     'approved',  NULL, 1),
 (3, 2, 'SMP/26-27/003', '2026-08-30', 11, 3, 6, 'supplied',     'pending',   NULL, 1),
 (4, 2, 'SMP/26-27/004', '2026-09-14', 2,  1, 2, 'not_supplied', 'pending',   'Dispatch planned', 1),
 (5, 2, 'SMP/26-27/005', '2026-09-28', 9,  2, 5, 'supplied',     'rejected',  'Thickness not suitable', 1),
 (6, 2, 'SMP/26-27/006', '2026-10-03', 12, 3, 6, 'supplied',     'pending',   NULL, 1);

INSERT INTO sample_items (sample_id, product_id, quantity, sample_value) VALUES
 (1, 8, 2,  4200.00),
 (2, 2, 3,  3450.00),
 (3, 7, 5,  3600.00),
 (4, 4, 2,  1960.00),
 (5, 5, 10, 1600.00),
 (6, 1, 50, 2100.00);

INSERT INTO dc_records (id, financial_year_id, dc_no, dc_date, sample_id, customer_id, branch_id, employee_id, supply_status, pending_status, invoice_id, remarks, created_by) VALUES
 (1, 2, 'DC/26-27/0101', '2026-06-18', 1,    3,  1, 3, 'supplied', 'pending',  NULL, 'Sample DC', 1),
 (2, 2, 'DC/26-27/0118', '2026-07-09', NULL, 6,  2, 4, 'supplied', 'pending',  NULL, 'Goods on approval', 1),
 (3, 2, 'DC/26-27/0124', '2026-07-22', 2,    8,  2, 5, 'supplied', 'closed',   NULL, NULL, 1),
 (4, 2, 'DC/26-27/0139', '2026-08-30', 3,    11, 3, 6, 'supplied', 'pending',  NULL, NULL, 1),
 (5, 2, 'DC/26-27/0150', '2026-09-25', NULL, 1,  1, 2, 'supplied', 'invoiced', 301,  'Billed on 05-10-2026', 1),
 (6, 2, 'DC/26-27/0157', '2026-10-04', NULL, 10, 3, 6, 'supplied', 'pending',  NULL, NULL, 1);

INSERT INTO dc_items (dc_id, product_id, quantity, dc_value) VALUES
 (1, 8, 2,   4200.00),
 (2, 6, 10,  14500.00),
 (3, 2, 3,   3450.00),
 (4, 7, 5,   3600.00),
 (5, 2, 15,  17250.00),
 (6, 3, 300, 11400.00);

-- -----------------------------------------------------------------------------
-- Leads & follow-ups
-- -----------------------------------------------------------------------------

INSERT INTO leads (id, lead_number, name, company_name, mobile, email, source_id, product_id, branch_id, employee_id, status, priority, expected_value, next_followup_at, remarks, created_by, created_at) VALUES
 (1,  'LD-00001', 'Arun Prasad',    'Chola Ceramics',       '9200000001', 'arun@chola.example.com',     2, 1, 1, 2, 'new',         'medium', 120000.00, '2026-10-07 11:00:00', NULL, 1, '2026-10-06 09:40:00'),
 (2,  'LD-00002', 'Deepa R',        'Ooty Organics',        '9200000002', 'deepa@ooty.example.com',     1, 2, 2, 5, 'contacted',   'high',   85000.00,  '2026-10-06 16:00:00', NULL, 1, '2026-10-02 10:15:00'),
 (3,  'LD-00003', 'Faizal M',       'Coastal Seafoods',     '9200000003', 'faizal@coastal.example.com', 7, 8, 1, 3, 'interested',  'high',   240000.00, '2026-10-08 10:30:00', NULL, 1, '2026-09-25 12:00:00'),
 (4,  'LD-00004', 'Gowri S',        'Temple Town Sweets',   '9200000004', 'gowri@ttsweets.example.com', 3, 1, 3, 6, 'follow_up',   'medium', 60000.00,  '2026-10-06 12:00:00', NULL, 1, '2026-09-18 15:20:00'),
 (5,  'LD-00005', 'Harish N',       'Bharath Bearings',     '9200000005', 'harish@bb.example.com',      4, 7, 2, 4, 'quotation',   'high',   175000.00, '2026-10-09 11:00:00', 'Quote QT-118 sent', 1, '2026-09-10 11:45:00'),
 (6,  'LD-00006', 'Indira V',       'Green Leaf Exports',   '9200000006', 'indira@gle.example.com',     5, 2, 1, 2, 'negotiation', 'urgent', 310000.00, '2026-10-06 17:30:00', 'Price discussion', 1, '2026-08-28 10:00:00'),
 (7,  'LD-00007', 'Jagan K',        'Kumaran Hardware',     '9200000007', 'jagan@kumaran.example.com',  6, 3, 3, 6, 'won',         'medium', 45000.00,  NULL, 'Converted', 1, '2026-08-05 13:10:00'),
 (8,  'LD-00008', 'Kavitha P',      'Palani Pickles',       '9200000008', 'kavitha@palani.example.com', 2, 5, 3, 6, 'lost',        'low',    30000.00,  NULL, NULL, 1, '2026-07-21 09:30:00'),
 (9,  'LD-00009', 'Lokesh B',       'Hosur Motors',         '9200000009', 'lokesh@hosur.example.com',   1, 6, 1, 3, 'new',         'high',   150000.00, '2026-10-07 15:00:00', NULL, 1, '2026-10-05 17:05:00'),
 (10, 'LD-00010', 'Malar D',        'Erode Handlooms',      '9200000010', 'malar@erode.example.com',    7, 4, 2, 5, 'new',         'medium', 70000.00,  '2026-10-08 12:00:00', NULL, 1, '2026-10-06 10:20:00'),
 (11, 'LD-00011', 'Naveen T',       'Salem Steel Fab',      '9200000011', 'naveen@ssf.example.com',     3, 7, 2, 4, 'contacted',   'medium', 95000.00,  '2026-10-10 10:00:00', NULL, 1, '2026-09-30 14:00:00'),
 (12, 'LD-00012', 'Oviya J',        'Thanjavur Art Prints', '9200000012', 'oviya@tap.example.com',      1, 1, 3, 6, 'interested',  'low',    40000.00,  '2026-10-12 11:00:00', NULL, 1, '2026-10-01 16:40:00');

INSERT INTO followups (lead_id, customer_id, employee_id, followup_at, followup_type, purpose, notes, status, created_by) VALUES
 (2,    NULL, 5, '2026-10-06 16:00:00', 'call',    'sales',      'Confirm trial quantity',            'pending',   1),
 (4,    NULL, 6, '2026-10-06 12:00:00', 'visit',   'sales',      'Show sample boxes',                 'pending',   1),
 (6,    NULL, 2, '2026-10-06 17:30:00', 'meeting', 'sales',      'Final price negotiation',           'pending',   1),
 (1,    NULL, 2, '2026-10-07 11:00:00', 'call',    'sales',      'Introductory call',                 'pending',   1),
 (NULL, 4,    3, '2026-10-06 11:00:00', 'call',    'collection', 'Overdue INV/25-26/0915 follow-up',  'pending',   1),
 (NULL, 6,    4, '2026-10-05 15:00:00', 'visit',   'collection', 'Collect cheque for overdue bills',  'completed', 1),
 (NULL, 9,    5, '2026-10-07 10:00:00', 'call',    'order',      'Advance for SO/26-27/0048',         'pending',   1),
 (5,    NULL, 4, '2026-10-03 11:00:00', 'email',   'sales',      'Quote follow-up',                   'missed',    1);

-- -----------------------------------------------------------------------------
-- Mail
-- -----------------------------------------------------------------------------
INSERT INTO email_accounts (id, email_address, display_name, provider, branch_id, is_active, created_by) VALUES
 (1, 'sales@example.com', 'Sales Inbox (demo)', 'manual', 1, 1, 1);


INSERT INTO email_messages (account_id, provider_message_id, from_email, from_name, subject, body_preview, received_at, has_attachments, category_id, auto_category_id, classification_method, classification_confidence, customer_id, lead_id, branch_id, employee_id, status, followup_status) VALUES
 (1, 'demo-001', 'arun@chola.example.com',       'Arun Prasad',     'Enquiry for 5-ply corrugated boxes',         'Please share your best rate for 10,000 boxes...',     '2026-10-06 09:31:00', 0, 1, 1, 'rule', 82.00, NULL, 1,    1, 2, 'open',        'pending'),
 (1, 'demo-002', 'stores@kaveri.example.com',    'Kaveri Stores',   'Quotation required - BOPP tape',             'Kindly send quotation for 48mm tape...',               '2026-10-06 10:05:00', 0, 1, 1, 'rule', 78.00, 3,    NULL, 1, 3, 'new',         'none'),
 (1, 'demo-003', 'po@kongu.example.com',         'Kongu Purchase',  'Purchase Order PO-KNG-131',                  'Please find attached our purchase order...',           '2026-10-06 10:47:00', 1, 2, 2, 'rule', 95.00, 6,    NULL, 2, 4, 'open',        'pending'),
 (1, 'demo-004', 'pack@nilgiri.example.com',     'Nilgiri Packers', 'PO-NTP-090 bubble wrap',                     'Our order for 30 rolls bubble wrap...',                '2026-10-06 08:55:00', 1, 2, 2, 'rule', 88.00, 8,    NULL, 2, 5, 'in_progress', 'pending'),
 (1, 'demo-005', 'noreply@indiamart.example.com','IndiaMART',       'New lead: Erode Handlooms - Bubble Wrap',    'Buyer interested in bubble wrap rolls...',             '2026-10-06 10:19:00', 0, 3, 3, 'rule', 91.00, NULL, 10,   2, 5, 'open',        'pending'),
 (1, 'demo-006', 'accounts@balaji.example.com',  'Balaji Accounts', 'Payment advice - UPI0000902002',             'We have remitted Rs 21,830 against INV/26-27/0137...','2026-10-05 18:12:00', 0, 4, 4, 'rule', 97.00, 1,    NULL, 1, 2, 'closed',      'done'),
 (1, 'demo-007', 'stores@siruvani.example.com',  'Siruvani Stores', 'Remittance details',                         'Cheque CHQ000903 for Rs 15,000 handed over...',       '2026-10-06 11:02:00', 1, 4, 4, 'rule', 90.00, 7,    NULL, 2, 4, 'open',        'pending'),
 (1, 'demo-008', 'purchase@ganga.example.com',   'Ganga Purchase',  'Payment schedule',                           'Payment for September bills will be released...',      '2026-10-06 09:12:00', 0, 4, 4, 'rule', 61.00, 9,    NULL, 2, 5, 'new',         'none'),
 (1, 'demo-009', 'newsletter@example.org',       'Trade Weekly',    'Packaging industry news - October',          'This week in packaging...',                           '2026-10-06 07:30:00', 0, 5, 5, 'none', NULL,  NULL, NULL, NULL, NULL, 'ignored', 'none'),
 (1, 'demo-010', 'lokesh@hosur.example.com',     'Lokesh B',        'Requirement for wooden pallets',             'We are interested in 4-way pallets...',                '2026-10-05 17:01:00', 0, 3, 1, 'manual', 100.00, NULL, 9, 1, 3, 'open',        'pending'),
 (1, 'demo-011', 'scm@velan.example.com',        'Velan SCM',       'Re: Order SO/25-26/0412 balance',            'Please schedule the balance pallets...',               '2026-10-06 11:40:00', 0, 2, 2, 'rule', 70.00, 4,    NULL, 1, 3, 'new',         'none'),
 (1, 'demo-012', 'hr@example.org',               'Job Portal',      'Your job posting is live',                   'Your posting has been published...',                  '2026-10-06 06:45:00', 0, 5, 5, 'none', NULL,  NULL, NULL, NULL, NULL, 'ignored', 'none');

INSERT INTO email_activity (email_id, user_id, action, old_value, new_value, note)
SELECT id, 1, 'recategorised', 'new_enquiry', 'new_lead', 'Customer is new - treat as lead'
FROM email_messages WHERE provider_message_id = 'demo-010';

-- -----------------------------------------------------------------------------
-- SMS templates (gateway = mock; nothing is ever sent from seed data)
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- Settings
-- -----------------------------------------------------------------------------


-- Demo values on top of database/base.sql
UPDATE settings SET setting_value = 'Marketing CRM Demo Pvt Ltd' WHERE setting_group = 'company' AND setting_key = 'name';
UPDATE number_sequences SET next_number = 13 WHERE name IN ('customer', 'lead');
UPDATE number_sequences SET next_number = 10 WHERE name = 'employee';
UPDATE sms_templates SET created_by = 1;

INSERT INTO audit_logs (user_id, user_name, role_slug, ip_address, action, module, new_data)
VALUES (NULL, 'system', NULL, '127.0.0.1', 'system.seeded', 'system', JSON_OBJECT('note', 'Development seed data loaded'));
