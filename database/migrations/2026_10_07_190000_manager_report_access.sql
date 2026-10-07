-- CRM access approval: managers get the full main reports and charts
-- (every *.view except users / audit, plus report export). Admin Head keeps full access
-- and grants anything more to the Admin Coordinator under Access -> Roles.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON (p.action = 'view' AND p.module NOT IN ('users', 'audit')) OR p.slug = 'reports.export'
WHERE r.slug IN ('sales_manager', 'branch_manager')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
