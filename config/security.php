<?php

declare(strict_types=1);

return [
    'session' => [
        'name'          => env('SESSION_NAME', 'mcrm_session'),
        'lifetime_min'  => (int) env('SESSION_LIFETIME', 120),
        'secure_cookie' => (bool) env('SESSION_SECURE_COOKIE', false),
        'same_site'     => 'Lax',
    ],
    'login' => [
        'max_attempts'    => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
    ],
    'api' => [
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_ALLOWED_ORIGINS', ''))))),
        'token_ttl_days'  => (int) env('API_TOKEN_TTL_DAYS', 30),
        'rate_limit'      => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
    ],
    'uploads' => [
        // Whitelist only. Anything else (php, phtml, exe, js, html, svg ...) is rejected.
        'import_extensions' => ['xlsx', 'xls', 'csv'],
        'import_mime_types' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'text/plain',
            'application/octet-stream',
        ],
    ],
];
