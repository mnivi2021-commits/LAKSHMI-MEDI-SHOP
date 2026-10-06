<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * JSON-lines logger writing to logs/app-YYYY-MM-DD.log (outside the web root).
 * Context keys that look like secrets are redacted before writing.
 */
final class Logger
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400, 'critical' => 500];
    private const SECRET_KEYS = '/pass|secret|token|api_key|apikey|authorization|cookie|csrf/i';

    public static function debug(string $message, array $context = []): void    { self::write('debug', $message, $context); }
    public static function info(string $message, array $context = []): void     { self::write('info', $message, $context); }
    public static function warning(string $message, array $context = []): void  { self::write('warning', $message, $context); }
    public static function error(string $message, array $context = []): void    { self::write('error', $message, $context); }
    public static function critical(string $message, array $context = []): void { self::write('critical', $message, $context); }

    public static function exception(Throwable $e, string $level = 'error'): string
    {
        $ref = bin2hex(random_bytes(6));
        self::write($level, $e->getMessage(), [
            'ref'   => $ref,
            'type'  => $e::class,
            'file'  => self::relative($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_slice(array_map(
                static fn (array $f): string => self::relative($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'),
                $e->getTrace()
            ), 0, 15),
        ]);
        return $ref;
    }

    private static function write(string $level, string $message, array $context): void
    {
        $min = self::LEVELS[strtolower((string) Config::get('app.log_level', 'info'))] ?? 200;
        if (self::LEVELS[$level] < $min) {
            return;
        }

        $line = json_encode([
            'time'    => date('c'),
            'level'   => $level,
            'message' => $message,
            'context' => self::redact($context),
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'uri'     => $_SERVER['REQUEST_URI'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $dir = BASE_PATH . '/logs';
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }

    private static function relative(string $path): string
    {
        return str_replace([BASE_PATH . DIRECTORY_SEPARATOR, BASE_PATH . '/'], '', $path);
    }
}
