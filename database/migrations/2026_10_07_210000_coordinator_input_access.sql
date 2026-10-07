-- CRM access approval (owner, 07-10-2026):
-- * The Admin Coordinator is the Sales Coordinator and does all data input. Their menu is
--   Dashboard, Branch Details, Follow up and Requests only.
-- * Sales Executives are view only (own data).
-- * Follow up gets its own permissions (view lists, add = record a payment follow-up).

INSERT INTO permissions (module, action, slug, description, is_critical, sort_order) VALUES
 ('followup', 'view', 'followup.view', 'View Follow up', 0, 221),
 ('followup', 'add',  'followup.add',  'Add Follow up',  0, 222);

-- Follow up: view for every role that has the dashboard (except HR), add for the input roles
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'followup.view'
WHERE r.slug IN ('admin_head', 'admin_coordinator', 'sales_manager', 'sales_executive', 'branch_manager', 'accounts', 'crm_operator', 'viewer')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'followup.add'
WHERE r.slug IN ('admin_head', 'admin_coordinator', 'sales_manager', 'branch_manager', 'accounts')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- Admin Coordinator: exactly the input rights behind the four menus
DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
WHERE r.slug = 'admin_coordinator';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN (
    'dashboard.view', 'branches.view', 'followup.view', 'followup.add',
    'daily_entry.view', 'daily_entry.add', 'daily_entry.edit', 'targets.add', 'targets.edit',
    'leads.add', 'pending_orders.add', 'dc.add', 'samples.add', 'customers.add', 'sms.send')
WHERE r.slug = 'admin_coordinator';

-- Sales Executives: view only
DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'sales_executive'
  AND p.slug IN ('leads.add', 'leads.edit', 'daily_entry.add', 'daily_entry.edit', 'pending_orders.add', 'dc.add', 'samples.add');
