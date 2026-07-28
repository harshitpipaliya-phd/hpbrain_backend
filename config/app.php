<?php

declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'HP Enterprise Brain'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost:4000'),
    'timezone' => 'UTC',
    'locale'   => 'en',
    'key'      => env('APP_KEY'),
    'cipher'   => 'AES-256-CBC',
];
