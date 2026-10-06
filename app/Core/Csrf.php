<?php

declare(strict_types=1);

namespace App\Core;

/** Synchronizer-token CSRF protection for every state-changing web form. */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION[self::KEY])
            && hash_equals($_SESSION[self::KEY], $token);
    }

    /** Checks POST field or X-CSRF-Token header; responds 419-style 403 on failure. */
    public static function enforce(): bool
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (self::verify($token)) {
            return true;
        }
        Logger::warning('CSRF token mismatch');
        Response::error(403, 'Your session has expired. Please reload the page and try again.');
        return false;
    }
}
