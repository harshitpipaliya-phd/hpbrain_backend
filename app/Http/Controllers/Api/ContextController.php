<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCurrentContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/context/{tenantId} — what the caller is currently looking at.
 *
 * ONE READ-ONLY ENDPOINT. There is no store(), no update() and no screen of its
 * own: this exists to be called when the AI Assistant opens over an object, and
 * nothing in the product navigates to it.
 *
 * THE {tenantId} SEGMENT IS NOT AN INPUT, IT IS AN ASSERTION THAT IS CHECKED.
 * EnsureTenantScope has already compared it against the verified token claim
 * and answered 403 tenant_mismatch if they differ, so by the time this method
 * runs the two are the same value. The tenant handed to the engine still comes
 * from the middleware's own attribute rather than from the path, so that a
 * future routing change cannot quietly turn the URL into the authority.
 * `?tenantId=` is not read anywhere and is ignored.
 *
 * The parameter is kept in the path because every other tenant-scoped route in
 * this API has the same shape and TenantIsolationMatrixTest sweeps all of them
 * from the live route table — a route without the segment is a route that test
 * cannot cover.
 *
 * `screen`, `objectId` and `objectType` are the only things the caller decides,
 * and none of them grants anything. The worst a caller can do with them is name
 * an object that does not exist in their own organization, which answers
 * present:false.
 */
final class ContextController extends Controller
{
    use ResolvesCurrentContext;

    public function show(Request $request): JsonResponse
    {
        /*
          objectType IS VALIDATED AGAINST THE VOCABULARY, screen IS NOT.

          objectType names an entity the resolver will be asked for, so an
          unrecognised value is refused with 422 — the caller mistyped a
          parameter, and answering "no object" would describe that as a fact
          about the organization instead.

          `screen` is deliberately free text. A screen nothing has declared is
          not an error: the request is still a valid question about the tenant,
          the user and their session, and the response says so by marking
          session.screenKnown false and object.present false with the reason
          unknown_screen. Rejecting it would make adding a screen to the SPA a
          breaking change here.
        */
        $data = $request->validate($this->contextRules());

        return response()->json($this->resolveContext($request, $data)->toArray());
    }
}
