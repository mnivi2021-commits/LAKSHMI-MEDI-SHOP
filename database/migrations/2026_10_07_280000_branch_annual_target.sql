-- Dashboard: "<BRANCH> BRANCH ANNUAL TARGET" (owner, 07-10-2026). Annual targets can now be set per branch.
ALTER TABLE annual_targets ADD KEY idx_at_fy (financial_year_id);
ALTER TABLE annual_targets DROP INDEX uq_annual_target, DROP COLUMN scope_key;
ALTER TABLE annual_targets
    MODIFY level ENUM('division','area','employee','branch') NOT NULL,
    MODIFY division_id INT UNSIGNED NULL,
    ADD COLUMN branch_id INT UNSIGNED NULL AFTER division_id,
    ADD CONSTRAINT fk_at_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE annual_targets
    ADD COLUMN scope_key VARCHAR(60) AS (CONCAT(level, ':', IFNULL(division_id, 0), ':', IFNULL(area_id, 0), ':', IFNULL(employee_id, 0), ':', IFNULL(branch_id, 0))) STORED AFTER annual_target,
    ADD UNIQUE KEY uq_annual_target (financial_year_id, scope_key);
