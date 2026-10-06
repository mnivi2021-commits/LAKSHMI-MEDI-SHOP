<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Append-only audit trail (audit_logs). Snapshots the acting user's name and
 * role so history stays readable even if the user is later renamed or deleted.
 * Never let an audit failure break the user's action - it is logged instead.
 */
final class Audit
{
    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     * @param array{id: int, name: string, role_slug: string}|null $actor defaults to the signed-in user
     */
    public static function log(string $action, string $module, ?int $recordId = null, ?array $old = null, ?array $new = null, ?array $actor = null): void
    {
        $actor ??= Auth::user();

        try {
            Database::query(
                'INSERT INTO audit_logs (user_id, user_name, role_slug, ip_address, user_agent, action, module, record_id, old_data, new_data)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $actor['id'] ?? null,
                    $actor['name'] ?? null,
                    $actor['role_slug'] ?? null,
                    PHP_SAPI === 'cli' ? 'cli' : Request::ip(),
                    PHP_SAPI === 'cli' ? 'cli' : Request::userAgent(),
                    $action,
                    $module,
                    $recordId,
                    $old === null ? null : self::encode($old),
                    $new === null ? null : self::encode($new),
                ]
            );
        } catch (Throwable $e) {
            Logger::exception($e, 'error');
        }
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        // Never write secrets into the audit trail.
        foreach (['password', 'password_hash', 'token', 'token_hash'] as $secret) {
            if (array_key_exists($secret, $data)) {
                $data[$secret] = '[redacted]';
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
