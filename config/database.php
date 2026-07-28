<?php

declare(strict_types=1);

/**
 * MySQL 8 is the sole datastore (ADR-006, ADR-008). Neo4j is deferred.
 *
 * The env var names deliberately match the Node build's .env so an existing
 * deployment's configuration carries over untouched.
 */
return [
    'default' => 'mysql',

    'connections' => [
        'mysql' => [
            'driver'         => 'mysql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'hp_brain'),
            'username'       => env('DB_USERNAME', ''),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'strict'         => true,
            'engine'         => 'InnoDB',
            'options'        => env('DB_SSL') === 'true'
                ? [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]
                : [],
        ],
    ],

    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
