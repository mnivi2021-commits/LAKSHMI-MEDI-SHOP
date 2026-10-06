<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Business settings from the `settings` table, cached per request. */
final class Settings
{
    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    public static function get(string $group, string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Database::fetchAll('SELECT setting_group, setting_key, setting_value FROM settings') as $row) {
                    self::$cache[$row['setting_group'] . '.' . $row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'warning');
            }
        }
        return self::$cache[$group . '.' . $key] ?? $default;
    }

    /** Sales KPIs measure taxable value (default) or invoice total incl. GST. */
    public static function salesBasis(): string
    {
        return self::get('finance', 'sales_amount_basis', 'taxable') === 'total' ? 'total' : 'taxable';
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}
