<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        header_remove('X-Powered-By');
    }

    /** @param array<string, mixed> $data */
    public static function view(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo View::render($view, $data);
    }

    public static function json(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Renders 403/404/500 as JSON for API calls and as a page otherwise. */
    public static function error(int $status, string $message, ?string $ref = null, ?string $detail = null): void
    {
        if (headers_sent() === false) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            self::securityHeaders();
        }

        if (Request::expectsJson()) {
            $error = array_filter(
                ['code' => $status, 'message' => $message, 'ref' => $ref, 'detail' => $detail],
                static fn (mixed $v): bool => $v !== null
            );
            self::json(['success' => false, 'error' => $error], $status);
            return;
        }

        $view = in_array($status, [403, 404, 500], true) ? 'errors/' . $status : 'errors/500';
        self::view($view, ['message' => $message, 'ref' => $ref, 'detail' => $detail], $status);
    }
}
