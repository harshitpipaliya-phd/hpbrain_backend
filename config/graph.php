<?php

declare(strict_types=1);

/**
 * Which GraphQueryPort implementation answers graph reads, and the connection
 * settings the Neo4j adapter would need if it were ever selected.
 *
 * ADR-008 defers Neo4j out of v1: MySQL (GraphProjection) is the sole store,
 * and that is this file's default. The 'neo4j' driver names
 * App\Domain\Graph\Neo4jGraphAdapter, which exists and is unit-testable
 * (mocked client) but is never selected by this default, and nothing in this
 * installation's .env provisions real Neo4j credentials — flipping
 * GRAPH_DRIVER to 'neo4j' without them would fail closed at first use, not
 * silently misbehave, because the adapter builds its client lazily.
 */
return [

    'driver' => env('GRAPH_DRIVER', 'mysql'),

    'neo4j' => [
        'uri'      => env('NEO4J_URI'),
        'username' => env('NEO4J_USERNAME'),
        'password' => env('NEO4J_PASSWORD'),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
    ],

];
