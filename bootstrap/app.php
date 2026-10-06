<?php

declare(strict_types=1);

/*
 * Shared bootstrap for the web front controller, the API and CLI scripts.
 */

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;

define('BASE_PATH', dirname(__DIR__));

// PSR-4 autoloader for the App\ namespace (Composer is optional in Phase 1).
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

require BASE_PATH . '/app/Helpers/functions.php';

Env::load(BASE_PATH . '/.env');
Config::loadDirectory(BASE_PATH . '/config');

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

ErrorHandler::register();
