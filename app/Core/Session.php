<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened session start. Login (Phase 3) must call Session::regenerate()
 * after successful authentication to prevent session fixation.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }

        $cfg = Config::get('security.session');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ($cfg['lifetime_min'] * 60));
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        session_name($cfg['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (Request::basePath() ?: '') . '/',
            'secure'   => $cfg['secure_cookie'],
            'httponly' => true,
            'samesite' => $cfg['same_site'],
        ]);
        session_start();

        // Idle timeout
        $now = time();
        if (isset($_SESSION['_last_activity']) && $now - $_SESSION['_last_activity'] > $cfg['lifetime_min'] * 60) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
    }
}
