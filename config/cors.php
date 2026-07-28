<?php

declare(strict_types=1);

return [
    'paths' => [
        'api/*',
        'health',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_map(
        'trim',
        explode(
            ',',
            (string) env(
                'CORS_ORIGIN',
                'http://localhost:5173,http://localhost:5174'
            )
        )
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];