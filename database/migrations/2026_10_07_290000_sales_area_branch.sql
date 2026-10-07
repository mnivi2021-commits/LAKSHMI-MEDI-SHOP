-- Each sales area belongs to a branch (owner, 07-10-2026): Madurai, Trichy, TTN, TVL are Madurai Branch areas.
ALTER TABLE sales_areas
    ADD COLUMN branch_id INT UNSIGNED NULL AFTER name,
    ADD CONSTRAINT fk_area_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;
UPDATE sales_areas a JOIN branches b ON b.branch_code = 'MDU' AND b.deleted_at IS NULL
SET a.branch_id = b.id
WHERE a.name IN ('Madurai', 'Trichy', 'TTN', 'TVL') AND a.branch_id IS NULL;
