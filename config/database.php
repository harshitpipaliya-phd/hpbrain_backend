<?php

declare(strict_types=1);

/**
 * MySQL 8 is the sole datastore (ADR-006, ADR-008). Neo4j is deferred.
 *
 * The env var names deliberately match the Node build's .env so an existing
 * deployment's configuration carries over untouched.
 */
return [
    // Honours DB_CONNECTION instead of hardcoding mysql. .env sets
    // DB_CONNECTION=mysql, so deployments are unaffected — but hardcoding it
    // meant phpunit.xml could not redirect the suite off the shared ERP
    // server, and tests were opening real connections to it.
    'default' => env('DB_CONNECTION', 'mysql'),

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
        // Test-only. The mysql connection above points at hp_erp, which the
        // Brain SHARES with the institute ERP (171 non-hpbrain_ tables live in
        // it). A suite that boots against that connection is one
        // RefreshDatabase trait away from dropping the ERP. phpunit.xml pins
        // DB_CONNECTION to this instead, so tests cannot reach the shared
        // server even by accident.
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix'   => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
