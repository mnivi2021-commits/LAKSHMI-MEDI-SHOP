-- Month start sheet (owner, 07-10-2026): target + 80% commitment, opening outstanding + 60% collection target.
INSERT INTO settings (setting_group, setting_key, setting_value, value_type, description)
SELECT 'targets', 'sales_commit_pct', '80', 'int', 'Month start sheet: sales commitment % shown beside each target'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_group = 'targets' AND setting_key = 'sales_commit_pct');
INSERT INTO settings (setting_group, setting_key, setting_value, value_type, description)
SELECT 'targets', 'collection_pct', '60', 'int', 'Month start sheet: collection target = % of opening outstanding'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_group = 'targets' AND setting_key = 'collection_pct');
