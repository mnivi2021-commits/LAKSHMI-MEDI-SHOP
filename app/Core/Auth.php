<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authentication for web sessions and API tokens.
 *
 * Session holds only: user id, login time and a "credential stamp" derived from
 * the password hash. Every request reloads the user, so disabling an account or
 * changing a password takes effect immediately on all other sessions.
 */
final class Auth
{
    /** Used when the account does not exist, so the response time does not reveal it. */
    private const DUMMY_HASH = '$2y$12$b.qiDzDCVBhaPh1dWIiZUu8Yd.viKYuXaQe1zNGEY0ZYqDj3.T.8i';

    /** A session can never live longer than this, even if active. */
    private const ABSOLUTE_LIFETIME_SECONDS = 12 * 3600;

    /** @var array<string, mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    /**
     * Verify credentials. Does not start a session (see loginSession / ApiTokens).
     *
     * @return array{status: 'ok'|'invalid'|'throttled'|'disabled', retry_after?: int, user?: array<string, mixed>}
     */
    public static function attempt(string $identifier, string $password): array
    {
        $identifier = LoginThrottle::normalise($identifier);
        if ($identifier === '' || $password === '') {
            return ['status' => 'invalid'];
        }

        $wait = LoginThrottle::secondsUntilAllowed($identifier);
        if ($wait > 0) {
            Audit::log('login.throttled', 'auth', null, null, ['identifier' => $identifier], self::anonymous());
            return ['status' => 'throttled', 'retry_after' => $wait];
        }

        $row = Database::fetch(
            'SELECT id, name, password_hash, status, locked_until, failed_login_count,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), locked_until)) AS locked_seconds
             FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL LIMIT 1',
            [$identifier, $identifier]
        );

        $valid = password_verify($password, $row['password_hash'] ?? self::DUMMY_HASH) && $row !== null;

        if (!$valid) {
            LoginThrottle::record($identifier, false);
            if ($row !== null) {
                self::registerFailure((int) $row['id']);
            }
            Audit::log('login.failed', 'auth', $row ? (int) $row['id'] : null, null, ['identifier' => $identifier], self::anonymous());
            return ['status' => 'invalid'];
        }

        if ($row['status'] !== 'active') {
            LoginThrottle::record($identifier, false);
            Audit::log('login.blocked', 'auth', (int) $row['id'], null, ['reason' => 'disabled'], self::anonymous());
            return ['status' => 'disabled'];
        }
        if ((int) $row['locked_seconds'] > 0) {
            Audit::log('login.blocked', 'auth', (int) $row['id'], null, ['reason' => 'locked'], self::anonymous());
            return ['status' => 'throttled', 'retry_after' => (int) $row['locked_seconds']];
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }

        LoginThrottle::record($identifier, true);
        Database::query(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?',
            [Request::ip(), $row['id']]
        );

        return ['status' => 'ok', 'user' => self::loadUser((int) $row['id'])];
    }

