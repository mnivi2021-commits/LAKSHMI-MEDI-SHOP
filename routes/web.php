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
use App\Modules\Access\RoleController;
use App\Modules\Access\UserController;
use App\Modules\Auth\AuthController;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Dashboard\SalesController;

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
    DashboardController::index();     // dashboard, or a simple home page without dashboard.view
});

// Dashboard quick-add (JSON, session + CSRF header) and scope-limited lookups
$router->post('/dashboard/add/{type}', [DashboardController::class, 'quickAdd'], ['auth', 'csrf']);
$router->get('/dashboard/lookup/{type}', [DashboardController::class, 'lookup'], ['auth', 'can:dashboard.view']);

// Step A1 - Sales Performance drill-down, export, mobile API
$router->get('/dashboard/sales', [SalesController::class, 'detail'], ['auth', 'can:dashboard.view', 'can:sales.view']);
$router->get('/dashboard/sales/export', [SalesController::class, 'export'], ['auth', 'can:sales.view', 'can:sales.export']);
$router->get('/api/dashboard/sales', [SalesController::class, 'api'], ['api_auth', 'can:dashboard.view']);

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
// Access: users, roles, permission matrix
// -----------------------------------------------------------------------------
$router->get('/access/users', [UserController::class, 'index'], ['auth', 'can:users.view']);
$router->get('/access/users/new', [UserController::class, 'create'], ['auth', 'can:users.add']);
$router->post('/access/users', [UserController::class, 'store'], ['auth', 'can:users.add', 'csrf']);
$router->get('/access/users/{id}/edit', [UserController::class, 'edit'], ['auth', 'can:users.edit']);
$router->post('/access/users/{id}', [UserController::class, 'update'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/status', [UserController::class, 'toggleStatus'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/reset-password', [UserController::class, 'resetPassword'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/unlock', [UserController::class, 'unlock'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/delete', [UserController::class, 'destroy'], ['auth', 'can:users.delete', 'csrf']);
$router->get('/access/users/{id}/permissions', [UserController::class, 'permissions'], ['auth', 'can:access.manage']);
$router->post('/access/users/{id}/permissions', [UserController::class, 'savePermissions'], ['auth', 'can:access.manage', 'csrf']);

$router->get('/access/roles', [RoleController::class, 'index'], ['auth', 'can:access.manage']);
$router->get('/access/roles/new', [RoleController::class, 'create'], ['auth', 'can:access.manage']);
$router->post('/access/roles', [RoleController::class, 'store'], ['auth', 'can:access.manage', 'csrf']);
$router->get('/access/roles/{id}', [RoleController::class, 'edit'], ['auth', 'can:access.manage']);
$router->post('/access/roles/{id}', [RoleController::class, 'update'], ['auth', 'can:access.manage', 'csrf']);
$router->post('/access/roles/{id}/delete', [RoleController::class, 'destroy'], ['auth', 'can:access.manage', 'csrf']);

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
