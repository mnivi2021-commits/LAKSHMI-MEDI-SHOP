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

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /** Trimmed string from POST form data ('' when missing or not a string). */
    public static function input(string $key, int $maxLength = 1000): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $maxLength) : '';
    }

    /** Raw (untrimmed) POST value - for passwords, where spaces are significant. */
    public static function raw(string $key, int $maxLength = 1000): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? mb_substr($v, 0, $maxLength) : '';
    }

    /** @var array<string, mixed>|null */
    private static ?array $jsonBody = null;

    /**
     * Decoded JSON request body (API). Bodies over 64 KB or invalid JSON give [].
     *
     * @return array<string, mixed>
     */
    public static function json(): array
    {
        if (self::$jsonBody === null) {
            $raw = file_get_contents('php://input', false, null, 0, 65536) ?: '';
            $data = json_decode($raw, true);
            self::$jsonBody = is_array($data) ? $data : [];
        }
        return self::$jsonBody;
    }

    public static function jsonString(string $key, int $maxLength = 1000): string
    {
        $v = self::json()[$key] ?? '';
        return is_string($v) ? mb_substr($v, 0, $maxLength) : '';
    }

    /** Bearer token from the Authorization header (Apache may expose it under several names). */
    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');

        return preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,200})$/', trim((string) $header), $m) ? $m[1] : null;
    }

    /**
     * Only same-app relative paths are allowed as post-login redirects
     * (blocks open redirects such as //evil.com or https://evil.com).
     */
    public static function safeRedirectPath(?string $path, string $default = '/'): string
    {
        if (!is_string($path) || $path === '' || strlen($path) > 500) {
            return $default;
        }
        if ($path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1F]/', $path)) {
            return $default;
        }
        return $path;
    }
}
