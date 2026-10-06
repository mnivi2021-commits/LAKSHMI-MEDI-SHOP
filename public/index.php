<?php

declare(strict_types=1);

/*
 * Front controller. public/ is the ONLY web-accessible directory; app code,
 * config, .env, logs and uploads all live one level up.
 */

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

require dirname(__DIR__) . '/bootstrap/app.php';

Response::securityHeaders();

if (!Request::expectsJson()) {
    Session::start();
}

$router = new Router();
require BASE_PATH . '/routes/web.php';

$router->dispatch(Request::method(), Request::path());
