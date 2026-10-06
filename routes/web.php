<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\HealthCheck;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** @var Router $router */

// Detailed diagnostics only on a developer machine; elsewhere just "ok"/"fail".
$healthDetailAllowed = static fn (): bool => Config::get('app.env') === 'local' || Request::isLocal();

$router->get('/', static function (): void {
    Response::view('home', ['title' => 'Marketing CRM']);
});

$router->get('/health', static function () use ($healthDetailAllowed): void {
    $report = (new HealthCheck())->run();
    if (!$healthDetailAllowed()) {
        Response::view('health', ['report' => ['status' => $report['status']], 'limited' => true], $report['status'] === 'fail' ? 503 : 200);
        return;
    }
    Response::view('health', ['report' => $report, 'limited' => false], $report['status'] === 'fail' ? 503 : 200);
});

$router->get('/api/health', static function () use ($healthDetailAllowed): void {
    $report = (new HealthCheck())->run();
    $payload = $healthDetailAllowed() ? $report : ['status' => $report['status']];
    Response::json(['success' => $report['status'] !== 'fail', 'data' => $payload], $report['status'] === 'fail' ? 503 : 200);
});
