-- =============================================================================
-- One-time setup: create the database and a dedicated least-privilege user.
-- Run as MySQL root (MySQL Workbench, or: mysql -u root -p < database/setup_user.sql)
--
-- 1. Replace CHANGE_ME_STRONG_PASSWORD below with a strong password.
-- 2. Put the same password in .env as DB_PASSWORD.
-- 3. Do NOT commit this file after editing it with a real password.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS marketing_crm
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'crm_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
CREATE USER IF NOT EXISTS 'crm_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

-- Application needs DML + DDL on its own schema only (DDL for installer/migrations).
-- No GRANT OPTION, no access to other databases, no FILE / SUPER privileges.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW
    ON marketing_crm.* TO 'crm_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW
    ON marketing_crm.* TO 'crm_app'@'127.0.0.1';

FLUSH PRIVILEGES;
