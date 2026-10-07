-- Managers add and edit employees in HRM (not delete). Owner request 07-10-2026.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('hrm.add', 'hrm.edit')
WHERE r.slug IN ('sales_manager', 'branch_manager')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
