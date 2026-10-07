-- =============================================================================
-- MARKETING CRM - Base reference data (EVERY install, including production)
-- -----------------------------------------------------------------------------
-- Roles and the permission matrix, departments / designations, lead sources,
-- email categories with their sorting keywords, starter SMS templates, settings
-- and number sequences. Contains NO users and NO business data: create the first
-- Admin Head with  php cli/create-admin.php
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- -----------------------------------------------------------------------------
-- Roles
-- -----------------------------------------------------------------------------
INSERT INTO roles (id, name, slug, description, data_scope, is_system) VALUES
 (1, 'Admin Head',        'admin_head',        'Full access to every module, branch and employee',             'all',    1),
 (2, 'Admin Coordinator', 'admin_coordinator', 'Partial access, configurable by Admin Head',                    'all',    1),
 (3, 'Sales Manager',     'sales_manager',     'Manages a sales team; sees own reporting team',                 'team',   0),
 (4, 'Sales Executive',   'sales_executive',   'Field sales; sees only own customers and transactions',         'own',    0),
 (5, 'Branch Manager',    'branch_manager',    'Sees assigned branches',                                        'branch', 0),
 (6, 'HR',                'hr',                'Employee master and HRM',                                       'all',    0),
 (7, 'Accounts',          'accounts',          'Collections, outstanding and sales figures',                    'all',    0),
 (8, 'CRM Operator',      'crm_operator',      'Data entry for customers, leads, mail and SMS',                 'branch', 0),
 (9, 'Viewer',            'viewer',            'Read-only access to dashboards and reports',                    'branch', 0);

-- -----------------------------------------------------------------------------
-- Permissions  (module x action, generated from a compact definition)
-- -----------------------------------------------------------------------------
INSERT INTO permissions (module, action, slug, description, is_critical, sort_order)
SELECT m.module,
       a.action,
       CONCAT(m.module, '.', a.action),
       CONCAT(UPPER(LEFT(a.action, 1)), SUBSTRING(a.action, 2), ' ', m.label),
       (a.action = 'delete' OR m.module IN ('users', 'access', 'settings', 'audit')),
       m.ord * 10 + a.idx
FROM (
          SELECT 'dashboard' AS module, 'Dashboard' AS label,      1 AS ord, '["view"]' AS acts
UNION ALL SELECT 'branches',       'Branch Details',               2, '["view","add","edit","delete","export"]'
UNION ALL SELECT 'sales',          'Sales',                        3, '["view","add","edit","delete","import","export"]'
UNION ALL SELECT 'targets',        'Sales Targets',                4, '["view","add","edit","delete","import"]'
UNION ALL SELECT 'daily_entry',    'Daily Entry Sheet',           21, '["view","add","edit"]'
UNION ALL SELECT 'followup',       'Follow up',                   22, '["view","add"]'
UNION ALL SELECT 'collections',    'Payment Collection',           5, '["view","add","edit","delete","import","export"]'
UNION ALL SELECT 'outstanding',    'Outstanding',                  6, '["view","import","export"]'
UNION ALL SELECT 'pending_orders', 'Pending Orders',               7, '["view","add","edit","delete","import","export"]'
UNION ALL SELECT 'samples',        'Samples',                      8, '["view","add","edit","delete","import","export","approve"]'
UNION ALL SELECT 'dc',             'Delivery Challans',            9, '["view","add","edit","delete","import","export"]'
UNION ALL SELECT 'hrm',            'HRM',                         10, '["view","add","edit","delete","export"]'
UNION ALL SELECT 'customers',      'Customers',                   11, '["view","add","edit","delete","import","export"]'
UNION ALL SELECT 'leads',          'Leads',                       12, '["view","add","edit","delete","import","export"]'
UNION ALL SELECT 'products',       'Products',                    13, '["view","add","edit","delete","import","export"]'
UNION ALL SELECT 'reports',        'Reports',                     14, '["view","export"]'
UNION ALL SELECT 'mail',           'Mail',                        15, '["view","edit","manage"]'
UNION ALL SELECT 'sms',            'SMS',                         16, '["view","send","bulk","manage"]'
UNION ALL SELECT 'users',          'Users',                       17, '["view","add","edit","delete"]'
UNION ALL SELECT 'access',         'Access & Permissions',        18, '["manage"]'
UNION ALL SELECT 'settings',       'System Settings',             19, '["manage"]'
UNION ALL SELECT 'audit',          'Audit Logs',                  20, '["view"]'
) m
JOIN JSON_TABLE(m.acts, '$[*]' COLUMNS (idx FOR ORDINALITY, action VARCHAR(30) PATH '$')) a;

