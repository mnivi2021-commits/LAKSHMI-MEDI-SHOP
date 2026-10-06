<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\HealthCheck;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Modules\Auth\ApiAuthController;
use App\Modules\Auth\AuthController;

/** @var Router $router */

// -----------------------------------------------------------------------------
// Public intro page / signed-in home
// -----------------------------------------------------------------------------
$router->get('/', static function (): void {
    if (!Auth::check()) {
        Response::view('intro', ['title' => 'Marketing CRM']);
        return;
    }
    if (Auth::user()['must_change_password']) {
        Response::redirect('/password/change');
        return;
    }
    Response::view('home', ['title' => 'Home · Marketing CRM', 'user' => Auth::user(), 'flash' => Session::takeFlash()]);
});

// -----------------------------------------------------------------------------
// Authentication (web)
// -----------------------------------------------------------------------------
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);

$router->post('/logout', static function (): void {
    if (!Auth::check()) {                 // session already gone - nothing to protect
        Response::redirect('/login');
        return;
    }
    if (Csrf::enforce()) {
        AuthController::logout();
    }
});

$router->get('/password/change', [AuthController::class, 'showChangePassword'], ['auth']);
$router->post('/password/change', [AuthController::class, 'changePassword'], ['auth', 'csrf']);

// -----------------------------------------------------------------------------
// Authentication (API / mobile)
// -----------------------------------------------------------------------------
$router->post('/api/auth/login', [ApiAuthController::class, 'login']);
$router->get('/api/auth/me', [ApiAuthController::class, 'me'], ['api_auth']);
$router->post('/api/auth/logout', [ApiAuthController::class, 'logout'], ['api_auth']);

// -----------------------------------------------------------------------------
// Health (detail only on the server itself or when APP_ENV=local)
// -----------------------------------------------------------------------------
$healthDetailAllowed = static fn (): bool => Config::get('app.env') === 'local' || Request::isLocal();

$router->get('/health', static function () use ($healthDetailAllowed): void {
    $report = (new HealthCheck())->run();
    $status = $report['status'] === 'fail' ? 503 : 200;
    if (!$healthDetailAllowed()) {
        Response::view('health', ['report' => ['status' => $report['status']], 'limited' => true], $status);
        return;
    }
    Response::view('health', ['report' => $report, 'limited' => false], $status);
});

$router->get('/api/health', static function () use ($healthDetailAllowed): void {
    $report = (new HealthCheck())->run();
    $payload = $healthDetailAllowed() ? $report : ['status' => $report['status']];
    Response::json(['success' => $report['status'] !== 'fail', 'data' => $payload], $report['status'] === 'fail' ? 503 : 200);
});
