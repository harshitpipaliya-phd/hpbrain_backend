<?php

declare(strict_types=1);

use App\Domain\Universal\UnsupportedEntityException;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnsureTenantScope;
use App\Http\Middleware\RequirePermission;
use App\Providers\AiServiceProvider;
use App\Providers\IntelligenceServiceProvider;
use App\Providers\UniversalServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Console\ServeCommand;

// `php artisan serve` cannot bind a port on Windows without this.
//
// ServeCommand hands the built-in server a filtered environment: every variable
// not named in ServeCommand::$passthroughVariables is set to false, which
// Symfony's Process removes. That list contains 'SYSTEMROOT', but Windows
// actually sets 'SystemRoot', and the in_array() check is case-sensitive — so
// the variable is dropped. WinSock cannot initialise without it, and the child
// answers "Failed to listen on 127.0.0.1:8000 (reason: ?)" for every port it
// tries, 8000 through 8010, while a plain `php -S` on the same port succeeds.
//
// `php artisan serve --no-reload` also works, because that path passes the
// environment through untouched — but it costs you file-change reloading, and
// the failure gives no hint that the flag is the difference.
//
// No effect anywhere else: the entry is only read when `serve` runs.
if (PHP_OS_FAMILY === 'Windows' && ! in_array('SystemRoot', ServeCommand::$passthroughVariables, true)) {
    ServeCommand::$passthroughVariables[] = 'SystemRoot';
}

return Application::configure(basePath: dirname(__DIR__))
    // Binds the model driver named by config('brain.ai.provider'). This project
    // has no bootstrap/providers.php, so providers are registered here.
    ->withProviders([
        AiServiceProvider::class,
        IntelligenceServiceProvider::class,
        UniversalServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The API is stateless and token-authenticated. No session, no CSRF.
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->alias([
            'jwt'    => AuthenticateJwt::class,
            'tenant'     => EnsureTenantScope::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every error leaves as JSON. The React SPA has no HTML error handling.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // A tenant that resolves nothing is a tenant that does not exist.
        //
        // WHY THIS IS A 404 AND NOT A 500. After an organization is permanently
        // deleted its hpbrain_entity_mappings rows are gone, so EntityResolver
        // correctly refuses to resolve anything for it — that fail-closed
        // behaviour is the whole point of the class and is not being softened
        // here. What was wrong is only how it left the application: a request
        // carrying a validly-signed token for a dissolved tenant produced a 500
        // and a stack trace, which reads as "the server is broken" rather than
        // "that organization is gone".
        //
        // THE DEPLOYMENT CASE IS DELIBERATELY EXCLUDED. notInstalled() is the
        // one constructor that leaves tenantId empty, and it means the schema
        // was never migrated onto this database. That must stay a 500: it is a
        // fault in the installation, and answering 404 would tell an operator
        // their tenant is missing when in fact every tenant is.
        $exceptions->render(function (UnsupportedEntityException $e) {
            if ($e->tenantId === '') {
                return null;
            }

            return response()->json([
                'error'   => 'organization_not_found',
                'message' => 'This organization does not exist or has been permanently deleted.',
            ], 404);
        });
    })
    ->create();
