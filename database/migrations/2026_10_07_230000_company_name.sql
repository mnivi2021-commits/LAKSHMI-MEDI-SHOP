-- Company name (owner, 07-10-2026). Only replaces the placeholder; a name already set in Settings is kept.
UPDATE settings SET setting_value = 'LAKSHMI SAFETY EQUIPMENT PRIVATE LIMITED'
WHERE setting_group = 'company' AND setting_key = 'name' AND setting_value IN ('Your Company Name', '');