-- Admin Head: everything
INSERT INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions;

-- Admin Coordinator (= Sales Coordinator, does the data input). Menus: Dashboard, Branch Details,
-- Follow up, Requests only. Admin Head can give more under Access -> Roles.
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE slug IN (
 'dashboard.view','branches.view','followup.view','followup.add',
 'daily_entry.view','daily_entry.add','daily_entry.edit','targets.add','targets.edit',
 'leads.add','pending_orders.add','dc.add','samples.add','customers.add','sms.send');

-- Sales Manager
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE slug IN (
 'dashboard.view','sales.view','sales.export','targets.view','targets.add','targets.edit',
 'collections.view','outstanding.view','outstanding.export','pending_orders.view','samples.view','dc.view',
 'customers.view','customers.add','customers.edit','products.view',
 'leads.view','leads.add','leads.edit','leads.export','reports.view','reports.export','mail.view','sms.view','sms.send',
 'daily_entry.view','daily_entry.add','daily_entry.edit','samples.approve',
 'pending_orders.add','dc.add','samples.add','followup.view','followup.add');

-- Sales Executive: view only, own data (data_scope = own); the coordinator does the input
INSERT INTO role_permissions (role_id, permission_id)
SELECT 4, id FROM permissions WHERE slug IN (
 'dashboard.view','sales.view','targets.view','collections.view','outstanding.view',
 'pending_orders.view','samples.view','dc.view','customers.view','products.view',
 'leads.view','mail.view','daily_entry.view','followup.view');

-- Branch Manager
INSERT INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE slug IN (
 'dashboard.view','branches.view','sales.view','sales.export','targets.view','collections.view','outstanding.view',
 'pending_orders.view','samples.view','dc.view','hrm.view','customers.view','customers.add','customers.edit',
 'products.view','leads.view','leads.add','leads.edit','reports.view','reports.export','mail.view','sms.view','sms.send',
 'daily_entry.view','daily_entry.add','daily_entry.edit','samples.approve',
 'pending_orders.add','dc.add','samples.add','followup.view','followup.add');

-- Managers: full main reports and charts - every *.view (except users / audit) and report export
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON (p.action = 'view' AND p.module NOT IN ('users', 'audit')) OR p.slug = 'reports.export'
WHERE r.slug IN ('sales_manager', 'branch_manager')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- Managers add and edit employees in HRM (not delete)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('hrm.add', 'hrm.edit')
WHERE r.slug IN ('sales_manager', 'branch_manager')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- HR
INSERT INTO role_permissions (role_id, permission_id)
SELECT 6, id FROM permissions WHERE slug IN ('dashboard.view','branches.view','hrm.view','hrm.add','hrm.edit','hrm.export');

-- Accounts
INSERT INTO role_permissions (role_id, permission_id)
SELECT 7, id FROM permissions WHERE slug IN (
 'dashboard.view','sales.view','sales.import','sales.export','collections.view','collections.add','collections.edit',
 'collections.import','collections.export','outstanding.view','outstanding.import','outstanding.export',
 'customers.view','reports.view','reports.export','followup.view','followup.add');

-- CRM Operator
INSERT INTO role_permissions (role_id, permission_id)
SELECT 8, id FROM permissions WHERE slug IN (
 'dashboard.view','customers.view','customers.add','customers.edit','customers.import',
 'leads.view','leads.add','leads.edit','leads.import','products.view','mail.view','mail.edit','sms.view','sms.send','followup.view');

-- Viewer: every *.view except administrative modules
INSERT INTO role_permissions (role_id, permission_id)
SELECT 9, id FROM permissions WHERE action = 'view' AND module NOT IN ('users','audit','hrm');

INSERT INTO departments (id, name) VALUES
 (1, 'Sales'), (2, 'Accounts'), (3, 'Administration'), (4, 'Human Resources'), (5, 'Dispatch');

INSERT INTO designations (id, name) VALUES
 (1, 'Branch Manager'), (2, 'Sales Manager'), (3, 'Sales Executive'),
 (4, 'Admin Coordinator'), (5, 'Accounts Executive'), (6, 'HR Executive');

INSERT INTO lead_sources (id, name, sort_order) VALUES
 (1, 'Email', 1), (2, 'Website', 2), (3, 'Phone', 3), (4, 'Referral', 4),
 (5, 'Trade Show', 5), (6, 'Walk-in', 6), (7, 'IndiaMART', 7);

