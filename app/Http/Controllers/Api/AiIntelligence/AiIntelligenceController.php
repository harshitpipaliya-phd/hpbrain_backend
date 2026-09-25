<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\Tenancy\TenantOwnedTables;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Shared base for the AI & Intelligence API (`/api/v1/ai-intelligence/*`).
 *
 * The envelope is G2G's `{success, message, data, errors}`, and so is the
 * exception mapping — ValidationException 422 with `errors`, AuthorizationException
 * 403, any other RuntimeException 422 with its (domain, safe-to-show) message,
 * anything else 500 with the detail logged rather than returned — because the
 * console's screens are ported 1:1 from G2G's.
 *
 * `scope()` is the only source of the tenant: it is built from the attributes the
 * `jwt` and `tenant` middleware set from the caller's token, never from input.
 */
abstract class AiIntelligenceController extends Controller
{
    protected function scope(Request $request): AiIntelligenceScope
    {
        $tenantId = $this->tenantId($request);

        // A reserved id ('*', 'platform', …) addresses every tenant's shared rows.
        // A token carrying one must not be able to write — or read — as "everyone".
        if ($tenantId === '' || in_array(strtolower($tenantId), TenantOwnedTables::RESERVED_TENANT_IDS, true)) {
            throw ValidationException::withMessages([
                'context' => ['The request scope could not be resolved.'],
            ]);
        }

        return new AiIntelligenceScope(
            tenantId: $tenantId,
            userId: $this->actorId($request),
            role: (string) $request->attributes->get('auth.role', ''),
        );
    }

    protected function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    protected function failure(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }

    protected function handle(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return $this->failure('The request was not valid.', 422, $exception->errors());
        }

        if ($exception instanceof AuthorizationException) {
            return $this->failure($exception->getMessage(), 403);
        }

        if ($exception instanceof RuntimeException) {
            return $this->failure($exception->getMessage(), 422);
        }

        report($exception);

        return $this->failure('The request could not be completed.', 500);
    }

    protected function limit(Request $request, int $default = 50, int $max = 200): int
    {
        return max(1, min((int) $request->input('limit', $default), $max));
    }
}
