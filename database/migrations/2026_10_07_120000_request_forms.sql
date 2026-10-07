-- Screen B request forms: New Lead / Enquiry / Order, Sample and DC requests.
--
-- * Leads and enquiries share the leads table (record_type) and get product lines.
-- * Orders record HOW the order came (PO / mail / phone / advance payment) and who informed.
-- * A DC needs the customer's mail or the M.D's approval mail.
-- * A sample is returnable or not, and only counts after manager approval.
-- * Invoices can carry the customer's PO reference (payment follow-up statement).

ALTER TABLE leads
    ADD COLUMN record_type ENUM('lead','enquiry') NOT NULL DEFAULT 'lead' AFTER lead_number,
    ADD COLUMN lead_type ENUM('new_customer','new_product') NOT NULL DEFAULT 'new_customer' AFTER record_type,
    ADD COLUMN contact_person VARCHAR(150) NULL AFTER company_name,
    ADD COLUMN enquiry_source ENUM('mail','office_visit','phone','other') NULL AFTER source_id,
    ADD COLUMN informed_by ENUM('manager','rep') NULL AFTER employee_id,
    ADD COLUMN informed_employee_id INT UNSIGNED NULL AFTER informed_by,
    ADD KEY idx_leads_type (record_type, status),
    ADD CONSTRAINT fk_leads_informed FOREIGN KEY (informed_employee_id) REFERENCES employees(id) ON DELETE SET NULL;

CREATE TABLE lead_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id      INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NULL COMMENT 'NULL = product not in the master; the form then requires a description',
    description  VARCHAR(200) NULL,
    quantity     DECIMAL(14,3) NOT NULL,
    unit_price   DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Approximate (lead) or offered (enquiry) price per unit',
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lead_items_lead (lead_id),
    CONSTRAINT fk_lead_items_lead    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pending_orders
    ADD COLUMN reference_type ENUM('po','mail','phone','advance') NULL COMMENT 'How the order came' AFTER customer_po_no,
    ADD COLUMN reference_detail VARCHAR(255) NULL AFTER reference_type,
    ADD COLUMN advance_amount DECIMAL(15,2) NULL AFTER reference_detail,
    ADD COLUMN informed_by ENUM('manager','rep') NULL AFTER employee_id,
    ADD COLUMN informed_employee_id INT UNSIGNED NULL AFTER informed_by,
    ADD CONSTRAINT fk_po_informed FOREIGN KEY (informed_employee_id) REFERENCES employees(id) ON DELETE SET NULL;

ALTER TABLE dc_records
    ADD COLUMN approval_type ENUM('customer_mail','md_approval') NULL COMMENT 'Customer mail or M.D approval mail' AFTER pending_status,
    ADD COLUMN approval_reference VARCHAR(255) NULL AFTER approval_type,
    ADD COLUMN order_id INT UNSIGNED NULL AFTER sample_id,
    ADD CONSTRAINT fk_dc_order FOREIGN KEY (order_id) REFERENCES pending_orders(id) ON DELETE SET NULL;

ALTER TABLE samples
    ADD COLUMN sample_type ENUM('returnable','non_returnable') NULL AFTER supply_status,
    ADD COLUMN approval_status ENUM('requested','approved','rejected') NOT NULL DEFAULT 'approved' COMMENT 'Manager approval; only approved samples count' AFTER sample_type,
    ADD COLUMN approved_by INT UNSIGNED NULL AFTER approval_status,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN approval_note VARCHAR(255) NULL AFTER approved_at,
    ADD CONSTRAINT fk_samples_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE sales_invoices
    ADD COLUMN customer_po_no VARCHAR(60) NULL COMMENT 'Customer PO reference' AFTER invoice_no;

-- Only manager-approved samples are "pending with the customer".
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_pending_sample_lines AS
SELECT smi.id                   AS item_id,
       s.id                     AS sample_id,
       s.financial_year_id,
       s.document_no,
       s.document_date,
       s.customer_id,
       s.branch_id,
       s.employee_id,
       s.supply_status,
       smi.product_id,
       smi.quantity,
       smi.sample_value,
       s.import_batch_id
FROM samples s
JOIN sample_items smi ON smi.sample_id = s.id
WHERE s.pending_status = 'pending'
  AND s.approval_status = 'approved'
  AND s.deleted_at IS NULL;

INSERT IGNORE INTO number_sequences (name, prefix, next_number, padding) VALUES
 ('enquiry', 'ENQ-', 1, 5), ('order', 'ORD-', 1, 5), ('dc', 'DCR-', 1, 5), ('sample', 'SMR-', 1, 5);

INSERT INTO permissions (module, action, slug, description, is_critical, sort_order) VALUES
 ('samples', 'approve', 'samples.approve', 'Approve Samples', 0, 87);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'samples.approve'
WHERE r.slug IN ('admin_head', 'sales_manager', 'branch_manager')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- Coordinators, managers and reps raise orders, DC and sample requests from the Requests screen.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('pending_orders.add', 'dc.add', 'samples.add')
WHERE r.slug IN ('admin_coordinator', 'sales_manager', 'branch_manager', 'sales_executive')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
