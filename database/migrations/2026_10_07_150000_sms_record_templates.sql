-- Screen 4: SMS templates for enquiry offers, purchase orders and payment dues
-- (chosen automatically when SMS is opened from an enquiry, an order or a payment statement).
INSERT INTO sms_templates (name, category, body)
SELECT t.name, t.category, t.body FROM (
  SELECT 'Enquiry Offer' AS name, 'transactional' AS category, 'Dear {customer_name}, thank you for your enquiry {enquiry_no}. Our offer for {products}: Rs {amount}. {employee_name} will contact you. - {company}' AS body
  UNION ALL SELECT 'Purchase Order Received', 'transactional', 'Dear {customer_name}, thank you for your purchase order {po_ref}. Our order ref {order_no}, value Rs {amount}. We will update you on dispatch. - {company}'
  UNION ALL SELECT 'Payment Due', 'transactional', 'Dear {customer_name}, Rs {amount} is due against {bill_count} bill(s), oldest due on {due_date}. Kindly arrange payment; ignore if already paid. - {company}'
) t
WHERE NOT EXISTS (SELECT 1 FROM sms_templates s WHERE s.name = t.name AND s.deleted_at IS NULL);

-- Viewer reads every *.view permission (as on a fresh install), including the Daily Entry Sheet.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.action = 'view' AND p.module NOT IN ('users', 'audit', 'hrm')
WHERE r.slug = 'viewer'
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
