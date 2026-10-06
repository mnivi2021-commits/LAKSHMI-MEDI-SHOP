<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader. Values are kept in-process (not exported with putenv)
 * so secrets do not leak into child processes or phpinfo().
 */
final class Env
{
    /** @var array<string, mixed> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            self::$values[$key] = self::parse(trim($value));
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        // Real environment variables (e.g. set by the hosting panel) as fallback.
        $server = getenv($key);
        return $server === false ? $default : self::parse($server);
    }

    private static function parse(string $value): mixed
    {
        $quoted = strlen($value) >= 2
            && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")));
        if ($quoted) {
            return substr($value, 1, -1);
        }

        // Strip trailing inline comment: KEY=value # comment
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = rtrim(substr($value, 0, $hash));
        }

        return match (strtolower($value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $value,
        };
    }
}
