<?php

declare(strict_types=1);

namespace App\Core;

/** Loads config/*.php arrays and resolves dot keys: Config::get('database.host'). */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $items = [];

    public static function loadDirectory(string $dir): void
    {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            self::$items[basename($file, '.php')] = require $file;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
