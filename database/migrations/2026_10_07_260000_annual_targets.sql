-- Targets tab (owner, 07-10-2026): annual targets by division (PPE, MAAP, TRAINING), by sales area
-- (Madurai, Trichy, TTN, TVL) and by sales employee. Current month target = annual / 12.

CREATE TABLE IF NOT EXISTS divisions (
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

CREATE TABLE IF NOT EXISTS sales_areas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL COMMENT 'Matches employees.area',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_areas_name (name),
    CONSTRAINT fk_area_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_area_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS annual_targets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_year_id   INT UNSIGNED NOT NULL,
    level               ENUM('division','area','employee') NOT NULL,
    division_id         INT UNSIGNED NOT NULL,
    area_id             INT UNSIGNED NULL,
    employee_id         INT UNSIGNED NULL,
    annual_target       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    scope_key           VARCHAR(60) AS (CONCAT(level, ':', division_id, ':', IFNULL(area_id, 0), ':', IFNULL(employee_id, 0))) STORED,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_annual_target (financial_year_id, scope_key),
    KEY idx_at_employee (employee_id),
    CONSTRAINT chk_at_value CHECK (annual_target >= 0),
    CONSTRAINT fk_at_fy       FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE RESTRICT,
    CONSTRAINT fk_at_division FOREIGN KEY (division_id)       REFERENCES divisions(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_at_area     FOREIGN KEY (area_id)           REFERENCES sales_areas(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_at_employee FOREIGN KEY (employee_id)       REFERENCES employees(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_at_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_at_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO divisions (name)
SELECT d.name FROM (SELECT 'PPE' AS name UNION ALL SELECT 'MAAP' UNION ALL SELECT 'TRAINING') d
WHERE NOT EXISTS (SELECT 1 FROM divisions x WHERE x.name = d.name);

INSERT INTO sales_areas (name)
SELECT a.name FROM (SELECT 'Madurai' AS name UNION ALL SELECT 'Trichy' UNION ALL SELECT 'TTN' UNION ALL SELECT 'TVL') a
WHERE NOT EXISTS (SELECT 1 FROM sales_areas x WHERE x.name = a.name);
