<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * Emits contracts/openapi/hpbrain.openapi.yaml from the live route table.
 *
 * WHY AN ARTISAN COMMAND RATHER THAN A NODE SCRIPT. A Node generator would have
 * to parse routes/api.php with regular expressions, and that is precisely the
 * class of tool that produced the defect this module exists to close: the API
 * matrix matched URLs textually, called a route with no controller method a
 * MATCH, and the first dispatch was fatal. Laravel's router has already
 * resolved every route, its middleware stack and its controller binding — using
 * that is the difference between reading the contract and guessing at it.
 *
 * THE RULE THAT SHAPES EVERYTHING BELOW: a shape that cannot be derived is
 * marked `x-unverified: true`, never invented. An invented schema passes CI,
 * generates a confident-looking client type, and lies to every consumer of it —
 * strictly worse than an absent one, which at least announces the gap. Every
 * `x-unverified` in the output is a piece of honest work someone still has to
 * do, and the count is reported so it can be burned down deliberately.
 *
 * What IS derived, and how:
 *   - method, path, path parameters       — the router
 *   - required permissions                — the middleware stack
 *   - request body                        — the $request->validate([...]) rules
 *                                           in the controller method's source
 *   - error responses and their codes     — literal response()->json(['error' =>
 *                                           '...'], NNN) in the same source
 * What is NOT derived: success response bodies. Almost every controller returns
 * a raw database row, whose shape lives in a migration rather than in the
 * method, so those are marked unverified rather than guessed at.
 */
final class GenerateOpenApi extends Command
{
    protected $signature = 'brain:openapi
        {--out= : Output path (defaults to contracts/openapi/hpbrain.openapi.yaml)}';

    protected $description = 'Generate the OpenAPI schema from the live route table.';

    /** Permissions the API declares, so the generator can label them as such. */
    private const PERMISSION_MIDDLEWARE = 'permission:';

    private int $unverifiedRequests = 0;

    /** @var array<int, string> */
    private array $unverifiedOperations = [];

    public function handle(): int
    {
        $paths = [];
        $count = 0;

        foreach ($this->apiRoutes() as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                // brain:check-routes fails the build on these; the schema simply
                // must not describe an endpoint that cannot be dispatched.
                $this->warn("skipping unresolvable route: {$action}");
                continue;
            }

            $source = $this->methodSource($class, $method);

            // servers.url already carries /api/v1, so the path must not repeat
            // it — a generated client would otherwise request
            // /api/v1/api/v1/signals and 404 on every call.
            $path = '/'.ltrim(preg_replace('#^api/v1/?#', '', $route->uri()) ?? '', '/');

            foreach (array_diff($route->methods(), ['HEAD']) as $httpMethod) {
                $paths[$path][strtolower($httpMethod)] = $this->operation(
                    $route, $class, $method, $source, $httpMethod, $path
                );
                $count++;
            }
        }

