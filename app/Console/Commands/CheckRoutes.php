<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;

/**
 * The gate that would have caught DecisionController::approve().
 *
 * That route was declared in routes/api.php, had no method on the controller,
 * appeared in `route:list` as a perfectly ordinary row, and threw a fatal on
 * the first dispatch. The API matrix in docs/ reported it as a MATCH, because
 * the matrix compared URLs. `route:list` is a liveness check — it proves a
 * route was registered, not that anything is on the other end of it.
 *
 * This asserts the other end exists: the controller class is loadable and
 * carries a PUBLIC method of the declared name. Exits non-zero listing every
 * route that fails, so CI can refuse the merge rather than production
 * discovering it.
 *
 * Deliberately duplicated by tests/Feature/RouteResolutionTest — the test
 * catches it locally before push, this catches it in CI. The same defect being
 * caught twice is cheaper than it being caught once, in production.
 */
final class CheckRoutes extends Command
{
    protected $signature = 'brain:check-routes {--json : Machine-readable output for CI}';

    protected $description = 'Assert every declared route resolves to a real public controller method.';

    public function handle(): int
    {
        $broken = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            // Closure routes have no controller to verify. Laravel reports them
            // as 'Closure'; they are not a defect, just out of scope here.
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            $checked++;

            $verb = implode('|', array_diff($route->methods(), ['HEAD']));
            $uri  = $route->uri();

            if (! class_exists($class)) {
                $broken[] = ['uri' => $uri, 'method' => $verb, 'action' => $action, 'reason' => 'controller_class_not_found'];
                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = ['uri' => $uri, 'method' => $verb, 'action' => $action, 'reason' => 'method_not_found'];
                continue;
            }

            // A protected or private method is as unreachable as a missing one:
            // the router cannot call it and the request 500s just the same.
            $reflection = new ReflectionMethod($class, $method);

            if (! $reflection->isPublic()) {
                $broken[] = ['uri' => $uri, 'method' => $verb, 'action' => $action, 'reason' => 'method_not_public'];
                continue;
            }

            if ($reflection->isStatic()) {
                $broken[] = ['uri' => $uri, 'method' => $verb, 'action' => $action, 'reason' => 'method_is_static'];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['checked' => $checked, 'broken' => $broken], JSON_PRETTY_PRINT));

            return $broken === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($broken === []) {
            $this->info("brain:check-routes: {$checked} routes checked, all resolve.");

            return self::SUCCESS;
        }

        $this->error(sprintf('brain:check-routes: %d of %d routes do not resolve.', count($broken), $checked));

        $this->table(
            ['Method', 'URI', 'Action', 'Reason'],
            array_map(fn (array $b) => [$b['method'], $b['uri'], $b['action'], $b['reason']], $broken)
        );

        return self::FAILURE;
    }
}
