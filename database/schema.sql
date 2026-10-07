-- =============================================================================
-- MARKETING CRM - Database Schema (Phase 1 foundation)
-- -----------------------------------------------------------------------------
-- Target  : MySQL 8.0+   Engine: InnoDB   Charset: utf8mb4 / utf8mb4_unicode_ci
--
-- Design rules
--   * Transactions are the source of truth. Dashboard figures (sales, collection,
--     pending order, sample, DC, outstanding) are SUMs over these tables - no
--     manually-maintained totals.
--   * Money      -> DECIMAL(15,2)   Quantity -> DECIMAL(14,3)   Percent -> DECIMAL(5,2)
--   * Every transaction row stores branch_id + employee_id as a SNAPSHOT at the
--     time of the transaction, so moving a customer to another employee later
--     does not rewrite history.
--   * Every imported row keeps import_batch_id so any KPI can be traced back to
--     the spreadsheet row it came from.
--   * Business tables carry created_at / updated_at / created_by / updated_by and
--     soft delete (deleted_at / deleted_by) where users may delete records.
--   * MySQL 8 forbids CHECK constraints on columns used by FKs with SET NULL /
--     CASCADE actions, so CHECKs are only placed on plain value columns.
--
-- Run on an EMPTY database:  php cli/install.php   (see README.md)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. ACCESS CONTROL
-- =============================================================================

