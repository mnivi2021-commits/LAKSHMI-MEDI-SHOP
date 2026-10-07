-- HRM -> Sales person details: date of birth, sales role, area and the area's sales coordinator.
ALTER TABLE employees
    ADD COLUMN date_of_birth DATE NULL AFTER email,
    ADD COLUMN sales_role ENUM('manager','sales_executive','sales_support','sales_coordinator') NULL
        COMMENT 'Group on HRM -> Sales person details' AFTER is_sales_rep,
    ADD COLUMN area VARCHAR(80) NULL COMMENT 'Sales area, e.g. Trichy' AFTER sales_role,
    ADD COLUMN coordinator_id INT UNSIGNED NULL COMMENT 'Sales coordinator for this sales person' AFTER area,
    ADD KEY idx_employees_role (sales_role, branch_id),
    ADD CONSTRAINT fk_emp_coordinator FOREIGN KEY (coordinator_id) REFERENCES employees(id) ON DELETE SET NULL;

-- Existing sales representatives start as Sales Executives.
UPDATE employees SET sales_role = 'sales_executive' WHERE is_sales_rep = 1 AND sales_role IS NULL;
