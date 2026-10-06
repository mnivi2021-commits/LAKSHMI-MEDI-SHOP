-- =============================================================================
-- CRM & SMS Management System - Database Schema
-- -----------------------------------------------------------------------------
-- Target  : MySQL 8.0+  (also verified on MariaDB 10.4+ for shared hosting)
-- Charset : utf8mb4 / utf8mb4_unicode_ci
-- Engine  : InnoDB (transactions + foreign keys)
--
-- Conventions
--   * Every business table has created_at / updated_at / created_by / updated_by.
--   * Soft delete via deleted_at (+ deleted_by) on records users can delete.
--   * Money      -> DECIMAL(14,2)      Mobile -> VARCHAR(20), stored normalised (+91...)
--   * Polymorphic "customer OR lead" links use two nullable FKs + a CHECK, so the
--     database still enforces referential integrity (FKs use RESTRICT because
--     MySQL 8 forbids CHECK constraints on columns with SET NULL/CASCADE actions).
--
-- Run this on an EMPTY database (see cli/install.php or README.md).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. ACCESS CONTROL
-- =============================================================================

CREATE TABLE roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50)  NOT NULL,
    slug            VARCHAR(50)  NOT NULL,
    description     VARCHAR(255) NULL,
    is_system       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'System roles cannot be deleted',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_slug (slug),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(80)  NOT NULL COMMENT 'module.action e.g. customers.view',
    module          VARCHAR(50)  NOT NULL,
    action          VARCHAR(30)  NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_slug (slug),
    KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id         INT UNSIGNED NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_rp_permission (permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id               INT UNSIGNED NOT NULL,
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
    KEY idx_users_role (role_id),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_role       FOREIGN KEY (role_id)    REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection: every attempt is recorded by identifier AND by IP.
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

-- Secure "remember me": selector/validator split, only a SHA-256 of the validator is stored.
CREATE TABLE user_remember_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    selector        CHAR(24)     NOT NULL,
    validator_hash  CHAR(64)     NOT NULL,
    user_agent      VARCHAR(255) NULL,
    ip_address      VARCHAR(45)  NULL,
    expires_at      DATETIME     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_urt_selector (selector),
    KEY idx_urt_user (user_id),
    KEY idx_urt_expires (expires_at),
    CONSTRAINT fk_urt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. LOOKUPS / MASTER DATA
-- =============================================================================

CREATE TABLE lead_sources (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lead_sources_name (name),
    CONSTRAINT fk_ls_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ls_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_statuses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50)  NOT NULL,
    slug            VARCHAR(50)  NOT NULL,
    color           VARCHAR(20)  NOT NULL DEFAULT 'secondary' COMMENT 'Bootstrap colour name',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    is_default      TINYINT(1)   NOT NULL DEFAULT 0,
    is_won          TINYINT(1)   NOT NULL DEFAULT 0,
    is_lost         TINYINT(1)   NOT NULL DEFAULT 0,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lead_statuses_slug (slug),
    UNIQUE KEY uq_lead_statuses_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE opportunity_stages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50)  NOT NULL,
    slug            VARCHAR(50)  NOT NULL,
    probability     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Default win probability %',
    color           VARCHAR(20)  NOT NULL DEFAULT 'secondary',
    sort_order      SMALLINT     NOT NULL DEFAULT 0,
    is_won          TINYINT(1)   NOT NULL DEFAULT 0,
    is_lost         TINYINT(1)   NOT NULL DEFAULT 0,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_opp_stages_slug (slug),
    UNIQUE KEY uq_opp_stages_name (name),
    CONSTRAINT chk_opp_stage_probability CHECK (probability <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gapless, concurrency-safe document numbers (CUS-00001, LD-00001 ...).
-- Application reads with SELECT ... FOR UPDATE inside a transaction.
CREATE TABLE number_sequences (
    name            VARCHAR(30)  NOT NULL PRIMARY KEY,
    prefix          VARCHAR(10)  NOT NULL,
    next_number     INT UNSIGNED NOT NULL DEFAULT 1,
    padding         TINYINT UNSIGNED NOT NULL DEFAULT 5,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. PRODUCTS & SERVICES
-- =============================================================================

CREATE TABLE products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku             VARCHAR(50)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    category        VARCHAR(80)  NULL,
    description     TEXT         NULL,
    unit            VARCHAR(20)  NOT NULL DEFAULT 'Nos',
    price           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    tax_rate        DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percent',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME     NULL,
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_name (name),
    KEY idx_products_status (status, deleted_at),
    CONSTRAINT chk_products_price CHECK (price >= 0),
    CONSTRAINT chk_products_tax   CHECK (tax_rate >= 0 AND tax_rate <= 100),
    CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    category        VARCHAR(80)  NULL,
    description     TEXT         NULL,
    price           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    tax_rate        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    billing_cycle   ENUM('one_time','monthly','quarterly','half_yearly','yearly') NOT NULL DEFAULT 'one_time',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME     NULL,
    UNIQUE KEY uq_services_code (code),
    KEY idx_services_name (name),
    KEY idx_services_status (status, deleted_at),
    CONSTRAINT chk_services_price CHECK (price >= 0),
    CONSTRAINT chk_services_tax   CHECK (tax_rate >= 0 AND tax_rate <= 100),
    CONSTRAINT fk_services_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_services_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. CUSTOMERS
-- =============================================================================

CREATE TABLE customers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_code       VARCHAR(20)  NOT NULL,
    name                VARCHAR(150) NOT NULL,
    company_name        VARCHAR(150) NULL,
    mobile              VARCHAR(20)  NOT NULL,
    alternate_mobile    VARCHAR(20)  NULL,
    email               VARCHAR(150) NULL,
    date_of_birth       DATE         NULL,
    customer_type       ENUM('individual','business') NOT NULL DEFAULT 'individual',
    source_id           INT UNSIGNED NULL,
    status              ENUM('active','inactive','prospect','blocked') NOT NULL DEFAULT 'active',
    sms_opt_out         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Customer asked not to receive SMS',
    notes               TEXT         NULL,
    assigned_to         INT UNSIGNED NULL,
    converted_from_lead_id INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    UNIQUE KEY uq_customers_code (customer_code),
    KEY idx_customers_mobile (mobile),
    KEY idx_customers_email (email),
    KEY idx_customers_name (name),
    KEY idx_customers_company (company_name),
    KEY idx_customers_status (status, deleted_at),
    KEY idx_customers_assigned (assigned_to, deleted_at),
    KEY idx_customers_source (source_id),
    KEY idx_customers_created (created_at),
    CONSTRAINT fk_customers_source     FOREIGN KEY (source_id)   REFERENCES lead_sources(id) ON DELETE SET NULL,
    CONSTRAINT fk_customers_assigned   FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_customers_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_customers_deleted_by FOREIGN KEY (deleted_by)  REFERENCES users(id) ON DELETE SET NULL
    -- fk_customers_lead is added after the leads table (circular reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_contacts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    designation     VARCHAR(100) NULL,
    mobile          VARCHAR(20)  NULL,
    email           VARCHAR(150) NULL,
    is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
    notes           VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cc_customer (customer_id),
    KEY idx_cc_mobile (mobile),
    CONSTRAINT fk_cc_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cc_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_addresses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    address_type    ENUM('billing','shipping','office','home','other') NOT NULL DEFAULT 'billing',
    address_line1   VARCHAR(255) NOT NULL,
    address_line2   VARCHAR(255) NULL,
    city            VARCHAR(100) NULL,
    state           VARCHAR(100) NULL,
    pincode         VARCHAR(10)  NULL,
    country         VARCHAR(100) NOT NULL DEFAULT 'India',
    is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ca_customer (customer_id, is_primary),
    KEY idx_ca_city (city),
    KEY idx_ca_state (state),
    KEY idx_ca_pincode (pincode),
    CONSTRAINT fk_ca_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ca_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ca_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer groups power "customer-group SMS" and campaign targeting.
CREATE TABLE customer_groups (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    description     VARCHAR(255) NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_groups_name (name),
    CONSTRAINT fk_cg_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cg_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_group_members (
    group_id        INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NOT NULL,
    added_by        INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, customer_id),
    KEY idx_cgm_customer (customer_id),
    CONSTRAINT fk_cgm_group    FOREIGN KEY (group_id)    REFERENCES customer_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_cgm_customer FOREIGN KEY (customer_id) REFERENCES customers(id)       ON DELETE CASCADE,
    CONSTRAINT fk_cgm_added_by FOREIGN KEY (added_by)    REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. LEADS
-- =============================================================================

CREATE TABLE leads (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_number           VARCHAR(20)  NOT NULL,
    name                  VARCHAR(150) NOT NULL,
    company_name          VARCHAR(150) NULL,
    mobile                VARCHAR(20)  NOT NULL,
    email                 VARCHAR(150) NULL,
    source_id             INT UNSIGNED NULL,
    product_id            INT UNSIGNED NULL,
    service_id            INT UNSIGNED NULL,
    status_id             INT UNSIGNED NOT NULL,
    priority              ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    assigned_to           INT UNSIGNED NULL,
    expected_value        DECIMAL(14,2) NULL,
    next_followup_at      DATETIME     NULL,
    notes                 TEXT         NULL,
    lost_reason           VARCHAR(255) NULL,
    sms_opt_out           TINYINT(1)   NOT NULL DEFAULT 0,
    converted_customer_id INT UNSIGNED NULL,
    converted_at          DATETIME     NULL,
    created_by            INT UNSIGNED NULL,
    updated_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at            DATETIME     NULL,
    deleted_by            INT UNSIGNED NULL,
    UNIQUE KEY uq_leads_number (lead_number),
    KEY idx_leads_mobile (mobile),
    KEY idx_leads_email (email),
    KEY idx_leads_name (name),
    KEY idx_leads_status (status_id, deleted_at),
    KEY idx_leads_assigned (assigned_to, deleted_at),
    KEY idx_leads_source (source_id),
    KEY idx_leads_priority (priority),
    KEY idx_leads_next_followup (next_followup_at),
    KEY idx_leads_created (created_at),
    CONSTRAINT chk_leads_value CHECK (expected_value IS NULL OR expected_value >= 0),
    CONSTRAINT fk_leads_source     FOREIGN KEY (source_id)   REFERENCES lead_sources(id)  ON DELETE SET NULL,
    CONSTRAINT fk_leads_product    FOREIGN KEY (product_id)  REFERENCES products(id)      ON DELETE SET NULL,
    CONSTRAINT fk_leads_service    FOREIGN KEY (service_id)  REFERENCES services(id)      ON DELETE SET NULL,
    CONSTRAINT fk_leads_status     FOREIGN KEY (status_id)   REFERENCES lead_statuses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_leads_assigned   FOREIGN KEY (assigned_to) REFERENCES users(id)         ON DELETE SET NULL,
    CONSTRAINT fk_leads_customer   FOREIGN KEY (converted_customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_deleted_by FOREIGN KEY (deleted_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customers
    ADD CONSTRAINT fk_customers_lead FOREIGN KEY (converted_from_lead_id) REFERENCES leads(id) ON DELETE SET NULL;

-- Explicit status history (funnel reports, "lead history" tab).
CREATE TABLE lead_status_history (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id         INT UNSIGNED NOT NULL,
    from_status_id  INT UNSIGNED NULL,
    to_status_id    INT UNSIGNED NOT NULL,
    remarks         VARCHAR(255) NULL,
    changed_by      INT UNSIGNED NULL,
    changed_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lsh_lead (lead_id, changed_at),
    KEY idx_lsh_to_status (to_status_id, changed_at),
    CONSTRAINT fk_lsh_lead        FOREIGN KEY (lead_id)        REFERENCES leads(id)         ON DELETE CASCADE,
    CONSTRAINT fk_lsh_from_status FOREIGN KEY (from_status_id) REFERENCES lead_statuses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lsh_to_status   FOREIGN KEY (to_status_id)   REFERENCES lead_statuses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lsh_changed_by  FOREIGN KEY (changed_by)     REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. ENQUIRIES
-- =============================================================================

CREATE TABLE enquiries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enquiry_number  VARCHAR(20)  NOT NULL,
    customer_id     INT UNSIGNED NULL,
    lead_id         INT UNSIGNED NULL,
    name            VARCHAR(150) NOT NULL,
    mobile          VARCHAR(20)  NOT NULL,
    email           VARCHAR(150) NULL,
    channel         ENUM('phone','walk_in','website','email','whatsapp','sms','referral','other') NOT NULL DEFAULT 'phone',
    source_id       INT UNSIGNED NULL,
    product_id      INT UNSIGNED NULL,
    service_id      INT UNSIGNED NULL,
    subject         VARCHAR(200) NOT NULL,
    message         TEXT         NULL,
    status          ENUM('open','in_progress','converted','closed') NOT NULL DEFAULT 'open',
    priority        ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    assigned_to     INT UNSIGNED NULL,
    resolution      TEXT         NULL,
    closed_at       DATETIME     NULL,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME     NULL,
    deleted_by      INT UNSIGNED NULL,
    UNIQUE KEY uq_enquiries_number (enquiry_number),
    KEY idx_enq_customer (customer_id),
    KEY idx_enq_lead (lead_id),
    KEY idx_enq_mobile (mobile),
    KEY idx_enq_status (status, deleted_at),
    KEY idx_enq_assigned (assigned_to, deleted_at),
    KEY idx_enq_created (created_at),
    CONSTRAINT fk_enq_customer   FOREIGN KEY (customer_id) REFERENCES customers(id)    ON DELETE SET NULL,
    CONSTRAINT fk_enq_lead       FOREIGN KEY (lead_id)     REFERENCES leads(id)        ON DELETE SET NULL,
    CONSTRAINT fk_enq_source     FOREIGN KEY (source_id)   REFERENCES lead_sources(id) ON DELETE SET NULL,
    CONSTRAINT fk_enq_product    FOREIGN KEY (product_id)  REFERENCES products(id)     ON DELETE SET NULL,
    CONSTRAINT fk_enq_service    FOREIGN KEY (service_id)  REFERENCES services(id)     ON DELETE SET NULL,
    CONSTRAINT fk_enq_assigned   FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_enq_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_enq_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_enq_deleted_by FOREIGN KEY (deleted_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. OPPORTUNITIES
-- =============================================================================

CREATE TABLE opportunities (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opportunity_number  VARCHAR(20)  NOT NULL,
    title               VARCHAR(200) NOT NULL,
    customer_id         INT UNSIGNED NULL,
    lead_id             INT UNSIGNED NULL,
    stage_id            INT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NULL,
    service_id          INT UNSIGNED NULL,
    amount              DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    probability         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expected_close_date DATE         NULL,
    actual_close_date   DATE         NULL,
    status              ENUM('open','won','lost') NOT NULL DEFAULT 'open',
    lost_reason         VARCHAR(255) NULL,
    assigned_to         INT UNSIGNED NULL,
    notes               TEXT         NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    UNIQUE KEY uq_opp_number (opportunity_number),
    KEY idx_opp_customer (customer_id),
    KEY idx_opp_lead (lead_id),
    KEY idx_opp_stage (stage_id),
    KEY idx_opp_status (status, deleted_at),
    KEY idx_opp_assigned (assigned_to, deleted_at),
    KEY idx_opp_close (expected_close_date),
    CONSTRAINT chk_opp_party       CHECK (customer_id IS NOT NULL OR lead_id IS NOT NULL),
    CONSTRAINT chk_opp_amount      CHECK (amount >= 0),
    CONSTRAINT chk_opp_probability CHECK (probability <= 100),
    CONSTRAINT fk_opp_customer   FOREIGN KEY (customer_id) REFERENCES customers(id)          ON DELETE RESTRICT,
    CONSTRAINT fk_opp_lead       FOREIGN KEY (lead_id)     REFERENCES leads(id)              ON DELETE RESTRICT,
    CONSTRAINT fk_opp_stage      FOREIGN KEY (stage_id)    REFERENCES opportunity_stages(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opp_product    FOREIGN KEY (product_id)  REFERENCES products(id)           ON DELETE SET NULL,
    CONSTRAINT fk_opp_service    FOREIGN KEY (service_id)  REFERENCES services(id)           ON DELETE SET NULL,
    CONSTRAINT fk_opp_assigned   FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_opp_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_opp_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_opp_deleted_by FOREIGN KEY (deleted_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. FOLLOW-UPS
-- =============================================================================

CREATE TABLE followups (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT UNSIGNED NULL,
    lead_id             INT UNSIGNED NULL,
    enquiry_id          INT UNSIGNED NULL,
    opportunity_id      INT UNSIGNED NULL,
    parent_followup_id  INT UNSIGNED NULL COMMENT 'Follow-up this one was scheduled from',
    followup_date       DATE         NOT NULL,
    followup_time       TIME         NULL,
    followup_type       ENUM('call','sms','whatsapp','email','meeting','visit','other') NOT NULL DEFAULT 'call',
    assigned_to         INT UNSIGNED NULL,
    subject             VARCHAR(200) NULL,
    notes               TEXT         NULL,
    status              ENUM('pending','completed','rescheduled','cancelled','missed') NOT NULL DEFAULT 'pending',
    result              TEXT         NULL,
    next_followup_date  DATE         NULL,
    completed_at        DATETIME     NULL,
    completed_by        INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    deleted_by          INT UNSIGNED NULL,
    KEY idx_fu_due (status, followup_date, deleted_at),
    KEY idx_fu_assigned_due (assigned_to, status, followup_date),
    KEY idx_fu_customer (customer_id),
    KEY idx_fu_lead (lead_id),
    KEY idx_fu_enquiry (enquiry_id),
    KEY idx_fu_opportunity (opportunity_id),
    CONSTRAINT chk_fu_party CHECK (customer_id IS NOT NULL OR lead_id IS NOT NULL),
    CONSTRAINT fk_fu_customer     FOREIGN KEY (customer_id)        REFERENCES customers(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_fu_lead         FOREIGN KEY (lead_id)            REFERENCES leads(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_fu_enquiry      FOREIGN KEY (enquiry_id)         REFERENCES enquiries(id)     ON DELETE SET NULL,
    CONSTRAINT fk_fu_opportunity  FOREIGN KEY (opportunity_id)     REFERENCES opportunities(id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_parent       FOREIGN KEY (parent_followup_id) REFERENCES followups(id)     ON DELETE SET NULL,
    CONSTRAINT fk_fu_assigned     FOREIGN KEY (assigned_to)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_created_by   FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_updated_by   FOREIGN KEY (updated_by)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_deleted_by   FOREIGN KEY (deleted_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. SALES
-- =============================================================================

CREATE TABLE sales (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no      VARCHAR(30)  NOT NULL,
    customer_id     INT UNSIGNED NOT NULL,
    opportunity_id  INT UNSIGNED NULL,
    sale_date       DATE         NOT NULL,
    subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    paid_amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    payment_status  ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    status          ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes           TEXT         NULL,
    sold_by         INT UNSIGNED NULL,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME     NULL,
    UNIQUE KEY uq_sales_invoice (invoice_no),
    KEY idx_sales_customer (customer_id),
    KEY idx_sales_date (sale_date, status),
    KEY idx_sales_sold_by (sold_by),
    CONSTRAINT chk_sales_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND paid_amount >= 0),
    CONSTRAINT fk_sales_customer    FOREIGN KEY (customer_id)    REFERENCES customers(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_sales_opportunity FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_sold_by     FOREIGN KEY (sold_by)    REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_created_by  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_updated_by  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id         INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NULL,
    service_id      INT UNSIGNED NULL,
    description     VARCHAR(255) NOT NULL COMMENT 'Snapshot of item name at time of sale',
    quantity        DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    unit_price      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    tax_rate        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_si_sale (sale_id),
    KEY idx_si_product (product_id),
    KEY idx_si_service (service_id),
    CONSTRAINT chk_si_qty CHECK (quantity > 0),
    CONSTRAINT fk_si_sale    FOREIGN KEY (sale_id)    REFERENCES sales(id)    ON DELETE CASCADE,
    CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_si_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 10. SMS
-- =============================================================================

CREATE TABLE sms_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    category        ENUM('transactional','promotional','service','otp') NOT NULL DEFAULT 'transactional',
    body            TEXT         NOT NULL COMMENT 'Plain text with {placeholders}; never evaluated as code',
    sender_id       VARCHAR(20)  NULL,
    dlt_template_id VARCHAR(50)  NULL COMMENT 'Regulatory template ID where required (e.g. India DLT)',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME     NULL,
    UNIQUE KEY uq_sms_templates_name (name),
    KEY idx_sms_templates_status (status, deleted_at),
    CONSTRAINT fk_st_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_st_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sms_campaigns (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150) NOT NULL,
    template_id       INT UNSIGNED NULL,
    message_body      TEXT         NOT NULL COMMENT 'Snapshot of template body when campaign was created',
    sender_id         VARCHAR(20)  NULL,
    target_type       ENUM('all_customers','customer_group','customer_status','all_leads','lead_status','lead_source','custom') NOT NULL,
    target_filter     JSON         NULL COMMENT 'e.g. {"group_id":3} or {"status_id":2}',
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
    deleted_at        DATETIME     NULL,
    KEY idx_sc_status (status, scheduled_at),
    KEY idx_sc_template (template_id),
    KEY idx_sc_created (created_at),
    CONSTRAINT fk_sc_template   FOREIGN KEY (template_id) REFERENCES sms_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_sc_created_by FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sc_updated_by FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per outgoing SMS (single, bulk, campaign, follow-up, scheduled).
CREATE TABLE sms_messages (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_mobile    VARCHAR(20)  NOT NULL,
    recipient_name      VARCHAR(150) NULL,
    customer_id         INT UNSIGNED NULL,
    lead_id             INT UNSIGNED NULL,
    followup_id         INT UNSIGNED NULL,
    template_id         INT UNSIGNED NULL,
    campaign_id         INT UNSIGNED NULL,
    message             TEXT         NOT NULL,
    sender_id           VARCHAR(20)  NULL,
    encoding            ENUM('gsm7','unicode') NOT NULL DEFAULT 'gsm7',
    segments            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    gateway             VARCHAR(50)  NOT NULL COMMENT 'Gateway key used, e.g. mock',
    is_test             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = mock/test gateway, NOT a real SMS',
    status              ENUM('pending','queued','sent','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(100) NULL,
    scheduled_at        DATETIME     NULL,
    queued_at           DATETIME     NULL,
    sent_at             DATETIME     NULL,
    delivered_at        DATETIME     NULL,
    failed_at           DATETIME     NULL,
    error_code          VARCHAR(50)  NULL,
    error_message       VARCHAR(500) NULL,
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cost                DECIMAL(10,4) NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sms_queue (status, scheduled_at),
    KEY idx_sms_provider_id (gateway, provider_message_id),
    KEY idx_sms_mobile (recipient_mobile),
    KEY idx_sms_customer (customer_id),
    KEY idx_sms_lead (lead_id),
    KEY idx_sms_campaign (campaign_id, status),
    KEY idx_sms_created (created_at),
    CONSTRAINT fk_sms_customer   FOREIGN KEY (customer_id) REFERENCES customers(id)     ON DELETE SET NULL,
    CONSTRAINT fk_sms_lead       FOREIGN KEY (lead_id)     REFERENCES leads(id)         ON DELETE SET NULL,
    CONSTRAINT fk_sms_followup   FOREIGN KEY (followup_id) REFERENCES followups(id)     ON DELETE SET NULL,
    CONSTRAINT fk_sms_template   FOREIGN KEY (template_id) REFERENCES sms_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_sms_campaign   FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_sms_created_by FOREIGN KEY (created_by)  REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sms_campaign_recipients (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id     INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NULL,
    lead_id         INT UNSIGNED NULL,
    mobile          VARCHAR(20)  NOT NULL,
    recipient_name  VARCHAR(150) NULL,
    variables       JSON         NULL COMMENT 'Resolved placeholder values for this recipient',
    sms_message_id  BIGINT UNSIGNED NULL,
    status          ENUM('pending','queued','sent','delivered','failed','cancelled','skipped') NOT NULL DEFAULT 'pending',
    error_message   VARCHAR(500) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scr_campaign_mobile (campaign_id, mobile) COMMENT 'No duplicate SMS to one number per campaign',
    KEY idx_scr_status (campaign_id, status),
    KEY idx_scr_customer (customer_id),
    KEY idx_scr_lead (lead_id),
    KEY idx_scr_message (sms_message_id),
    CONSTRAINT fk_scr_campaign FOREIGN KEY (campaign_id)    REFERENCES sms_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_scr_customer FOREIGN KEY (customer_id)    REFERENCES customers(id)     ON DELETE SET NULL,
    CONSTRAINT fk_scr_lead     FOREIGN KEY (lead_id)        REFERENCES leads(id)         ON DELETE SET NULL,
    CONSTRAINT fk_scr_message  FOREIGN KEY (sms_message_id) REFERENCES sms_messages(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gateway request/response/callback trail. Payloads must be sanitised (no API keys).
CREATE TABLE sms_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sms_message_id  BIGINT UNSIGNED NULL,
    gateway         VARCHAR(50)  NOT NULL,
    event           ENUM('request','response','status_update','webhook','balance','error') NOT NULL,
    http_status     SMALLINT UNSIGNED NULL,
    payload         JSON         NULL,
    message         VARCHAR(500) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_logs_message (sms_message_id),
    KEY idx_sms_logs_created (gateway, created_at),
    CONSTRAINT fk_sms_logs_message FOREIGN KEY (sms_message_id) REFERENCES sms_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 11. ACTIVITY, NOTIFICATIONS, SETTINGS, AUDIT, IMPORTS
-- =============================================================================

-- Timeline for customer / lead / enquiry / opportunity profile pages.
-- subject_type + subject_id is polymorphic, so it has no FK; the application validates it.
CREATE TABLE activities (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type    ENUM('customer','lead','enquiry','opportunity','followup','sale','campaign') NOT NULL,
    subject_id      INT UNSIGNED NOT NULL,
    activity_type   VARCHAR(40)  NOT NULL COMMENT 'created, updated, note, call, status_change, sms_sent, converted ...',
    title           VARCHAR(200) NOT NULL,
    description     TEXT         NULL,
    meta            JSON         NULL,
    user_id         INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_act_subject (subject_type, subject_id, created_at),
    KEY idx_act_user (user_id, created_at),
    CONSTRAINT fk_act_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    type            VARCHAR(40)  NOT NULL COMMENT 'followup_due, lead_assigned, campaign_done ...',
    title           VARCHAR(200) NOT NULL,
    message         VARCHAR(500) NULL,
    link            VARCHAR(255) NULL COMMENT 'Relative app path only',
    read_at         DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user_unread (user_id, read_at, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_group   VARCHAR(50)  NOT NULL,
    setting_key     VARCHAR(80)  NOT NULL,
    setting_value   TEXT         NULL,
    value_type      ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
    is_encrypted    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Value encrypted with APP_KEY (secrets)',
    description     VARCHAR(255) NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settings_group_key (setting_group, setting_key),
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(60)  NOT NULL COMMENT 'customer.created, lead.status_changed, sms.sent ...',
    module          VARCHAR(50)  NOT NULL,
    record_id       BIGINT UNSIGNED NULL,
    old_values      JSON         NULL,
    new_values      JSON         NULL,
    ip_address      VARCHAR(45)  NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_module_record (module, record_id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Result summary for each CSV import (Total / Successful / Duplicates / Failed / Errors).
CREATE TABLE import_batches (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module            ENUM('customers','leads') NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
    success_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_rows    INT UNSIGNED NOT NULL DEFAULT 0,
    failed_rows       INT UNSIGNED NOT NULL DEFAULT 0,
    errors            JSON         NULL COMMENT '[{"row":5,"errors":["Invalid mobile"]}]',
    status            ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at      DATETIME     NULL,
    KEY idx_import_module (module, created_at),
    CONSTRAINT fk_import_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
