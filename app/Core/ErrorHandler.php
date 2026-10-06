<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Turns every PHP error into an exception, logs it, and shows a generic page.
 * SQL errors, credentials, stack traces and file paths are never sent to the
 * browser unless APP_ENV=local AND APP_DEBUG=true.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
            }
        });
    }

    public static function handle(Throwable $e): void
    {
        $ref = Logger::exception($e, 'critical');

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, '[error] ' . $e->getMessage() . " (ref {$ref})" . PHP_EOL);
            exit(1);
        }

        $detail = self::showDetail() ? $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : null;
        Response::error(500, 'Something went wrong. Please try again or contact the administrator.', $ref, $detail);
    }

    private static function showDetail(): bool
    {
        return Config::get('app.env') === 'local' && Config::get('app.debug') === true;
    }
}
