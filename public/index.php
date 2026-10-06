<?php

declare(strict_types=1);

/*
 * Front controller. public/ is the ONLY web-accessible directory; app code,
 * config, .env, logs and uploads all live one level up.
 */

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

require dirname(__DIR__) . '/bootstrap/app.php';

Response::securityHeaders();
header('Cache-Control: no-store, private');   // pages contain business data

$isApi = str_starts_with(Request::path(), '/api/');

if ($isApi) {
    // CORS only for configured origins (browser clients such as Expo web).
    // Native mobile apps do not send Origin and are unaffected.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, Config::get('security.api.allowed_origins', []), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }
    header('Vary: Origin');
    if (Request::method() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
} else {
    Session::start();   // the API is stateless: no session cookie
}

$router = new Router();
require BASE_PATH . '/routes/middleware.php';
require BASE_PATH . '/routes/web.php';

$router->dispatch(Request::method(), Request::path());
