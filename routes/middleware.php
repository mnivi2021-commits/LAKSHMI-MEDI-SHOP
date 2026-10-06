<?php

declare(strict_types=1);

use App\Core\ApiTokens;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** @var Router $router */

// Signed-in users only. Users who must change their password can reach nothing else.
$router->middleware('auth', static function (): bool {
    if (!Auth::check()) {
        $here = Request::path() . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        Response::redirect('/login' . ($here !== '/' ? '?next=' . rawurlencode($here) : ''));
        return false;
    }
    if (Auth::user()['must_change_password'] && Request::path() !== '/password/change') {
        Response::redirect('/password/change');
        return false;
    }
    return true;
});

// Pages only for signed-out visitors (login).
$router->middleware('guest', static function (): bool {
    if (Auth::check()) {
        Response::redirect('/');
        return false;
    }
    return true;
});

// Every state-changing web form.
$router->middleware('csrf', static fn (): bool => Csrf::enforce());

// Bearer-token API requests (mobile app). No cookies, so no CSRF needed.
$router->middleware('api_auth', static function (): bool {
    $token = Request::bearerToken();
    $auth = $token !== null ? ApiTokens::authenticate($token) : null;
    if ($auth === null) {
        header('WWW-Authenticate: Bearer');
        Response::json(['success' => false, 'error' => ['code' => 401, 'type' => 'unauthenticated', 'message' => 'Sign in required.']], 401);
        return false;
    }
    Auth::setUser($auth['user']);
    ApiTokens::$currentTokenId = $auth['token_id'];
    return true;
});
