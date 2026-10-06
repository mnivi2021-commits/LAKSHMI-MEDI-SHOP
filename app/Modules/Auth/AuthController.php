<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Auth;
use App\Core\PasswordPolicy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Web sign-in, sign-out and password change. Forms use POST-redirect-GET. */
final class AuthController
{
    public static function showLogin(): void
    {
        $old = $_SESSION['_old_identifier'] ?? '';
        unset($_SESSION['_old_identifier']);

        Response::view('auth/login', [
            'title'      => 'Sign in · Marketing CRM',
            'flash'      => Session::takeFlash(),
            'identifier' => $old,
            'next'       => Request::safeRedirectPath($_GET['next'] ?? null, ''),
        ]);
    }

    public static function login(): void
    {
        $identifier = Request::input('identifier', 150);
        $password   = Request::raw('password', 200);
        $next       = Request::safeRedirectPath(Request::input('next', 500));
        $result = Auth::attempt($identifier, $password);

        if ($result['status'] === 'ok') {
            Auth::loginSession($result['user']);
            if ($result['user']['must_change_password']) {
                Session::flash('info', 'Welcome! Please set a new password before continuing.');
                Response::redirect('/password/change');
                return;
            }
            Response::redirect($next);
            return;
        }

        $_SESSION['_old_identifier'] = $identifier;
        Session::flash('error', match ($result['status']) {
            'throttled' => 'Too many failed attempts. Please try again in ' . max(1, (int) ceil($result['retry_after'] / 60)) . ' minute(s).',
            'disabled'  => 'This account is disabled. Please contact the Admin Head.',
            default     => 'Incorrect username or password.',
        });
        Response::redirect('/login' . ($next !== '/' ? '?next=' . rawurlencode($next) : ''));
    }

    public static function logout(): void
    {
        Auth::logout();
        Session::flash('success', 'You have been signed out.');
        Response::redirect('/login');
    }

    public static function showChangePassword(): void
    {
        $user = Auth::user();
        Response::view('auth/change_password', [
            'title'     => 'Change password · Marketing CRM',
            'flash'     => Session::takeFlash(),
            'user'      => $user,
            'forced'    => $user['must_change_password'],
            'minLength' => PasswordPolicy::MIN_LENGTH,
        ]);
    }

    public static function changePassword(): void
    {
        $errors = Auth::changePassword(
            (int) Auth::id(),
            Request::raw('current_password', 200),
            Request::raw('new_password', 200),
            Request::raw('confirm_password', 200),
        );

        if ($errors) {
            foreach ($errors as $error) {
                Session::flash('error', $error);
            }
            Response::redirect('/password/change');
            return;
        }

        Session::flash('success', 'Your password has been changed. Other devices have been signed out.');
        Response::redirect('/');
    }
}
