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
            /*
             * DB_PERSISTENT exists because this connection is REMOTE.
             *
             * DB_HOST is a public address, not localhost, so every request pays
             * a full TCP + MySQL auth handshake before it can run its first
             * query. Measured against the current host that handshake is about
             * 1.2 SECONDS — more than the rest of a typical request put
             * together, and paid again on the next request, and the next.
             *
             * A persistent connection is kept open by the PHP worker and reused,
             * so only the first request each worker serves pays it.
             *
             * WHY IT IS OPT-IN RATHER THAN ON. A pooled connection carries
             * server-side state — an open transaction, a session variable, a
             * temporary table — into whatever request next picks it up. Laravel
             * resets what it knows about, but a connection killed mid
             * transaction by a network drop can be handed on in an odd state,
             * and this link already shows 39-1505 ms jitter. It is also actively
             * wrong under `php artisan serve` (one process, one connection, no
             * pool to speak of) and it multiplies open connections by worker
             * count, which the shared ERP server has a max_connections budget
             * for.
             *
             * Turn it on where those conditions are understood: PHP-FPM or
             * Apache with a known worker count, and headroom on the server's
             * max_connections. Leave it off for CLI and local dev.
             */
            'options'        => array_filter([
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => env('DB_SSL') === 'true' ? false : null,
                PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false) ? true : null,
                // Fail fast rather than hanging a web worker for the default
                // timeout when the remote host is unreachable. The link to this
                // database drops packets; a request that cannot connect should
                // return an error in seconds, not occupy a worker for minutes.
                PDO::ATTR_TIMEOUT => (int) env('DB_TIMEOUT', 10),
            ], static fn ($v) => $v !== null),
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
