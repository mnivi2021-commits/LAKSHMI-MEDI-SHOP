-- Daily entry sheets (Branch Performance dashboard).
--
-- The office records TOTALS per sales representative instead of individual bills:
--   rep_month_openings  once a month: opening outstanding (targets go to sales_targets)
--   rep_daily_totals    each working day: sales / collection totals (with no. of bills and
--                       no. of customers) and the current pending-order, enquiry, lead, DC,
--                       sample and outstanding position.

CREATE TABLE rep_month_openings (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id    INT UNSIGNED NOT NULL,
    employee_id          INT UNSIGNED NOT NULL,
    branch_id            INT UNSIGNED NOT NULL COMMENT 'Snapshot of the employee branch for this month',
    opening_month        DATE         NOT NULL COMMENT 'Always the 1st of the month',
    opening_outstanding  DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total outstanding on the first day of the month',
    created_by           INT UNSIGNED NULL,
    updated_by           INT UNSIGNED NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rmo_employee_month (employee_id, opening_month),
    KEY idx_rmo_branch (branch_id, opening_month),
    CONSTRAINT fk_rmo_fy       FOREIGN KEY (financial_year_id) REFERENCES financial_years(id),
    CONSTRAINT fk_rmo_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_rmo_branch   FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_rmo_created  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rmo_updated  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_rmo_month   CHECK (DAY(opening_month) = 1),
    CONSTRAINT chk_rmo_amount  CHECK (opening_outstanding >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rep_daily_totals (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id     INT UNSIGNED NOT NULL,
    entry_date            DATE         NOT NULL,
    employee_id           INT UNSIGNED NOT NULL,
    branch_id             INT UNSIGNED NOT NULL COMMENT 'Snapshot of the employee branch on that day',
    -- Day's business (added up over a period)
    sales_value           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    sales_bills           SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'NOB',
    sales_customers       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'NOC',
    collection_value      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    collection_bills      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'NOB',
    collection_customers  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'NOC',
    lead_new_customer     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Leads created today - new customers',
    lead_new_product      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Leads created today - existing customers, new product',
    -- Position at the end of the day (NULL = not updated today; the latest earlier figure applies)
    po_non_stock          DECIMAL(15,2) NULL COMMENT 'Pending orders: no stock',
    po_price_issue        DECIMAL(15,2) NULL COMMENT 'Pending orders: price issue',
    po_doubt              DECIMAL(15,2) NULL COMMENT 'Pending orders: doubtful',
    enq_new_customer      SMALLINT UNSIGNED NULL COMMENT 'Enquiries pending - new customers',
    enq_new_product       SMALLINT UNSIGNED NULL COMMENT 'Enquiries pending - new product',
    dc_order              DECIMAL(15,2) NULL COMMENT 'Open DC sent against an order',
    dc_mail               DECIMAL(15,2) NULL COMMENT 'Open DC sent on mail confirmation',
    dc_rep_inform         DECIMAL(15,2) NULL COMMENT 'Open DC sent on rep''s information',
    sample_returnable     DECIMAL(15,2) NULL,
    sample_non_returnable DECIMAL(15,2) NULL,
    os_overdue            DECIMAL(15,2) NULL COMMENT 'Overdue payment (past due date)',
    os_90                 DECIMAL(15,2) NULL COMMENT 'Outstanding 91-150 days',
    os_150                DECIMAL(15,2) NULL COMMENT 'Outstanding over 150 days',
    remarks               VARCHAR(255) NULL,
    created_by            INT UNSIGNED NULL,
    updated_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rdt_employee_date (employee_id, entry_date),
    KEY idx_rdt_branch_date (branch_id, entry_date),
    KEY idx_rdt_fy (financial_year_id, entry_date),
    CONSTRAINT fk_rdt_fy       FOREIGN KEY (financial_year_id) REFERENCES financial_years(id),
    CONSTRAINT fk_rdt_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_rdt_branch   FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_rdt_created  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rdt_updated  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_rdt_money   CHECK (sales_value >= 0 AND collection_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission to fill in the daily sheet (month-start sheet uses targets.add / targets.edit)
INSERT INTO permissions (module, action, slug, description, is_critical, sort_order) VALUES
 ('daily_entry', 'view', 'daily_entry.view', 'View Daily Entry Sheet', 0, 211),
 ('daily_entry', 'add',  'daily_entry.add',  'Add Daily Entry Sheet',  0, 212),
 ('daily_entry', 'edit', 'daily_entry.edit', 'Edit Daily Entry Sheet', 0, 213);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.module = 'daily_entry'
WHERE r.slug IN ('admin_head', 'admin_coordinator', 'sales_manager', 'branch_manager', 'sales_executive')
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