CREATE TABLE roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(60)  NOT NULL,
    slug            VARCHAR(60)  NOT NULL,
    description     VARCHAR(255) NULL,
    data_scope      ENUM('all','branch','team','own') NOT NULL DEFAULT 'own'
                    COMMENT 'Row-level visibility: all branches / assigned branches / reporting team / own records',
    is_system       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'System roles cannot be deleted',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_slug (slug),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module          VARCHAR(50)  NOT NULL COMMENT 'dashboard, branches, sales, hrm ...',
    action          VARCHAR(30)  NOT NULL COMMENT 'view, add, edit, delete, import, export, manage',
    slug            VARCHAR(85)  NOT NULL COMMENT 'module.action',
    description     VARCHAR(255) NULL,
    is_critical     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Only Admin Head should normally hold this',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_slug (slug),
    UNIQUE KEY uq_permissions_module_action (module, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id         INT UNSIGNED NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    granted_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_rp_permission (permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_granted_by FOREIGN KEY (granted_by)    REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id               INT UNSIGNED NOT NULL,
    employee_id           INT UNSIGNED NULL COMMENT 'Link to HRM employee (sales reps see their own data)',
    name                  VARCHAR(100) NOT NULL,
    username              VARCHAR(50)  NOT NULL,
    email                 VARCHAR(150) NOT NULL,
    mobile                VARCHAR(20)  NULL,
    password_hash         VARCHAR(255) NOT NULL COMMENT 'password_hash() output only',
    status                ENUM('active','disabled') NOT NULL DEFAULT 'active',
    must_change_password  TINYINT(1)   NOT NULL DEFAULT 0,
    password_changed_at   DATETIME     NULL,
    failed_login_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until          DATETIME     NULL,
    last_login_at         DATETIME     NULL,
    last_login_ip         VARCHAR(45)  NULL,
    created_by            INT UNSIGNED NULL,
    updated_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at            DATETIME     NULL,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_employee (employee_id),
    KEY idx_users_role (role_id),
    KEY idx_users_status (status, deleted_at),
    CONSTRAINT fk_users_role       FOREIGN KEY (role_id)    REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    -- fk_users_employee added after employees table
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user overrides on top of the role: grant an extra permission or deny one the role has.
CREATE TABLE user_permissions (
    user_id         INT UNSIGNED NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    effect          ENUM('grant','deny') NOT NULL DEFAULT 'grant',
    granted_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    KEY idx_up_permission (permission_id),
    CONSTRAINT fk_up_user       FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    CONSTRAINT fk_up_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_up_granted_by FOREIGN KEY (granted_by)    REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Branches a user may see when their role's data_scope = 'branch'.
CREATE TABLE user_branches (
    user_id         INT UNSIGNED NOT NULL,
    branch_id       INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, branch_id),
    KEY idx_ub_branch (branch_id),
    CONSTRAINT fk_ub_user   FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_ub_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier      VARCHAR(150) NOT NULL COMMENT 'username/email as typed (lower-cased)',
    ip_address      VARCHAR(45)  NOT NULL,
    user_agent      VARCHAR(255) NULL,
    success         TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_identifier_time (identifier, attempted_at),
    KEY idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mobile/API bearer tokens. Only a SHA-256 of the token is stored.
CREATE TABLE api_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL COMMENT 'Device / client label',
    token_hash      CHAR(64)     NOT NULL,
    last_used_at    DATETIME     NULL,
    last_used_ip    VARCHAR(45)  NULL,
    expires_at      DATETIME     NOT NULL,
    revoked_at      DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id, revoked_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. ORGANISATION / HRM
-- =============================================================================

-- Indian financial years. Application logic computes the FY for any date from
-- settings.fy_start_month; this table lets admins name, lock and select FYs.
CREATE TABLE financial_years (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label           VARCHAR(20)  NOT NULL COMMENT 'FY 2026-27',
    start_date      DATE         NOT NULL,
    end_date        DATE         NOT NULL,
    is_current      TINYINT(1)   NOT NULL DEFAULT 0,
    is_locked       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Locked years reject new/edited transactions',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fy_label (label),
    UNIQUE KEY uq_fy_start (start_date),
    KEY idx_fy_range (start_date, end_date),
    CONSTRAINT chk_fy_range CHECK (end_date > start_date),
    CONSTRAINT fk_fy_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fy_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branches (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_code         VARCHAR(20)  NOT NULL,
    name                VARCHAR(120) NOT NULL,
    address             VARCHAR(255) NULL,
    city                VARCHAR(80)  NULL,
    state               VARCHAR(80)  NULL,
    pincode             VARCHAR(10)  NULL,
    contact_number      VARCHAR(20)  NULL,
    email               VARCHAR(150) NULL,
    manager_employee_id INT UNSIGNED NULL,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    UNIQUE KEY uq_branches_code (branch_code),
    UNIQUE KEY uq_branches_name (name),
    KEY idx_branches_status (status, deleted_at),
    KEY idx_branches_city (city),
    CONSTRAINT fk_branches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_branches_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_branches_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
    -- fk_branches_manager added after employees table
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_departments_name (name),
    CONSTRAINT fk_dept_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_dept_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE designations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_designations_name (name),
    CONSTRAINT fk_desig_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_desig_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employees (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_code       VARCHAR(20)  NOT NULL,
    name                VARCHAR(120) NOT NULL,
    short_name          VARCHAR(40)  NULL COMMENT 'Shown on dashboard rep boxes e.g. JANA',
    mobile              VARCHAR(20)  NULL,
    email               VARCHAR(150) NULL,
    date_of_birth       DATE         NULL,
    branch_id           INT UNSIGNED NOT NULL,
    department_id       INT UNSIGNED NULL,
    designation_id      INT UNSIGNED NULL,
    reporting_manager_id INT UNSIGNED NULL,
    joining_date        DATE         NULL,
    relieving_date      DATE         NULL,
    is_sales_rep        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Appears in Sales Representative section',
    sales_role          ENUM('manager','sales_executive','sales_support','sales_coordinator') NULL
                        COMMENT 'Group on HRM -> Sales person details',
    area                VARCHAR(80)  NULL COMMENT 'Sales area, e.g. Trichy',
    coordinator_id      INT UNSIGNED NULL COMMENT 'Sales coordinator for this sales person',
    status              ENUM('active','inactive','resigned') NOT NULL DEFAULT 'active',
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    UNIQUE KEY uq_employees_code (employee_code),
    KEY idx_employees_name (name),
    KEY idx_employees_mobile (mobile),
    KEY idx_employees_branch (branch_id, status),
    KEY idx_employees_sales_rep (is_sales_rep, status, deleted_at),
    KEY idx_employees_manager (reporting_manager_id),
    KEY idx_employees_role (sales_role, branch_id),
    CONSTRAINT fk_emp_branch      FOREIGN KEY (branch_id)            REFERENCES branches(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_emp_department  FOREIGN KEY (department_id)        REFERENCES departments(id)  ON DELETE SET NULL,
    CONSTRAINT fk_emp_designation FOREIGN KEY (designation_id)       REFERENCES designations(id) ON DELETE SET NULL,
    CONSTRAINT fk_emp_manager     FOREIGN KEY (reporting_manager_id) REFERENCES employees(id)    ON DELETE SET NULL,
    CONSTRAINT fk_emp_coordinator FOREIGN KEY (coordinator_id)       REFERENCES employees(id)    ON DELETE SET NULL,
    CONSTRAINT fk_emp_created_by  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_emp_updated_by  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_emp_deleted_by  FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL;
ALTER TABLE branches
    ADD CONSTRAINT fk_branches_manager FOREIGN KEY (manager_employee_id) REFERENCES employees(id) ON DELETE SET NULL;

-- =============================================================================
-- 3. MASTER DATA: PRODUCTS, CUSTOMERS
-- =============================================================================

CREATE TABLE products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_code    VARCHAR(40)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    category        VARCHAR(80)  NULL,
    unit            VARCHAR(20)  NOT NULL DEFAULT 'Nos',
    hsn_code        VARCHAR(12)  NULL,
    rate            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gst_rate        DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percent',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME     NULL,
    deleted_by      INT UNSIGNED NULL,
    UNIQUE KEY uq_products_code (product_code),
    KEY idx_products_name (name),
    KEY idx_products_category (category),
    FULLTEXT KEY ft_products_search (product_code, name),
    KEY idx_products_status (status, deleted_at),
    CONSTRAINT chk_products_rate CHECK (rate >= 0),
    CONSTRAINT chk_products_gst  CHECK (gst_rate >= 0 AND gst_rate <= 100),
    CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_code       VARCHAR(30)  NOT NULL,
    name                VARCHAR(150) NOT NULL,
    company_name        VARCHAR(150) NULL,
    mobile              VARCHAR(20)  NULL,
    alternate_mobile    VARCHAR(20)  NULL,
    email               VARCHAR(150) NULL,
    address             VARCHAR(255) NULL,
    city                VARCHAR(80)  NULL,
    state               VARCHAR(80)  NULL,
    pincode             VARCHAR(10)  NULL,
    gstin               VARCHAR(15)  NULL,
    branch_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NULL COMMENT 'Currently assigned sales employee',
    credit_days         SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Used for due date when invoice has none',
    credit_limit        DECIMAL(15,2) NULL,
    status              ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    sms_opt_out         TINYINT(1)   NOT NULL DEFAULT 0,
    lead_id             INT UNSIGNED NULL COMMENT 'Lead this customer was converted from',
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    UNIQUE KEY uq_customers_code (customer_code),
    KEY idx_customers_name (name),
    KEY idx_customers_company (company_name),
    FULLTEXT KEY ft_customers_search (customer_code, name, company_name),
    KEY idx_customers_mobile (mobile),
    KEY idx_customers_email (email),
    KEY idx_customers_gstin (gstin),
    KEY idx_customers_branch (branch_id, status, deleted_at),
    KEY idx_customers_employee (employee_id, status, deleted_at),
    KEY idx_customers_city (city),
    CONSTRAINT chk_customers_credit_limit CHECK (credit_limit IS NULL OR credit_limit >= 0),
    CONSTRAINT fk_customers_branch     FOREIGN KEY (branch_id)   REFERENCES branches(id)  ON DELETE RESTRICT,
    CONSTRAINT fk_customers_employee   FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_customers_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_customers_deleted_by FOREIGN KEY (deleted_by)  REFERENCES users(id) ON DELETE SET NULL
    -- fk_customers_lead added after leads table
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. IMPORTS (declared early: transaction tables reference import_batches)
-- =============================================================================

CREATE TABLE import_batches (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module              ENUM('sales','collections','pending_orders','samples','dc','outstanding',
                             'targets','customers','products','leads','employees') NOT NULL,
    original_filename   VARCHAR(255) NOT NULL COMMENT 'Display only - never used as a path',
    stored_filename     VARCHAR(100) NOT NULL COMMENT 'Random server-side name under uploads/imports',
    file_sha256         CHAR(64)     NOT NULL COMMENT 'Detects re-upload of the same file',
    file_size           INT UNSIGNED NOT NULL,
    column_mapping      JSON         NULL COMMENT '{"Customer":"customer_code", ...}',
    status              ENUM('uploaded','mapped','validated','importing','completed','failed','cancelled') NOT NULL DEFAULT 'uploaded',
    total_rows          INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows          INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_rows        INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows       INT UNSIGNED NOT NULL DEFAULT 0,
    error_message       VARCHAR(500) NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at        DATETIME     NULL,
    KEY idx_ib_module (module, created_at),
    KEY idx_ib_sha (file_sha256),
    KEY idx_ib_status (status),
    CONSTRAINT fk_ib_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_rows (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id        INT UNSIGNED NOT NULL,
    row_no          INT UNSIGNED NOT NULL COMMENT 'Spreadsheet row number (header = 1)',
    raw_data        JSON         NOT NULL,
    mapped_data     JSON         NULL,
    status          ENUM('pending','valid','invalid','duplicate','imported','skipped') NOT NULL DEFAULT 'pending',
    errors          JSON         NULL COMMENT '["Invalid date in Order Date", ...]',
    record_table    VARCHAR(50)  NULL,
    record_id       BIGINT UNSIGNED NULL COMMENT 'Row created/updated by this import row',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ir_batch_row (batch_id, row_no),
    KEY idx_ir_status (batch_id, status),
    CONSTRAINT fk_ir_batch FOREIGN KEY (batch_id) REFERENCES import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. TARGETS
-- =============================================================================

-- One row per employee per month. Annual target = SUM over the FY's months,
-- branch target = SUM over the branch's employees. No separately stored totals.
CREATE TABLE sales_targets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id   INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NOT NULL,
    branch_id           INT UNSIGNED NOT NULL COMMENT 'Snapshot of employee branch for this month',
    target_month        DATE         NOT NULL COMMENT 'Always the 1st of the month',
    sales_target        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    collection_target   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    remarks             VARCHAR(255) NULL,
    import_batch_id     INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_targets_employee_month (employee_id, target_month),
    KEY idx_targets_fy (financial_year_id, employee_id),
    KEY idx_targets_branch (branch_id, target_month),
    CONSTRAINT chk_targets_first_day CHECK (DAYOFMONTH(target_month) = 1),
    CONSTRAINT chk_targets_amounts   CHECK (sales_target >= 0 AND collection_target >= 0),
    CONSTRAINT fk_targets_fy         FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_targets_employee   FOREIGN KEY (employee_id)       REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_targets_branch     FOREIGN KEY (branch_id)         REFERENCES branches(id)        ON DELETE RESTRICT,
    CONSTRAINT fk_targets_import     FOREIGN KEY (import_batch_id)   REFERENCES import_batches(id)  ON DELETE SET NULL,
    CONSTRAINT fk_targets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_targets_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Daily entry sheets: totals per sales representative (Branch Performance dashboard).
-- rep_month_openings once a month (opening outstanding; targets live in sales_targets),
-- rep_daily_totals each working day (flows add up; NULL position fields = not updated that day).
-- Annual targets by division / sales area / sales employee (Targets tab; month = annual / 12)
CREATE TABLE divisions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_divisions_name (name),
    CONSTRAINT fk_div_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_div_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_areas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL COMMENT 'Matches employees.area',
    branch_id       INT UNSIGNED NULL COMMENT 'Branch the area belongs to',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_areas_name (name),
    CONSTRAINT fk_area_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_area_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_area_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE annual_targets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id   INT UNSIGNED NOT NULL,
    level               ENUM('division','area','employee','branch') NOT NULL,
    division_id         INT UNSIGNED NULL,
    branch_id           INT UNSIGNED NULL,
    area_id             INT UNSIGNED NULL,
    employee_id         INT UNSIGNED NULL,
    annual_target       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    scope_key           VARCHAR(60) AS (CONCAT(level, ':', IFNULL(division_id, 0), ':', IFNULL(area_id, 0), ':', IFNULL(employee_id, 0), ':', IFNULL(branch_id, 0))) STORED,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_annual_target (financial_year_id, scope_key),
    KEY idx_at_fy (financial_year_id),
    KEY idx_at_employee (employee_id),
    CONSTRAINT chk_at_value CHECK (annual_target >= 0),
    CONSTRAINT fk_at_fy       FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_at_division FOREIGN KEY (division_id)       REFERENCES divisions(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_at_branch   FOREIGN KEY (branch_id)         REFERENCES branches(id)        ON DELETE RESTRICT,
    CONSTRAINT fk_at_area     FOREIGN KEY (area_id)           REFERENCES sales_areas(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_at_employee FOREIGN KEY (employee_id)       REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_at_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_at_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- =============================================================================
-- 6. SALES (invoices = source of truth for Sales KPIs)
-- =============================================================================

CREATE TABLE sales_invoices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id   INT UNSIGNED NOT NULL,
    document_type       ENUM('invoice','credit_note') NOT NULL DEFAULT 'invoice'
                        COMMENT 'Credit notes are stored positive and subtracted in reports',
    invoice_no          VARCHAR(40)  NOT NULL,
    customer_po_no      VARCHAR(60)  NULL COMMENT 'Customer PO reference',
    invoice_date        DATE         NOT NULL,
    due_date            DATE         NULL COMMENT 'NULL = invoice_date + customer.credit_days',
    customer_id         INT UNSIGNED NOT NULL,
    branch_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NULL,
    reference_invoice_id INT UNSIGNED NULL COMMENT 'Credit note -> original invoice',
    taxable_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    round_off           DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    total_amount        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status              ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    source              ENUM('manual','import','api') NOT NULL DEFAULT 'manual',
    import_batch_id     INT UNSIGNED NULL,
    remarks             VARCHAR(500) NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    UNIQUE KEY uq_si_fy_doc (financial_year_id, document_type, invoice_no),
    KEY idx_si_invoice_no (invoice_no),
    KEY idx_si_date (invoice_date, status),
    KEY idx_si_employee_date (employee_id, invoice_date),
    KEY idx_si_branch_date (branch_id, invoice_date),
    KEY idx_si_customer_date (customer_id, invoice_date),
    KEY idx_si_due (due_date),
    CONSTRAINT chk_si_amounts CHECK (taxable_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0),
    CONSTRAINT fk_si_fy         FOREIGN KEY (financial_year_id)    REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_si_customer   FOREIGN KEY (customer_id)          REFERENCES customers(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_si_branch     FOREIGN KEY (branch_id)            REFERENCES branches(id)        ON DELETE RESTRICT,
    CONSTRAINT fk_si_employee   FOREIGN KEY (employee_id)          REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_si_reference  FOREIGN KEY (reference_invoice_id) REFERENCES sales_invoices(id)  ON DELETE RESTRICT,
    CONSTRAINT fk_si_import     FOREIGN KEY (import_batch_id)      REFERENCES import_batches(id)  ON DELETE SET NULL,
    CONSTRAINT fk_si_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_si_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_si_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_invoice_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    quantity        DECIMAL(14,3) NOT NULL,
    rate            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    taxable_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gst_rate        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sii_invoice (invoice_id),
    KEY idx_sii_product (product_id),
    CONSTRAINT chk_sii_qty     CHECK (quantity > 0),
    CONSTRAINT chk_sii_amounts CHECK (rate >= 0 AND discount_amount >= 0 AND taxable_amount >= 0 AND tax_amount >= 0 AND line_total >= 0),
    CONSTRAINT fk_sii_invoice FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_sii_product FOREIGN KEY (product_id) REFERENCES products(id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. COLLECTIONS & OUTSTANDING
-- =============================================================================

CREATE TABLE collections (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id   INT UNSIGNED NOT NULL,
    receipt_no          VARCHAR(40)  NOT NULL,
    receipt_date        DATE         NOT NULL,
    customer_id         INT UNSIGNED NOT NULL,
    branch_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NULL,
    amount              DECIMAL(15,2) NOT NULL,
    payment_mode        ENUM('neft','rtgs','imps','upi','cheque','cash','dd','other') NOT NULL DEFAULT 'neft',
    reference_no        VARCHAR(60)  NULL COMMENT 'UTR / cheque number',
    bank_name           VARCHAR(100) NULL,
    status              ENUM('received','cleared','bounced','cancelled') NOT NULL DEFAULT 'cleared'
                        COMMENT 'KPIs exclude bounced/cancelled',
    source              ENUM('manual','import','api','email') NOT NULL DEFAULT 'manual',
    import_batch_id     INT UNSIGNED NULL,
    remarks             VARCHAR(500) NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    UNIQUE KEY uq_col_fy_receipt (financial_year_id, receipt_no),
    KEY idx_col_date (receipt_date, status),
    KEY idx_col_employee_date (employee_id, receipt_date),
    KEY idx_col_branch_date (branch_id, receipt_date),
    KEY idx_col_customer_date (customer_id, receipt_date),
    KEY idx_col_reference (reference_no),
    CONSTRAINT chk_col_amount CHECK (amount > 0),
    CONSTRAINT fk_col_fy         FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_col_customer   FOREIGN KEY (customer_id)       REFERENCES customers(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_col_branch     FOREIGN KEY (branch_id)         REFERENCES branches(id)        ON DELETE RESTRICT,
    CONSTRAINT fk_col_employee   FOREIGN KEY (employee_id)       REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_col_import     FOREIGN KEY (import_batch_id)   REFERENCES import_batches(id)  ON DELETE SET NULL,
    CONSTRAINT fk_col_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_col_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_col_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bill-wise settlement: which receipt paid which invoice.
-- Computed outstanding = invoice total - SUM(allocations on active receipts).
CREATE TABLE collection_allocations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collection_id   INT UNSIGNED NOT NULL,
    invoice_id      INT UNSIGNED NOT NULL,
    amount          DECIMAL(15,2) NOT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ca_collection_invoice (collection_id, invoice_id),
    KEY idx_ca_invoice (invoice_id),
    CONSTRAINT chk_ca_amount CHECK (amount > 0),
    CONSTRAINT fk_ca_collection FOREIGN KEY (collection_id) REFERENCES collections(id)    ON DELETE CASCADE,
    CONSTRAINT fk_ca_invoice    FOREIGN KEY (invoice_id)    REFERENCES sales_invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ca_created_by FOREIGN KEY (created_by)    REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bill-wise outstanding statement imported from the accounting system (e.g. Tally)
-- as on a given date. Used when settings outstanding.source = 'imported'.
-- Each import replaces the snapshot for its as_on_date; nothing is summed across snapshots.
CREATE TABLE outstanding_bills (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    as_on_date          DATE         NOT NULL,
    customer_id         INT UNSIGNED NOT NULL,
    branch_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NULL,
    invoice_no          VARCHAR(40)  NOT NULL,
    invoice_date        DATE         NOT NULL,
    due_date            DATE         NULL,
    bill_amount         DECIMAL(15,2) NOT NULL,
    pending_amount      DECIMAL(15,2) NOT NULL,
    import_batch_id     INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ob_snapshot_bill (as_on_date, customer_id, invoice_no),
    KEY idx_ob_employee (as_on_date, employee_id),
    KEY idx_ob_branch (as_on_date, branch_id),
    KEY idx_ob_invoice_date (as_on_date, invoice_date),
    CONSTRAINT chk_ob_amounts CHECK (bill_amount >= 0 AND pending_amount >= 0 AND pending_amount <= bill_amount),
    CONSTRAINT fk_ob_customer   FOREIGN KEY (customer_id)     REFERENCES customers(id)      ON DELETE RESTRICT,
    CONSTRAINT fk_ob_branch     FOREIGN KEY (branch_id)       REFERENCES branches(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_ob_employee   FOREIGN KEY (employee_id)     REFERENCES employees(id)      ON DELETE RESTRICT,
    CONSTRAINT fk_ob_import     FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_ob_created_by FOREIGN KEY (created_by)      REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. PENDING ORDERS
-- =============================================================================

CREATE TABLE pending_orders (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id       INT UNSIGNED NOT NULL,
    order_no                VARCHAR(40)  NOT NULL,
    order_date              DATE         NOT NULL,
    customer_po_no          VARCHAR(60)  NULL,
    reference_type          ENUM('po','mail','phone','advance') NULL COMMENT 'How the order came',
    reference_detail        VARCHAR(255) NULL,
    advance_amount          DECIMAL(15,2) NULL,
    customer_id             INT UNSIGNED NOT NULL,
    branch_id               INT UNSIGNED NOT NULL,
    employee_id             INT UNSIGNED NULL,
    informed_by             ENUM('manager','rep') NULL,
    informed_employee_id    INT UNSIGNED NULL,
    expected_delivery_date  DATE         NULL,
    status                  ENUM('open','partial','closed','cancelled') NOT NULL DEFAULT 'open',
    source                  ENUM('manual','import','api','email') NOT NULL DEFAULT 'manual',
    import_batch_id         INT UNSIGNED NULL,
    remarks                 VARCHAR(500) NULL,
    created_by              INT UNSIGNED NULL,
    updated_by              INT UNSIGNED NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME     NULL,
    deleted_by              INT UNSIGNED NULL,
    CONSTRAINT fk_po_informed FOREIGN KEY (informed_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    UNIQUE KEY uq_po_fy_order (financial_year_id, order_no),
    KEY idx_po_order_no (order_no),
    KEY idx_po_status_date (status, order_date),
    KEY idx_po_employee (employee_id, status),
    KEY idx_po_branch (branch_id, status),
    KEY idx_po_customer (customer_id, status),
    CONSTRAINT fk_po_fy         FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_customer   FOREIGN KEY (customer_id)       REFERENCES customers(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_po_branch     FOREIGN KEY (branch_id)         REFERENCES branches(id)        ON DELETE RESTRICT,
    CONSTRAINT fk_po_employee   FOREIGN KEY (employee_id)       REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_po_import     FOREIGN KEY (import_batch_id)   REFERENCES import_batches(id)  ON DELETE SET NULL,
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_po_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_po_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pending_value / pending_qty are GENERATED - they can never drift from order - supplied.
CREATE TABLE pending_order_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    order_qty       DECIMAL(14,3) NOT NULL,
    supplied_qty    DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    pending_qty     DECIMAL(14,3) AS (order_qty - supplied_qty) STORED,
    rate            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    order_value     DECIMAL(15,2) NOT NULL,
    supplied_value  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    pending_value   DECIMAL(15,2) AS (order_value - supplied_value) STORED,
    remarks         VARCHAR(255) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_poi_order_product (order_id, product_id),
    KEY idx_poi_product (product_id),
    CONSTRAINT chk_poi_qty   CHECK (order_qty > 0 AND supplied_qty >= 0 AND supplied_qty <= order_qty),
    CONSTRAINT chk_poi_value CHECK (order_value >= 0 AND supplied_value >= 0 AND supplied_value <= order_value),
    CONSTRAINT fk_poi_order   FOREIGN KEY (order_id)   REFERENCES pending_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. SAMPLES & DELIVERY CHALLANS (DC)
-- =============================================================================

CREATE TABLE samples (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id   INT UNSIGNED NOT NULL,
    document_no         VARCHAR(40)  NOT NULL,
    document_date       DATE         NOT NULL,
    customer_id         INT UNSIGNED NOT NULL,
    branch_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NULL,
    supply_status       ENUM('not_supplied','partially_supplied','supplied') NOT NULL DEFAULT 'not_supplied',
    sample_type         ENUM('returnable','non_returnable') NULL,
    approval_status     ENUM('requested','approved','rejected') NOT NULL DEFAULT 'approved' COMMENT 'Manager approval; only approved samples count',
    approved_by         INT UNSIGNED NULL,
    approved_at         DATETIME     NULL,
    approval_note       VARCHAR(255) NULL,
    pending_status      ENUM('pending','approved','rejected','converted','returned','closed') NOT NULL DEFAULT 'pending'
                        COMMENT 'pending = awaiting customer outcome',
    import_batch_id     INT UNSIGNED NULL,
    remarks             VARCHAR(500) NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    CONSTRAINT fk_samples_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_samples_fy_doc (financial_year_id, document_no),
    KEY idx_samples_status (pending_status, document_date),
    KEY idx_samples_employee (employee_id, pending_status),
    KEY idx_samples_branch (branch_id, pending_status),
    KEY idx_samples_customer (customer_id),
    CONSTRAINT fk_samples_fy         FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_samples_customer   FOREIGN KEY (customer_id)       REFERENCES customers(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_samples_branch     FOREIGN KEY (branch_id)         REFERENCES branches(id)        ON DELETE RESTRICT,
    CONSTRAINT fk_samples_employee   FOREIGN KEY (employee_id)       REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_samples_import     FOREIGN KEY (import_batch_id)   REFERENCES import_batches(id)  ON DELETE SET NULL,
    CONSTRAINT fk_samples_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_samples_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_samples_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sample_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sample_id       INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    quantity        DECIMAL(14,3) NOT NULL,
    sample_value    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sample_items (sample_id, product_id),
    KEY idx_sample_items_product (product_id),
    CONSTRAINT chk_sample_items CHECK (quantity > 0 AND sample_value >= 0),
    CONSTRAINT fk_sample_items_sample  FOREIGN KEY (sample_id)  REFERENCES samples(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sample_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dc_records (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id   INT UNSIGNED NOT NULL,
    dc_no               VARCHAR(40)  NOT NULL,
    dc_date             DATE         NOT NULL,
    sample_id           INT UNSIGNED NULL COMMENT 'Sample this DC supplied, if any',
    order_id            INT UNSIGNED NULL,
    customer_id         INT UNSIGNED NOT NULL,
    branch_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NULL,
    supply_status       ENUM('not_supplied','partially_supplied','supplied') NOT NULL DEFAULT 'supplied',
    pending_status      ENUM('pending','invoiced','returned','closed') NOT NULL DEFAULT 'pending'
                        COMMENT 'pending = goods out on DC, not yet invoiced or returned',
    approval_type       ENUM('customer_mail','md_approval') NULL COMMENT 'Customer mail or M.D approval mail',
    approval_reference  VARCHAR(255) NULL,
    invoice_id          INT UNSIGNED NULL COMMENT 'Invoice that cleared this DC',
    import_batch_id     INT UNSIGNED NULL,
    remarks             VARCHAR(500) NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    CONSTRAINT fk_dc_order FOREIGN KEY (order_id) REFERENCES pending_orders(id) ON DELETE SET NULL,
    UNIQUE KEY uq_dc_fy_no (financial_year_id, dc_no),
    KEY idx_dc_status (pending_status, dc_date),
    KEY idx_dc_employee (employee_id, pending_status),
    KEY idx_dc_branch (branch_id, pending_status),
    KEY idx_dc_customer (customer_id),
    CONSTRAINT fk_dc_fy         FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dc_sample     FOREIGN KEY (sample_id)         REFERENCES samples(id)         ON DELETE SET NULL,
    CONSTRAINT fk_dc_customer   FOREIGN KEY (customer_id)       REFERENCES customers(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_dc_branch     FOREIGN KEY (branch_id)         REFERENCES branches(id)        ON DELETE RESTRICT,
    CONSTRAINT fk_dc_employee   FOREIGN KEY (employee_id)       REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_dc_invoice    FOREIGN KEY (invoice_id)        REFERENCES sales_invoices(id)  ON DELETE SET NULL,
    CONSTRAINT fk_dc_import     FOREIGN KEY (import_batch_id)   REFERENCES import_batches(id)  ON DELETE SET NULL,
    CONSTRAINT fk_dc_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_dc_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_dc_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dc_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dc_id           INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    quantity        DECIMAL(14,3) NOT NULL,
    dc_value        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dc_items (dc_id, product_id),
    KEY idx_dc_items_product (product_id),
    CONSTRAINT chk_dc_items CHECK (quantity > 0 AND dc_value >= 0),
    CONSTRAINT fk_dc_items_dc      FOREIGN KEY (dc_id)      REFERENCES dc_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_dc_items_product FOREIGN KEY (product_id) REFERENCES products(id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 10. LEADS & FOLLOW-UPS
-- =============================================================================

CREATE TABLE lead_sources (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lead_sources_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_number         VARCHAR(20)  NOT NULL,
    record_type         ENUM('lead','enquiry') NOT NULL DEFAULT 'lead',
    lead_type           ENUM('new_customer','new_product') NOT NULL DEFAULT 'new_customer',
    name                VARCHAR(150) NOT NULL,
    company_name        VARCHAR(150) NULL,
    contact_person      VARCHAR(150) NULL,
    mobile              VARCHAR(20)  NULL,
    email               VARCHAR(150) NULL,
    source_id           INT UNSIGNED NULL,
    enquiry_source      ENUM('mail','office_visit','phone','other') NULL,
    valid_until         DATE NULL COMMENT 'Enquiry / offer expiry date',
    product_id          INT UNSIGNED NULL,
    branch_id           INT UNSIGNED NOT NULL,
    employee_id         INT UNSIGNED NULL,
    informed_by         ENUM('manager','rep') NULL,
    informed_employee_id INT UNSIGNED NULL,
    status              ENUM('new','contacted','interested','follow_up','quotation','negotiation','won','lost') NOT NULL DEFAULT 'new',
    priority            ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    expected_value      DECIMAL(15,2) NULL,
    next_followup_at    DATETIME     NULL,
    lost_reason         VARCHAR(255) NULL,
    remarks             TEXT         NULL,
    customer_id         INT UNSIGNED NULL COMMENT 'Set when converted / linked to a customer',
    email_message_id    BIGINT UNSIGNED NULL COMMENT 'Email that created this lead',
    import_batch_id     INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    KEY idx_leads_type (record_type, status),
    CONSTRAINT fk_leads_informed FOREIGN KEY (informed_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    UNIQUE KEY uq_leads_number (lead_number),
    KEY idx_leads_name (name),
    KEY idx_leads_company (company_name),
    FULLTEXT KEY ft_leads_search (lead_number, name, company_name),
    KEY idx_leads_mobile (mobile),
    KEY idx_leads_email (email),
    KEY idx_leads_status (status, deleted_at),
    KEY idx_leads_employee (employee_id, status),
    KEY idx_leads_branch (branch_id, status),
    KEY idx_leads_followup (next_followup_at),
    KEY idx_leads_created (created_at),
    CONSTRAINT chk_leads_value CHECK (expected_value IS NULL OR expected_value >= 0),
    CONSTRAINT fk_leads_source     FOREIGN KEY (source_id)       REFERENCES lead_sources(id)   ON DELETE SET NULL,
    CONSTRAINT fk_leads_product    FOREIGN KEY (product_id)      REFERENCES products(id)       ON DELETE SET NULL,
    CONSTRAINT fk_leads_branch     FOREIGN KEY (branch_id)       REFERENCES branches(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_leads_employee   FOREIGN KEY (employee_id)     REFERENCES employees(id)      ON DELETE SET NULL,
    CONSTRAINT fk_leads_customer   FOREIGN KEY (customer_id)     REFERENCES customers(id)      ON DELETE SET NULL,
    CONSTRAINT fk_leads_import     FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
    -- fk_leads_email added after email_messages table
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

ALTER TABLE customers
    ADD CONSTRAINT fk_customers_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL;

CREATE TABLE followups (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id             INT UNSIGNED NULL,
    customer_id         INT UNSIGNED NULL,
    employee_id         INT UNSIGNED NULL,
    followup_at         DATETIME     NOT NULL,
    followup_type       ENUM('call','visit','email','whatsapp','sms','meeting','other') NOT NULL DEFAULT 'call',
    purpose             ENUM('sales','collection','order','sample','general') NOT NULL DEFAULT 'sales',
    notes               VARCHAR(1000) NULL,
    status              ENUM('pending','completed','rescheduled','cancelled','missed') NOT NULL DEFAULT 'pending',
    outcome             VARCHAR(1000) NULL,
    completed_at        DATETIME     NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    KEY idx_fu_due (status, followup_at),
    KEY idx_fu_employee_due (employee_id, status, followup_at),
    KEY idx_fu_lead (lead_id),
    KEY idx_fu_customer (customer_id),
    CONSTRAINT chk_fu_party CHECK (lead_id IS NOT NULL OR customer_id IS NOT NULL),
    CONSTRAINT fk_fu_lead       FOREIGN KEY (lead_id)     REFERENCES leads(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_fu_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fu_employee   FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 11. MAIL (provider-agnostic: IMAP / Gmail API / Microsoft Graph / manual)
-- =============================================================================

-- Credentials are NEVER stored here: credentials_ref names an .env key prefix
-- (e.g. MAIL_SALES -> MAIL_SALES_USER / MAIL_SALES_PASS / OAuth token file).
CREATE TABLE email_accounts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_address       VARCHAR(150) NOT NULL,
    display_name        VARCHAR(100) NULL,
    provider            ENUM('manual','imap','gmail','microsoft_graph') NOT NULL DEFAULT 'manual',
    credentials_ref     VARCHAR(60)  NULL,
    branch_id           INT UNSIGNED NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    sync_cursor         VARCHAR(255) NULL COMMENT 'Provider history id / UID watermark',
    last_synced_at      DATETIME     NULL,
    last_sync_error     VARCHAR(500) NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_accounts_address (email_address),
    CONSTRAINT fk_ea_branch     FOREIGN KEY (branch_id)  REFERENCES branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_ea_created_by FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_ea_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL COMMENT 'new_enquiry, order, new_lead, payment_advice, other',
    name            VARCHAR(60)  NOT NULL,
    keywords        JSON         NULL COMMENT 'Rule-based classifier terms: [{"term":"purchase order","weight":3}]',
    color           VARCHAR(20)  NOT NULL DEFAULT 'slate',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    is_fallback     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Used when no rule matches (OTHER)',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_messages (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id              INT UNSIGNED NOT NULL,
    provider_message_id     VARCHAR(255) NOT NULL COMMENT 'IMAP UID / Gmail id / Graph id',
    internet_message_id     VARCHAR(255) NULL COMMENT 'RFC Message-ID header',
    thread_id               VARCHAR(255) NULL,
    from_email              VARCHAR(150) NOT NULL,
    from_name               VARCHAR(150) NULL,
    to_recipients           VARCHAR(1000) NULL,
    subject                 VARCHAR(500) NULL,
    body_preview            VARCHAR(1000) NULL COMMENT 'Plain-text excerpt; full body is not stored by default',
    received_at             DATETIME     NOT NULL,
    has_attachments         TINYINT(1)   NOT NULL DEFAULT 0,
    category_id             INT UNSIGNED NULL,
    classification_method   ENUM('rule','manual','ai','none') NOT NULL DEFAULT 'none',
    classification_confidence DECIMAL(5,2) NULL COMMENT '0-100',
    auto_category_id        INT UNSIGNED NULL COMMENT 'What the classifier originally chose (kept after manual correction)',
    category_corrected_by   INT UNSIGNED NULL,
    category_corrected_at   DATETIME     NULL,
    customer_id             INT UNSIGNED NULL,
    lead_id                 INT UNSIGNED NULL,
    branch_id               INT UNSIGNED NULL,
    employee_id             INT UNSIGNED NULL,
    status                  ENUM('new','open','in_progress','closed','ignored') NOT NULL DEFAULT 'new',
    followup_status         ENUM('none','pending','done') NOT NULL DEFAULT 'none',
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_em_provider (account_id, provider_message_id),
    KEY idx_em_received (received_at),
    KEY idx_em_category_date (category_id, received_at),
    KEY idx_em_status (status, received_at),
    KEY idx_em_employee (employee_id, received_at),
    KEY idx_em_branch (branch_id, received_at),
    KEY idx_em_customer (customer_id),
    KEY idx_em_from (from_email),
    KEY idx_em_internet_id (internet_message_id),
    CONSTRAINT chk_em_confidence CHECK (classification_confidence IS NULL OR (classification_confidence >= 0 AND classification_confidence <= 100)),
    CONSTRAINT fk_em_account      FOREIGN KEY (account_id)            REFERENCES email_accounts(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_em_category     FOREIGN KEY (category_id)           REFERENCES email_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_em_auto_cat     FOREIGN KEY (auto_category_id)      REFERENCES email_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_em_corrected_by FOREIGN KEY (category_corrected_by) REFERENCES users(id)            ON DELETE SET NULL,
    CONSTRAINT fk_em_customer     FOREIGN KEY (customer_id)           REFERENCES customers(id)        ON DELETE SET NULL,
    CONSTRAINT fk_em_lead         FOREIGN KEY (lead_id)               REFERENCES leads(id)            ON DELETE SET NULL,
    CONSTRAINT fk_em_branch       FOREIGN KEY (branch_id)             REFERENCES branches(id)         ON DELETE SET NULL,
    CONSTRAINT fk_em_employee     FOREIGN KEY (employee_id)           REFERENCES employees(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE leads
    ADD CONSTRAINT fk_leads_email FOREIGN KEY (email_message_id) REFERENCES email_messages(id) ON DELETE SET NULL;

CREATE TABLE email_assignments (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id        BIGINT UNSIGNED NOT NULL,
    assigned_to     INT UNSIGNED NOT NULL,
    assigned_by     INT UNSIGNED NULL,
    note            VARCHAR(500) NULL,
    is_current      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_eas_email (email_id, is_current),
    KEY idx_eas_user (assigned_to, is_current),
    CONSTRAINT fk_eas_email       FOREIGN KEY (email_id)    REFERENCES email_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_eas_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id)          ON DELETE CASCADE,
    CONSTRAINT fk_eas_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_attachments (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id        BIGINT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL COMMENT 'Display only',
    stored_name     VARCHAR(100) NULL COMMENT 'Random name under uploads/attachments; NULL = not downloaded',
    mime_type       VARCHAR(100) NULL,
    size_bytes      INT UNSIGNED NULL,
    sha256          CHAR(64)     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_eatt_email (email_id),
    CONSTRAINT fk_eatt_email FOREIGN KEY (email_id) REFERENCES email_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_activity (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id        BIGINT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(40)  NOT NULL COMMENT 'received, classified, recategorised, assigned, status_changed, lead_created ...',
    old_value       VARCHAR(255) NULL,
    new_value       VARCHAR(255) NULL,
    note            VARCHAR(500) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_eact_email (email_id, created_at),
    CONSTRAINT fk_eact_email FOREIGN KEY (email_id) REFERENCES email_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_eact_user  FOREIGN KEY (user_id)  REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 12. SMS (provider-agnostic; default gateway = mock, never sends real SMS)
-- =============================================================================

CREATE TABLE sms_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    category        ENUM('transactional','promotional','service','otp') NOT NULL DEFAULT 'transactional',
    body            TEXT         NOT NULL COMMENT 'Plain text with {placeholders}; never evaluated as code',
    sender_id       VARCHAR(20)  NULL,
    dlt_template_id VARCHAR(50)  NULL COMMENT 'India DLT template id',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME     NULL,
    UNIQUE KEY uq_sms_templates_name (name),
    CONSTRAINT fk_smst_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_smst_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sms_campaigns (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150) NOT NULL,
    template_id       INT UNSIGNED NULL,
    message_body      TEXT         NOT NULL COMMENT 'Snapshot of template body at creation',
    target_type       ENUM('customers','leads','employees','custom') NOT NULL,
    target_filter     JSON         NULL COMMENT '{"branch_id":2,"status":"active"}',
    total_recipients  INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count        INT UNSIGNED NOT NULL DEFAULT 0,
    delivered_count   INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count      INT UNSIGNED NOT NULL DEFAULT 0,
    scheduled_at      DATETIME     NULL,
    started_at        DATETIME     NULL,
    completed_at      DATETIME     NULL,
    status            ENUM('draft','scheduled','processing','completed','cancelled','failed') NOT NULL DEFAULT 'draft',
    created_by        INT UNSIGNED NULL,
    updated_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_smsc_status (status, scheduled_at),
    CONSTRAINT fk_smsc_template   FOREIGN KEY (template_id) REFERENCES sms_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_smsc_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_smsc_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sms_messages (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_mobile    VARCHAR(20)  NOT NULL,
    recipient_name      VARCHAR(150) NULL,
    customer_id         INT UNSIGNED NULL,
    lead_id             INT UNSIGNED NULL,
    employee_id         INT UNSIGNED NULL,
    template_id         INT UNSIGNED NULL,
    campaign_id         INT UNSIGNED NULL,
    message             TEXT         NOT NULL,
    encoding            ENUM('gsm7','unicode') NOT NULL DEFAULT 'gsm7',
    segments            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    gateway             VARCHAR(40)  NOT NULL COMMENT 'mock, or real provider key later',
    is_test             TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = mock gateway, NOT a real SMS',
    status              ENUM('pending','queued','sent','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(100) NULL,
    scheduled_at        DATETIME     NULL,
    sent_at             DATETIME     NULL,
    delivered_at        DATETIME     NULL,
    error_message       VARCHAR(500) NULL,
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sms_queue (status, scheduled_at),
    KEY idx_sms_provider (gateway, provider_message_id),
    KEY idx_sms_mobile (recipient_mobile),
    KEY idx_sms_campaign (campaign_id, status),
    KEY idx_sms_created (created_at),
    CONSTRAINT fk_sms_customer   FOREIGN KEY (customer_id) REFERENCES customers(id)     ON DELETE SET NULL,
    CONSTRAINT fk_sms_lead       FOREIGN KEY (lead_id)     REFERENCES leads(id)         ON DELETE SET NULL,
    CONSTRAINT fk_sms_employee   FOREIGN KEY (employee_id) REFERENCES employees(id)     ON DELETE SET NULL,
    CONSTRAINT fk_sms_template   FOREIGN KEY (template_id) REFERENCES sms_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_sms_campaign   FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_sms_created_by FOREIGN KEY (created_by)  REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gateway request/response/webhook trail. Payloads are sanitised (no API keys).
CREATE TABLE sms_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sms_message_id  BIGINT UNSIGNED NULL,
    gateway         VARCHAR(40)  NOT NULL,
    event           ENUM('request','response','status_update','webhook','error') NOT NULL,
    http_status     SMALLINT UNSIGNED NULL,
    payload         JSON         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_logs_message (sms_message_id),
    KEY idx_sms_logs_created (gateway, created_at),
    CONSTRAINT fk_sms_logs_message FOREIGN KEY (sms_message_id) REFERENCES sms_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 13. SYSTEM: SETTINGS, AUDIT, NOTIFICATIONS, NUMBER SEQUENCES
-- =============================================================================

CREATE TABLE settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_group   VARCHAR(50)  NOT NULL,
    setting_key     VARCHAR(80)  NOT NULL,
    setting_value   TEXT         NULL,
    value_type      ENUM('string','int','decimal','bool','json','date') NOT NULL DEFAULT 'string',
    description     VARCHAR(255) NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settings_group_key (setting_group, setting_key),
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only. role_slug / user_name are snapshots so history survives user changes.
CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    user_name       VARCHAR(100) NULL,
    role_slug       VARCHAR(60)  NULL,
    ip_address      VARCHAR(45)  NULL,
    user_agent      VARCHAR(255) NULL,
    action          VARCHAR(60)  NOT NULL COMMENT 'login, logout, customer.created, import.completed, permission.changed ...',
    module          VARCHAR(50)  NOT NULL,
    record_id       BIGINT UNSIGNED NULL,
    old_data        JSON         NULL,
    new_data        JSON         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_module_record (module, record_id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    type            VARCHAR(40)  NOT NULL COMMENT 'followup_due, lead_assigned, import_done ...',
    title           VARCHAR(200) NOT NULL,
    message         VARCHAR(500) NULL,
    link            VARCHAR(255) NULL COMMENT 'Relative app path only',
    read_at         DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user_unread (user_id, read_at, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gapless, concurrency-safe document numbers (CUS-00001, LD-00001 ...).
-- Application reads with SELECT ... FOR UPDATE inside a transaction.
CREATE TABLE number_sequences (
    name            VARCHAR(30)  NOT NULL PRIMARY KEY,
    prefix          VARCHAR(15)  NOT NULL,
    next_number     INT UNSIGNED NOT NULL DEFAULT 1,
    padding         TINYINT UNSIGNED NOT NULL DEFAULT 5,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Applied schema changes. cli/install.php records the baseline (this file);
-- cli/migrate.php applies database/migrations/*.sql in name order.
CREATE TABLE schema_migrations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration       VARCHAR(190) NOT NULL,
    checksum        CHAR(64)     NOT NULL COMMENT 'SHA-256 of the file when applied',
    batch           INT UNSIGNED NOT NULL,
    applied_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schema_migrations (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 14. KPI SOURCE VIEWS
-- -----------------------------------------------------------------------------
-- The ONE definition of "what counts" for dashboards, reports and the mobile API.
-- KPI queries read these views (adding date / branch / employee filters) instead
-- of re-implementing the rules, so every screen gives the same number.
--
--   * cancelled and soft-deleted documents never count
--   * credit notes are negative (sales are net of returns)
--   * bounced / cancelled receipts never count
--   * only open / partial orders with a pending balance count as pending
-- =============================================================================

-- Sales at document level, signed (credit notes negative).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_sales_documents AS
SELECT si.id                    AS invoice_id,
       si.financial_year_id,
       si.document_type,
       si.invoice_no,
       si.invoice_date,
       si.customer_id,
       si.branch_id,
       si.employee_id,
       CASE si.document_type WHEN 'credit_note' THEN -si.taxable_amount ELSE si.taxable_amount END AS taxable_value,
       CASE si.document_type WHEN 'credit_note' THEN -si.tax_amount     ELSE si.tax_amount     END AS tax_value,
       CASE si.document_type WHEN 'credit_note' THEN -si.total_amount   ELSE si.total_amount   END AS total_value,
       si.import_batch_id
FROM sales_invoices si
WHERE si.status = 'active'
  AND si.deleted_at IS NULL;

-- Sales at product-line level, signed - for Product drill-down.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_sales_lines AS
SELECT sii.id                   AS item_id,
       si.id                    AS invoice_id,
       si.financial_year_id,
       si.document_type,
       si.invoice_no,
       si.invoice_date,
       si.customer_id,
       si.branch_id,
       si.employee_id,
       sii.product_id,
       CASE si.document_type WHEN 'credit_note' THEN -sii.quantity       ELSE sii.quantity       END AS quantity,
       CASE si.document_type WHEN 'credit_note' THEN -sii.taxable_amount ELSE sii.taxable_amount END AS taxable_value,
       CASE si.document_type WHEN 'credit_note' THEN -sii.line_total     ELSE sii.line_total     END AS total_value
FROM sales_invoice_items sii
JOIN sales_invoices si ON si.id = sii.invoice_id
WHERE si.status = 'active'
  AND si.deleted_at IS NULL;

-- Receipts that count as collection, with how much is still unallocated (on account).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_valid_collections AS
SELECT c.id                     AS collection_id,
       c.financial_year_id,
       c.receipt_no,
       c.receipt_date,
       c.customer_id,
       c.branch_id,
       c.employee_id,
       c.amount,
       COALESCE(a.allocated, 0)              AS allocated_amount,
       c.amount - COALESCE(a.allocated, 0)   AS unallocated_amount,
       c.payment_mode,
       c.reference_no,
       c.status,
       c.import_batch_id
FROM collections c
LEFT JOIN (
    SELECT collection_id, SUM(amount) AS allocated
    FROM collection_allocations
    GROUP BY collection_id
) a ON a.collection_id = c.id
WHERE c.status IN ('received', 'cleared')
  AND c.deleted_at IS NULL;

-- Computed bill-wise outstanding (settings outstanding.source = 'computed').
-- balance = invoice total - valid receipts allocated - credit notes raised against it.
-- Ageing is done by the caller: DATEDIFF(:as_on, invoice_date | due_date).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_invoice_balances AS
SELECT si.id                    AS invoice_id,
       si.financial_year_id,
       si.invoice_no,
       si.invoice_date,
       COALESCE(si.due_date, DATE_ADD(si.invoice_date, INTERVAL cu.credit_days DAY)) AS due_date,
       si.customer_id,
       si.branch_id,
       si.employee_id,
       si.total_amount,
       COALESCE(pay.paid, 0)        AS paid_amount,
       COALESCE(cn.credited, 0)     AS credited_amount,
       si.total_amount - COALESCE(pay.paid, 0) - COALESCE(cn.credited, 0) AS balance
FROM sales_invoices si
JOIN customers cu ON cu.id = si.customer_id
LEFT JOIN (
    SELECT ca.invoice_id, SUM(ca.amount) AS paid
    FROM collection_allocations ca
    JOIN collections c ON c.id = ca.collection_id
    WHERE c.status IN ('received', 'cleared')
      AND c.deleted_at IS NULL
    GROUP BY ca.invoice_id
) pay ON pay.invoice_id = si.id
LEFT JOIN (
    SELECT reference_invoice_id, SUM(total_amount) AS credited
    FROM sales_invoices
    WHERE document_type = 'credit_note'
      AND status = 'active'
      AND deleted_at IS NULL
      AND reference_invoice_id IS NOT NULL
    GROUP BY reference_invoice_id
) cn ON cn.reference_invoice_id = si.id
WHERE si.document_type = 'invoice'
  AND si.status = 'active'
  AND si.deleted_at IS NULL;

-- Imported outstanding: the latest snapshot only (settings outstanding.source = 'imported').
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_outstanding_latest AS
SELECT ob.*
FROM outstanding_bills ob
WHERE ob.as_on_date = (SELECT MAX(as_on_date) FROM outstanding_bills)
  AND ob.pending_amount > 0;

-- Pending order lines (open / partial orders with something still to supply).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_pending_order_lines AS
SELECT poi.id                   AS item_id,
       po.id                    AS order_id,
       po.financial_year_id,
       po.order_no,
       po.order_date,
       po.customer_po_no,
       po.expected_delivery_date,
       po.customer_id,
       po.branch_id,
       po.employee_id,
       po.status,
       poi.product_id,
       poi.order_qty,
       poi.supplied_qty,
       poi.pending_qty,
       poi.order_value,
       poi.supplied_value,
       poi.pending_value,
       po.import_batch_id
FROM pending_orders po
JOIN pending_order_items poi ON poi.order_id = po.id
WHERE po.status IN ('open', 'partial')
  AND po.deleted_at IS NULL
  AND poi.pending_value > 0;

-- Samples still awaiting an outcome (pending_status = 'pending').
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

-- Goods out on DC, not yet invoiced / returned / closed.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_pending_dc_lines AS
SELECT di.id                    AS item_id,
       d.id                     AS dc_id,
       d.financial_year_id,
       d.dc_no,
       d.dc_date,
       d.sample_id,
       d.customer_id,
       d.branch_id,
       d.employee_id,
       d.supply_status,
       di.product_id,
       di.quantity,
       di.dc_value,
       d.import_batch_id
FROM dc_records d
JOIN dc_items di ON di.dc_id = d.id
WHERE d.pending_status = 'pending'
  AND d.deleted_at IS NULL;
