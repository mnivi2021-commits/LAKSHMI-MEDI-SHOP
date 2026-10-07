-- Enquiry / offer expiry date (owner, 07-10-2026).
ALTER TABLE leads ADD COLUMN valid_until DATE NULL COMMENT 'Enquiry / offer expiry date' AFTER enquiry_source;
