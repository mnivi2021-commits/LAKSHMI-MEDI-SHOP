<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Bearer tokens for the mobile app / API clients.
 * The plain token is shown once at login; only its SHA-256 is stored.
 */
final class ApiTokens
{
    /** Token that authenticated the current API request (set by the api_auth middleware). */
    public static ?int $currentTokenId = null;

    /** @return array{token: string, expires_at: string} */
    public static function issue(int $userId, string $deviceName): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $days = max(1, (int) Config::get('security.api.token_ttl_days', 30));

        Database::query(
            'INSERT INTO api_tokens (user_id, name, token_hash, last_used_ip, expires_at)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL ? DAY)',
            [$userId, mb_substr($deviceName !== '' ? $deviceName : 'Mobile app', 0, 100), hash('sha256', $token), Request::ip(), $days]
        );

        $expires = (string) Database::value('SELECT expires_at FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * @return array{token_id: int, user: array<string, mixed>}|null
     */
    public static function authenticate(string $token): ?array
    {
        $row = Database::fetch(
            'SELECT id, user_id, last_used_at < NOW() - INTERVAL 1 MINUTE OR last_used_at IS NULL AS stale
             FROM api_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token)]
        );
        if ($row === null) {
            return null;
        }

        $user = Auth::loadUser((int) $row['user_id']);
        if ($user === null || $user['must_change_password']) {
            return null;
        }

        if ((int) $row['stale'] === 1) {   // avoid a write on every request
            Database::query('UPDATE api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?', [Request::ip(), $row['id']]);
        }
        return ['token_id' => (int) $row['id'], 'user' => $user];
    }

    public static function revoke(int $tokenId): void
    {
        Database::query('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$tokenId]);
    }
}
