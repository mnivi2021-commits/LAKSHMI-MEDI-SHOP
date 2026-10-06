<?php

declare(strict_types=1);

return [
    'host'     => env('DB_HOST', '127.0.0.1'),
    'port'     => (int) env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'marketing_crm'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
    // MySQL session time zone; keep in step with APP_TIMEZONE so CURDATE() matches PHP's today.
    'time_zone' => '+05:30',
];
