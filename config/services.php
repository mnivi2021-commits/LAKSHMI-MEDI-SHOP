<?php

declare(strict_types=1);

// External providers. Business code talks to interfaces in app/Services; only these
// settings decide which concrete provider is used. Secrets come from .env only.
return [
    'mail' => [
        'provider' => env('MAIL_PROVIDER', 'manual'),
        'imap' => [
            'host'     => env('MAIL_IMAP_HOST', ''),
            'port'     => (int) env('MAIL_IMAP_PORT', 993),
            'username' => env('MAIL_IMAP_USERNAME', ''),
            'password' => env('MAIL_IMAP_PASSWORD', ''),
        ],
        'gmail' => [
            'client_id'     => env('MAIL_GMAIL_CLIENT_ID', ''),
            'client_secret' => env('MAIL_GMAIL_CLIENT_SECRET', ''),
        ],
        'microsoft_graph' => [
            'tenant_id'     => env('MAIL_GRAPH_TENANT_ID', ''),
            'client_id'     => env('MAIL_GRAPH_CLIENT_ID', ''),
            'client_secret' => env('MAIL_GRAPH_CLIENT_SECRET', ''),
        ],
    ],
    'sms' => [
        'gateway'       => env('SMS_GATEWAY', 'mock'),
        'api_key'       => env('SMS_API_KEY', ''),
        'sender_id'     => env('SMS_SENDER_ID', ''),
        'dlt_entity_id' => env('SMS_DLT_ENTITY_ID', ''),
    ],
];