INSERT INTO email_categories (id, code, name, keywords, color, sort_order, is_fallback) VALUES
 (1, 'new_enquiry',    'New Enquiry',    '[{"term":"enquiry","weight":3},{"term":"inquiry","weight":3},{"term":"quotation","weight":3},{"term":"quote","weight":2},{"term":"price list","weight":2},{"term":"rate","weight":1}]', 'blue',   1, 0),
 (2, 'order',          'Order',          '[{"term":"purchase order","weight":4},{"term":"po no","weight":3},{"term":"order","weight":2},{"term":"dispatch","weight":1}]',                                                       'green',  2, 0),
 (3, 'new_lead',       'New Lead',       '[{"term":"lead","weight":3},{"term":"interested in","weight":2},{"term":"indiamart","weight":3},{"term":"new requirement","weight":2}]',                                             'purple', 3, 0),
 (4, 'payment_advice', 'Payment Advice', '[{"term":"payment advice","weight":5},{"term":"remittance","weight":4},{"term":"utr","weight":3},{"term":"payment","weight":2},{"term":"neft","weight":2},{"term":"rtgs","weight":2}]', 'amber',  4, 0),
 (5, 'other',          'Other',          '[]',                                                                                                                                                                                'slate',  5, 1);

INSERT INTO sms_templates (name, category, body) VALUES
 ('Payment Reminder', 'transactional', 'Dear {customer_name}, an amount of Rs {amount} against invoice {invoice_no} is overdue. Kindly arrange payment. - {company}'),
 ('Order Received',   'transactional', 'Dear {customer_name}, we have received your order {order_no}. Expected delivery: {delivery_date}. - {company}'),
 ('Follow-up',        'service',       'Dear {name}, {employee_name} from {company} will call you on {date}. Thank you.'),
 ('Enquiry Offer',           'transactional', 'Dear {customer_name}, thank you for your enquiry {enquiry_no}. Our offer for {products}: Rs {amount}. {employee_name} will contact you. - {company}'),
 ('Purchase Order Received', 'transactional', 'Dear {customer_name}, thank you for your purchase order {po_ref}. Our order ref {order_no}, value Rs {amount}. We will update you on dispatch. - {company}'),
 ('Payment Due',             'transactional', 'Dear {customer_name}, Rs {amount} is due against {bill_count} bill(s), oldest due on {due_date}. Kindly arrange payment; ignore if already paid. - {company}');

INSERT INTO settings (setting_group, setting_key, setting_value, value_type, description) VALUES
 ('company',     'name',                 'LAKSHMI SAFETY EQUIPMENT PRIVATE LIMITED', 'string', 'Company name shown in header, reports and SMS'),
 ('company',     'currency',             'INR',          'string', 'ISO currency code'),
 ('general',     'timezone',             'Asia/Kolkata', 'string', 'Application timezone'),
 ('general',     'date_format',          'd-m-Y',        'string', 'Display date format'),
 ('finance',     'fy_start_month',       '4',            'int',    'Financial year start month (4 = April, Indian FY)'),
 ('finance',     'sales_amount_basis',   'taxable',      'string', 'Sales KPIs use: taxable (excl. GST) or total (incl. GST)'),
 ('targets',     'sales_commit_pct',     '80',           'int',    'Month start sheet: sales commitment % shown beside each target'),
 ('targets',     'collection_pct',       '60',           'int',    'Month start sheet: collection target = % of opening outstanding'),
 ('outstanding', 'source',               'computed',     'string', 'computed = invoices minus allocated receipts; imported = latest outstanding_bills snapshot'),
 ('outstanding', 'aging_basis',          'invoice_date', 'string', 'Age bills from invoice_date or due_date'),
 ('outstanding', 'aging_buckets',        '[30,60,90,150]', 'json', 'Aging bucket upper limits in days; last bucket is open-ended'),
 ('mail',        'provider',             'manual',       'string', 'manual | imap | gmail | microsoft_graph'),
 ('mail',        'min_auto_confidence',  '60',           'int',    'Below this confidence an email is left for manual categorisation'),
 ('sms',         'gateway',              'mock',         'string', 'mock never sends real SMS'),
 ('imports',     'max_rows',             '10000',        'int',    'Maximum rows accepted per spreadsheet');

INSERT INTO number_sequences (name, prefix, next_number, padding) VALUES
 ('customer', 'CUS-', 1, 5),
 ('lead',     'LD-',  1, 5),
 ('employee', 'EMP',  1, 3),
 ('enquiry',  'ENQ-', 1, 5),
 ('order',    'ORD-', 1, 5),
 ('dc',       'DCR-', 1, 5),
 ('sample',   'SMR-', 1, 5);
