<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every declared route reaches a real public controller method.
 *
 * THE DEFECT THIS EXISTS FOR. `POST decisions/{tenantId}/{id}/approve` was
 * declared in routes/api.php, `DecisionController` had no approve() method, and
 * nothing noticed: `route:list` showed it as an ordinary row, and the hand-
 * maintained API matrix compared URLs and called it a MATCH. The first request
 * to reach it was a fatal error in production. A route table is a claim about
 * what the API answers; nothing was checking the claim.
 *
 * DELIBERATELY DUPLICATES `php artisan brain:check-routes`, which CI runs. The
 * command fails the build; this fails the developer's own `php artisan test`
 * before the push. Catching the same defect twice costs one test; catching it
 * once, in production, costs an outage.
 *
 * ONE TEST, NOT ONE PER ROUTE. A data provider would name the offending route
 * in the failure message, which is nicer — but a provider runs before the
 * application boots, so it would have to boot a second kernel to see the route
 * table, and PHPUnit then marks all 156 cases risky for replacing the error
 * handlers. Collecting the failures and reporting them together gets the same
 * diagnosis without a second application.
 *
 * No database and no HTTP: reflection over the route table cannot be broken by
 * a missing table or a slow query.
 */
final class RouteResolutionTest extends TestCase
{
    public function test_every_declared_route_resolves_to_a_public_controller_method(): void
    {
        $broken  = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            // Closure routes have nothing to resolve; not a defect.
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            $checked++;

            [$class, $method] = explode('@', $action, 2);
            $where = implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$route->uri();

            if (! class_exists($class)) {
                $broken[] = "{$where} -> {$action}: controller class does not exist";
                continue;
            }

            if (! method_exists($class, $method)) {
                // The exact shape of the approve() defect: resolves in
                // route:list, throws on dispatch.
                $broken[] = "{$where} -> {$action}: method does not exist";
                continue;
            }

            $reflection = new ReflectionMethod($class, $method);

            // A protected method is as unreachable as an absent one: the router
            // cannot call it and the request 500s identically.
            if (! $reflection->isPublic()) {
                $broken[] = "{$where} -> {$action}: method is not public";
                continue;
            }

            if ($reflection->isStatic()) {
                $broken[] = "{$where} -> {$action}: method is static";
            }
        }

        // A guard on the guard: if the route table were empty — a routing
        // change, a boot failure — the loop above would pass vacuously and this
        // suite would report green while checking nothing.
        self::assertGreaterThan(
            100,
            $checked,
            'The route table is suspiciously small; this test would be passing vacuously.'
        );

        self::assertSame(
            [],
            $broken,
            sprintf(
                "%d of %d declared routes do not resolve:\n  - %s",
                count($broken),
                $checked,
                implode("\n  - ", $broken)
            )
        );
    }
}
