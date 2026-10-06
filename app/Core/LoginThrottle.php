<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Brute-force protection based on login_attempts.
 *
 *  - Per identifier: LOGIN_MAX_ATTEMPTS failures since the last success, within
 *    LOGIN_LOCKOUT_MINUTES, blocks that identifier until the oldest of them ages out.
 *  - Per IP: 4x that many failures (any identifiers) blocks the IP the same way,
 *    which stops one machine guessing across many usernames.
 *
 * Counting is by identifier as typed, whether or not the account exists, so the
 * response never reveals which usernames are real.
 */
final class LoginThrottle
{
    public static function record(string $identifier, bool $success): void
    {
        Database::query(
            'INSERT INTO login_attempts (identifier, ip_address, user_agent, success) VALUES (?, ?, ?, ?)',
            [self::normalise($identifier), Request::ip(), Request::userAgent(), $success ? 1 : 0]
        );

        // Occasional housekeeping: keep 90 days of history.
        if (random_int(1, 100) === 1) {
            Database::query('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 90 DAY');
        }
    }

    /** Seconds the caller must wait before another attempt (0 = allowed). */
    public static function secondsUntilAllowed(string $identifier): int
    {
        $max = max(1, (int) Config::get('security.login.max_attempts', 5));
        $window = max(1, (int) Config::get('security.login.lockout_minutes', 15)) * 60;

        $byIdentifier = Database::value(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL ? SECOND)
             FROM login_attempts
             WHERE identifier = ? AND success = 0
               AND attempted_at > NOW() - INTERVAL ? SECOND
               AND attempted_at > COALESCE((SELECT MAX(s.attempted_at) FROM login_attempts s WHERE s.identifier = ? AND s.success = 1), \'1000-01-01\')
             ORDER BY attempted_at DESC
             LIMIT 1 OFFSET ' . ($max - 1),
            [$window, self::normalise($identifier), $window, self::normalise($identifier)]
        );

        $byIp = Database::value(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL ? SECOND)
             FROM login_attempts
             WHERE ip_address = ? AND success = 0 AND attempted_at > NOW() - INTERVAL ? SECOND
             ORDER BY attempted_at DESC
             LIMIT 1 OFFSET ' . ($max * 4 - 1),
            [$window, Request::ip(), $window]
        );

        return max(0, (int) $byIdentifier, (int) $byIp);
    }

    public static function normalise(string $identifier): string
    {
        return mb_substr(mb_strtolower(trim($identifier)), 0, 150);
    }
}