    /** Establish the web session after a successful attempt(). */
    public static function loginSession(array $user): void
    {
        Session::regenerate();                    // prevent session fixation
        unset($_SESSION['_csrf_token']);          // new CSRF token for the authenticated session
        $_SESSION['auth'] = [
            'user_id'  => (int) $user['id'],
            'login_at' => time(),
            'stamp'    => self::stamp((int) $user['id']),
        ];
        self::$user = $user;
        self::$resolved = true;
        Audit::log('login', 'auth', (int) $user['id'], null, ['channel' => 'web']);
    }

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('logout', 'auth', (int) self::id(), null, ['channel' => 'web']);
        }
        self::$user = null;
        self::$resolved = true;
        Session::restart();
    }

    /** Current user (web session or API token), or null. Password hash is never included. */
    public static function user(): ?array
    {
        if (!self::$resolved) {
            self::$resolved = true;
            self::$user = self::resolveFromSession();
        }
        return self::$user;
    }

    /** Used by API token authentication. */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
        self::$resolved = true;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    /**
     * Active user with role details, or null if missing / disabled / deleted.
     *
     * @return array<string, mixed>|null
     */
    public static function loadUser(int $id): ?array
    {
        $user = Database::fetch(
            "SELECT u.id, u.name, u.username, u.email, u.mobile, u.employee_id, u.must_change_password,
                    u.last_login_at, r.id AS role_id, r.slug AS role_slug, r.name AS role_name, r.data_scope
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.status = 'active' AND u.deleted_at IS NULL AND r.status = 'active'",
            [$id]
        );
        if ($user === null) {
            return null;
        }
        $user['id'] = (int) $user['id'];
        $user['must_change_password'] = (bool) $user['must_change_password'];
        return $user;
    }

    /**
     * Change password after verifying the current one. Invalidates every other
     * session (stamp changes) and revokes all API tokens.
     *
     * @return list<string> validation errors; empty on success
     */
    public static function changePassword(int $userId, string $current, string $new, string $confirm): array
    {
        $row = Database::fetch('SELECT username, email, password_hash FROM users WHERE id = ?', [$userId]);
        if ($row === null || !password_verify($current, $row['password_hash'])) {
            return ['Your current password is incorrect.'];
        }
        if ($new !== $confirm) {
            return ['The new passwords do not match.'];
        }
        if (password_verify($new, $row['password_hash'])) {
            return ['The new password must be different from the current one.'];
        }
        $errors = PasswordPolicy::validate($new, $row['username'], $row['email']);
        if ($errors) {
            return $errors;
        }

        Database::transaction(static function () use ($userId, $new): void {
            Database::query(
                'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW(), updated_by = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $userId, $userId]
            );
            Database::query('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
        });

        Audit::log('password.changed', 'auth', $userId);

        // Keep THIS session valid; every other session now has a stale stamp.
        if (isset($_SESSION['auth']) && (int) $_SESSION['auth']['user_id'] === $userId) {
            Session::regenerate();
            $_SESSION['auth']['stamp'] = self::stamp($userId);
        }
        self::$user = self::loadUser($userId);
        return [];
    }

    // -------------------------------------------------------------------------

    private static function resolveFromSession(): ?array
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth) || !isset($auth['user_id'], $auth['login_at'], $auth['stamp'])) {
            return null;
        }

        $reason = null;
        $user = self::loadUser((int) $auth['user_id']);
        if ($user === null) {
            $reason = 'Your account is no longer active.';
        } elseif (!hash_equals(self::stamp((int) $auth['user_id']), (string) $auth['stamp'])) {
            $reason = 'Your password was changed. Please sign in again.';
        } elseif (time() - (int) $auth['login_at'] > self::ABSOLUTE_LIFETIME_SECONDS) {
            $reason = 'Your session has expired. Please sign in again.';
        }

        if ($reason !== null) {
            Session::restart();
            Session::flash('info', $reason);
            return null;
        }
        return $user;
    }

    /** Fingerprint of the stored password hash, keyed with APP_KEY. */
    private static function stamp(int $userId): string
    {
        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        return substr(hash_hmac('sha256', $hash, (string) Config::get('app.key', '')), 0, 32);
    }

    private static function registerFailure(int $userId): void
    {
        $max = max(1, (int) Config::get('security.login.max_attempts', 5));
        $minutes = max(1, (int) Config::get('security.login.lockout_minutes', 15));

        Database::query(
            'UPDATE users
             SET failed_login_count = failed_login_count + 1,
                 locked_until = IF(failed_login_count >= ?, NOW() + INTERVAL ? MINUTE, locked_until)
             WHERE id = ?',
            [$max, $minutes, $userId]
        );
        // MySQL evaluates SET left to right, so failed_login_count above is already incremented.
        $locked = Database::value('SELECT locked_until > NOW() FROM users WHERE id = ?', [$userId]);
        if ((int) $locked === 1) {
            Database::query('UPDATE users SET failed_login_count = 0 WHERE id = ? AND failed_login_count >= ?', [$userId, $max]);
            Audit::log('account.locked', 'auth', $userId, null, ['minutes' => $minutes], self::anonymous());
        }
    }

    /** @return array{id: null, name: string, role_slug: null} */
    private static function anonymous(): array
    {
        return ['id' => null, 'name' => 'guest', 'role_slug' => null];
    }
}
