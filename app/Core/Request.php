<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Path relative to the app root, e.g. "/health" for http://localhost/marketing_crm/health */
    public static function path(): string
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim(rawurldecode($uri), '/');
        return $uri === '/index.php' ? '/' : $uri;
    }

    /** URL prefix the front controller lives under ("/marketing_crm" on XAMPP, "" on a vhost). */
    public static function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        return rtrim($dir, '/');
    }

    public static function expectsJson(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return str_starts_with(self::path(), '/api/')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public static function ip(): string
    {
        // REMOTE_ADDR only. X-Forwarded-For is trivially spoofable and is ignored
        // until a trusted reverse proxy is configured in production.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function isLocal(): bool
    {
        return in_array(self::ip(), ['127.0.0.1', '::1'], true);
    }
}
