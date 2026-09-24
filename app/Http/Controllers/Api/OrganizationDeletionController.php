<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Tenancy\TenantDeletionException;
use App\Domain\Tenancy\TenantPurgeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Permanent organization deletion.
 *
 * SEPARATE FROM OrganizationController ON PURPOSE. That controller owns the
 * organization's ordinary lifecycle, including archive() — which sets
 * institute_detail.deleted_at on one row and is still the right operation when
 * someone wants an organization out of the list without destroying it. Renaming
 * archive to "delete", or quietly widening it, would have removed a working
 * capability and left every existing caller of
 * POST /organizations/{t}/{id}/archive doing something it did not ask for.
 *
 * The two operations differ in permission, in HTTP verb, in confirmation
 * requirement and in reversibility, so they are two endpoints.
 *
 * AUTHORIZATION IS ENFORCED HERE AND IN THE ROUTE, NOT IN THE MODAL.
 * The route carries permission:delete,tenant.manage — both of which only ADMIN
 * and TENANT_ADMIN hold — and EnsureTenantScope has already established that
 * the tenant in the URL is the tenant in the verified token. A manager, analyst
 * or viewer is refused by RequirePermission before this class is reached, and a
 * caller from tenant B addressing tenant A is refused with 403 tenant_mismatch
 * before that.
 */
final class OrganizationDeletionController extends Controller
{
    public function __construct(private readonly TenantPurgeService $purge)
    {
    }

    /**
     * GET /organizations/{tenantId}/{id}/deletion-preview
     *
     * Exactly what a permanent deletion would destroy, per table and per tier.
     * Reads only — nothing here writes, so the SPA is free to call it when the
     * confirmation dialog opens.
     */
    public function preview(Request $request, string $tenantId, string $id): JsonResponse
    {
        try {
            return response()->json($this->purge->plan($this->tenantId($request))->toArray());
        } catch (TenantDeletionException $e) {
            return $this->failure($e);
        }
    }

    /**
     * DELETE /organizations/{tenantId}/{id}
     *
     * Body:
     *   confirmName                 (required) the organization's exact name
     *   acknowledgeSourceSystemData (optional) destroy rows this tenant owns in
     *                               tables belonging to other applications
     *
     * Returns 200 only when the whole transaction committed. Any failure is a
     * rollback and a non-2xx, so the SPA can never show "deleted successfully"
     * over a partial deletion.
     */
    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'confirmName'                 => ['required', 'string'],
            'acknowledgeSourceSystemData' => ['sometimes', 'boolean'],
        ]);

        // The tenant comes from the verified token via EnsureTenantScope, never
        // from the URL. The route parameters are validated by that middleware
        // and then deliberately unused here.
        $tenant = $this->tenantId($request);
        $actor  = $this->actorId($request);

        try {
            $result = $this->purge->purge(
                $tenant,
                $data['confirmName'],
                (bool) ($data['acknowledgeSourceSystemData'] ?? false),
                $actor !== '' ? $actor : null,
            );
        } catch (TenantDeletionException $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            // The transaction has already rolled back. The organization, its
            // people, its logins and its data are intact.
            //
            // THE DETAIL GOES TO THE LOG, NOT THE BROWSER — the same rule
            // AuthController::signup follows, and for the same reason: a failed
            // sweep across a shared database is the most likely place for an
            // exception to name a table or constraint belonging to the ERP.
            Log::error('Permanent organization deletion failed and was rolled back', [
                'tenantId'  => $tenant,
                'actorId'   => $actor,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => 'deletion_failed',
                'message' => 'The organization was NOT deleted. Every change has been rolled back '
                    .'and all data remains intact. Please try again or contact support.',
            ], 500);
        }

        return response()->json(['ok' => true] + $result);
    }

    private function failure(TenantDeletionException $e): JsonResponse
    {
        return response()->json([
            'error'   => $e->reason,
            'message' => $e->getMessage(),
        ] + $e->payload, $e->status);
    }
}
