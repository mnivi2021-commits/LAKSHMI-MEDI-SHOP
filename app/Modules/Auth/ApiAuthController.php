<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\ApiTokens;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Mobile / API authentication.
 *
 *   POST /api/auth/login   {"username": "...", "password": "...", "device_name": "..."}
 *   GET  /api/auth/me      Authorization: Bearer <token>
 *   POST /api/auth/logout  Authorization: Bearer <token>
 */
final class ApiAuthController
{
    public static function login(): void
    {
        $result = Auth::attempt(Request::jsonString('username', 150), Request::jsonString('password', 200));

        if ($result['status'] === 'throttled') {
            header('Retry-After: ' . $result['retry_after']);
            self::fail(429, 'too_many_attempts', 'Too many failed attempts. Try again later.');
            return;
        }
        if ($result['status'] === 'disabled') {
            self::fail(403, 'account_disabled', 'This account is disabled.');
            return;
        }
        if ($result['status'] !== 'ok') {
            self::fail(401, 'invalid_credentials', 'Incorrect username or password.');
            return;
        }

        $user = $result['user'];
        if ($user['must_change_password']) {
            self::fail(403, 'password_change_required', 'Please sign in on the web once and set a new password first.');
            return;
        }

        $token = ApiTokens::issue($user['id'], Request::jsonString('device_name', 100));
        Audit::log('login', 'auth', $user['id'], null, ['channel' => 'api'], $user);

        Response::json(['success' => true, 'data' => [
            'token'      => $token['token'],
            'token_type' => 'Bearer',
            'expires_at' => $token['expires_at'],
            'user'       => self::publicUser($user),
        ]]);
    }

    public static function me(): void
    {
        Response::json(['success' => true, 'data' => ['user' => self::publicUser(Auth::user())]]);
    }

    public static function logout(): void
    {
        ApiTokens::revoke((int) ApiTokens::$currentTokenId);
        Audit::log('logout', 'auth', Auth::id(), null, ['channel' => 'api']);
        Response::json(['success' => true, 'data' => ['message' => 'Signed out.']]);
    }

    /** @param array<string, mixed> $user */
    private static function publicUser(array $user): array
    {
        return [
            'id'          => $user['id'],
            'name'        => $user['name'],
            'username'    => $user['username'],
            'email'       => $user['email'],
            'role'        => ['slug' => $user['role_slug'], 'name' => $user['role_name'], 'data_scope' => $user['data_scope']],
            'employee_id' => $user['employee_id'] !== null ? (int) $user['employee_id'] : null,
        ];
    }

    private static function fail(int $status, string $code, string $message): void
    {
        Response::json(['success' => false, 'error' => ['code' => $status, 'type' => $code, 'message' => $message]], $status);
    }
}