        ksort($paths);

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title'   => 'HP Enterprise Brain API',
                'version' => '1.0.0',
                'description' => trim('
GENERATED FROM routes/api.php by `php artisan brain:openapi`. Do not edit by hand.

Operations marked `x-unverified: true` have a shape this generator could not
derive from source. They are gaps to close, not schemas to trust: no client
should generate a type from an unverified operation and believe it.
                '),
            ],
            'servers' => [['url' => '/api/v1', 'description' => 'Versioned API root']],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => ['error' => ['type' => 'string']],
                        'required' => ['error'],
                    ],
                ],
                // Referenced by every operation. The three middleware gates
                // answer these before a controller is reached, so they are a
                // property of the API rather than of any one endpoint.
                'responses' => [
                    'Unauthenticated' => [
                        'description' => 'Missing, malformed, expired or wrong-type token (AuthenticateJwt), '
                            .'or a token carrying no tenant claim (EnsureTenantScope).',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                    ],
                    'Forbidden' => [
                        'description' => 'Role lacks the required permission (RequirePermission), or the route '
                            .'tenant does not match the token tenant (EnsureTenantScope).',
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'properties' => [
                                'error'    => ['type' => 'string'],
                                'required' => ['type' => 'string', 'description' => 'The permission that was missing.'],
                                'role'     => ['type' => 'string'],
                                'reason'   => ['type' => 'string'],
                            ],
                            'required' => ['error'],
                        ]]],
                    ],
                ],
            ],
            'paths' => $paths,
        ];

        $out = (string) ($this->option('out') ?: base_path('contracts/openapi/hpbrain.openapi.yaml'));

        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0755, true);
        }

        file_put_contents($out, Yaml::dump($document, 12, 2, Yaml::DUMP_OBJECT_AS_MAP));

        $this->info("brain:openapi: {$count} operations -> {$out}");
        $this->line("  request bodies marked x-unverified: {$this->unverifiedRequests}");
        $this->line('  every success response is x-unverified (raw DB rows; see the class docblock)');

        if ($this->unverifiedOperations !== []) {
            $this->line('  unverified request bodies:');
            foreach ($this->unverifiedOperations as $operation) {
                $this->line("    - {$operation}");
            }
        }

        return self::SUCCESS;
    }

    /** @return array<int, \Illuminate\Routing\Route> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => str_starts_with($route->uri(), 'api/')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(
        $route,
        string $class,
        string $method,
        string $source,
        string $httpMethod,
        string $path,
    ): array {
        $operation = [
            'operationId' => $this->operationId($class, $method, $httpMethod, $path),
            'summary'     => class_basename($class).'::'.$method,
            'tags'        => [str_replace('Controller', '', class_basename($class))],
            'security'    => [['bearerAuth' => []]],
            // The stack is documented rather than summarised: the reason a
            // route is or is not reachable by a role is the middleware list,
            // and a reader should not have to open routes/api.php to see it.
            'x-middleware'  => array_values($route->gatherMiddleware()),
            'x-permissions' => $this->permissions($route),
            'x-controller'  => $class.'@'.$method,
        ];

        $parameters = $this->pathParameters($path);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if (in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true)) {
            $operation['requestBody'] = $this->requestBody($source, $class, $method);
        }

        $operation['responses'] = $this->responses($source, $httpMethod);

        return $operation;
    }

    /**
     * Path parameters, taken from the URI template. All are strings: the router
     * binds them as strings and every controller signature receives them as
     * such, whatever the underlying column type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{(\w+)\??\}/', $path, $matches);

        return array_map(fn (string $name) => [
            'name'     => $name,
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => 'string'],
            'description' => $name === 'tenantId'
                // Worth stating in the contract: the segment is checked, but it
                // is NOT what scopes the query.
                ? 'Tenant segment. EnsureTenantScope resolves the effective tenant from the TOKEN; this segment must match it (admins may cross to an existing organization).'
                : '',
        ], $matches[1]);
    }

    /** @return array<int, string> */
    private function permissions($route): array
    {
        $permissions = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, self::PERMISSION_MIDDLEWARE)) {
                foreach (explode(',', substr($middleware, strlen(self::PERMISSION_MIDDLEWARE))) as $permission) {
                    $permissions[] = $permission;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * The request body, derived from the controller's own validate() call.
     *
     * This is the honest source: those rules ARE the contract the endpoint
     * enforces at runtime, so a schema derived from them cannot drift from
     * behaviour the way a hand-written one does.
     *
     * @return array<string, mixed>
     */
    private function requestBody(string $source, string $class, string $method): array
    {
        $rules = $this->extractValidationRules($source);

        if ($rules === []) {
            $this->unverifiedRequests++;
            $this->unverifiedOperations[] = class_basename($class).'::'.$method;

            return [
                'required' => false,
                'x-unverified' => true,
                'description' => 'No validate() call found in the controller method; '
                    .'the accepted body could not be derived and is NOT described here.',
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]],
            ];
        }

        $properties = [];
        $required   = [];

        foreach ($rules as $field => $ruleList) {
            // Nested keys (provenance.ts) are documented on the parent object
            // rather than as a top-level field with a dot in its name.
            if (str_contains($field, '.')) {
                [$parent, $child] = explode('.', $field, 2);

                if ($child === '*') {
                    $properties[$parent]['items'] = $this->schemaFor($ruleList);
                    continue;
                }

                $properties[$parent]['type'] = 'object';
                $properties[$parent]['properties'][$child] = $this->schemaFor($ruleList);

                if (in_array('required', $ruleList, true)) {
                    $properties[$parent]['required'][] = $child;
                }

                continue;
            }

            $properties[$field] = array_merge($properties[$field] ?? [], $this->schemaFor($ruleList));

            if (in_array('required', $ruleList, true)) {
                $required[] = $field;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return [
            'required' => $required !== [],
            'content'  => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * @param  array<int, string>  $rules
     * @return array<string, mixed>
     */
    private function schemaFor(array $rules): array
    {
        $schema = ['type' => 'string'];

        foreach ($rules as $rule) {
            if ($rule === 'array') {
                $schema['type'] = 'array';
            } elseif ($rule === 'integer') {
                $schema['type'] = 'integer';
            } elseif ($rule === 'numeric') {
                $schema['type'] = 'number';
            } elseif ($rule === 'boolean') {
                $schema['type'] = 'boolean';
            } elseif ($rule === 'date') {
                $schema['format'] = 'date-time';
            } elseif ($rule === 'email') {
                $schema['format'] = 'email';
            } elseif ($rule === 'nullable') {
                $schema['nullable'] = true;
            } elseif (str_starts_with($rule, 'size:')) {
                $size = (int) substr($rule, 5);
                $schema['minLength'] = $size;
                $schema['maxLength'] = $size;
            } elseif (str_starts_with($rule, 'min:')) {
                $key = ($schema['type'] ?? 'string') === 'string' ? 'minLength' : 'minimum';
                $schema[$key] = (float) substr($rule, 4);
            } elseif (str_starts_with($rule, 'max:')) {
                $key = ($schema['type'] ?? 'string') === 'string' ? 'maxLength' : 'maximum';
                $schema[$key] = (float) substr($rule, 4);
            } elseif (str_starts_with($rule, 'between:')) {
                [$low, $high] = array_pad(explode(',', substr($rule, 8)), 2, null);
                $schema['minimum'] = (float) $low;
                $schema['maximum'] = (float) $high;
            } elseif (str_starts_with($rule, 'enum:')) {
                $schema['enum'] = explode('|', substr($rule, 5));
            }
        }

        return $schema;
    }

    /**
     * Pull `'field' => ['rule', ...]` pairs out of a validate() call.
     *
     * Deliberately a source scan rather than an execution: instantiating a
     * controller and invoking it to observe its rules would need a request, a
     * tenant and a database. The trade is that a rule built dynamically at
     * runtime is invisible here — which is why a method whose rules cannot be
     * read produces x-unverified rather than an empty schema that would read as
     * "this endpoint accepts nothing".
     *
     * @return array<string, array<int, string>>
     */
    private function extractValidationRules(string $source): array
    {
        $block = $this->validationArrayLiteral($source);

        if ($block === null) {
            return [];
        }

        preg_match_all(
            "/'([\w.*]+)'\s*=>\s*\[(.*?)\]/s",
            $block,
            $matches,
            PREG_SET_ORDER
        );

        $rules = [];

        foreach ($matches as $match) {
            $field = $match[1];
            $raw   = $match[2];
            $list  = [];

            // Plain string rules: 'required', 'string', 'size:36', ...
            preg_match_all("/'([^']+)'/", $raw, $strings);
            foreach ($strings[1] as $rule) {
                $list[] = $rule;
            }

            // Rule::in(['a','b']) — the enum is part of the contract and is the
            // single most useful thing a client generator can be told.
            if (preg_match('/Rule::in\(\[(.*?)\]\)/s', $raw, $in)) {
                preg_match_all("/'([^']+)'/", $in[1], $values);
                if ($values[1] !== []) {
                    // Filter out the plain rules already captured from inside
                    // the Rule::in() argument list.
                    $list = array_values(array_diff($list, $values[1]));
                    $list[] = 'enum:'.implode('|', $values[1]);
                }
            }

            $rules[$field] = $list;
        }

        return $rules;
    }

    /**
     * The array literal passed to validate(), found by bracket matching.
     *
     * A regular expression cannot do this correctly: the rules array contains
     * nested arrays, so any pattern ending at the first `]` truncates and any
     * pattern ending at the last one over-reads into the rest of the method.
     * Walking the string with a depth counter — while skipping quoted
     * segments, since a rule string may itself contain a bracket — is the only
     * way to end at the RIGHT bracket. The first version of this used a regex
     * anchored on a newline and silently reported 26 endpoints as having no
     * validation, several of which simply wrote their rules on one line.
     */
    private function validationArrayLiteral(string $source): ?string
    {
        $start = strpos($source, 'validate(');

        if ($start === false) {
            return null;
        }

        $open = strpos($source, '[', $start);

        if ($open === false) {
            return null;
        }

        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                // Skip an escaped quote inside a string literal.
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            // Comments are skipped whole. An apostrophe in ordinary prose —
            // "an outcome that cites no evidence is somebody's opinion" — would
            // otherwise open a string literal that never closes, and the walk
            // would run off the end of the method. That is not hypothetical:
            // it silently hid the validation rules of seven endpoints.
            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $newline = strpos($source, "\n", $i);
                $i = $newline === false ? $length : $newline;
                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $close = strpos($source, '*/', $i);
                $i = $close === false ? $length : $close + 1;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    /**
     * Success response plus every error this method can literally return.
     *
     * The error codes ARE derivable — they are string literals next to their
     * status codes in the source — and they are the half of the contract a
     * client most often gets wrong. The success body is not derivable, and is
     * marked as such rather than guessed.
     *
     * @return array<string, mixed>
     */
    private function responses(string $source, string $httpMethod): array
    {
        $responses = [];

        preg_match_all(
            "/response\(\)->json\(\s*\[\s*'error'\s*=>\s*'([\w.]+)'.*?\]\s*,\s*(\d{3})\s*\)/s",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $byStatus = [];

        foreach ($matches as $match) {
            $byStatus[$match[2]][] = $match[1];
        }

        foreach ($byStatus as $status => $errors) {
            $responses[(string) $status] = [
                'description' => 'Refused: '.implode(', ', array_unique($errors)),
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['error' => ['type' => 'string', 'enum' => array_values(array_unique($errors))]],
                    'required' => ['error'],
                ]]],
            ];
        }

        $successCode = str_contains($source, ', 201)') && $httpMethod === 'POST' ? '201' : '200';

        $responses[$successCode] = [
            'description' => 'Success.',
            // The one thing this generator refuses to guess. Most controllers
            // return a raw database row, whose shape is defined by a migration
            // rather than by anything visible in the method — deriving it would
            // mean inferring a schema from DDL and hoping the SELECT matched.
            'x-unverified' => true,
            'content' => ['application/json' => ['schema' => ['x-unverified' => true]]],
        ];

        // Every authenticated route can answer these; documenting them once per
        // operation is what lets a generated client handle them uniformly.
        $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
        $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];

        ksort($responses);

        return $responses;
    }

    private function operationId(string $class, string $method, string $httpMethod, string $path): string
    {
        $base = lcfirst(str_replace('Controller', '', class_basename($class))).ucfirst($method);

        // Two routes can share a controller method (the same show() reached by
        // two paths). The operationId must still be unique, so the path is
        // folded in when it would otherwise collide.
        return $base.'_'.strtolower($httpMethod).'_'.preg_replace('/[^a-z0-9]+/i', '_', trim($path, '/'));
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();

        if ($file === false) {
            return '';
        }

        $lines = file($file) ?: [];

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
