<?php

declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'Marketing CRM'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => rtrim((string) env('APP_URL', 'http://localhost/marketing_crm'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Kolkata'),
    'key'      => env('APP_KEY', ''),
    'version'  => '0.1.0',

    // Indian financial year starts in April. Overridden by settings.finance.fy_start_month.
    'fy_start_month' => 4,

    'log_level'     => env('LOG_LEVEL', 'info'),
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 10),
];
