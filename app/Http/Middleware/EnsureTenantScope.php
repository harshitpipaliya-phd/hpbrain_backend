<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Universal\EntityResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant isolation.
 *
 * The rule that must never bend: a caller may not read another tenant's data by
 * editing a URL. The tenant is always resolved HERE and stashed on the request;
 * nothing downstream may take a tenantId from a body or query parameter.
 *
 * What changed, and why it had to:
 *
 * The Brain addresses tenants by the institute's sub_institute_id — signals and
 * cases are stored under tenant_id '6', which is Scholar Clone in
 * the organization register. But a token carries exactly ONE tenant claim, and the
 * dev-bypass token carries 'demo-tenant'. Requiring route tenant === token
 * tenant therefore made every organization except the token's own unreadable:
 * /signals/6 answered 403, and since no row anywhere is stored under
 * 'demo-tenant', /signals/demo-tenant answered 200 with an empty list. The
 * intelligence screens could not show their data under any URL.
 *
 * An admin may now address any organization that actually exists. That is a real
 * widening, so it is bounded three ways:
 *
 *   1. Role — only 'admin' may cross tenants. Every other role stays pinned to
 *      its own token claim, exactly as before.
 *   2. Existence — the tenant must match a live sub_institute_id, so the segment
 *      cannot be used to probe for arbitrary values.
 *   3. Resolution — the tenant is still decided here and written to the request
 *      attributes, so controllers keep reading $this->tenantId($request) and
 *      cannot be handed a tenant by the client any other way.
 *
 * A non-admin crossing tenants, or anyone naming an organization that does not
 * exist, still gets 403.
 */
final class EnsureTenantScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenTenant = $request->attributes->get('auth.tenantId');

        if (! is_string($tokenTenant) || $tokenTenant === '') {
            return response()->json(['error' => 'tenant_unresolved'], 401);
        }

        $routeTenant = $request->route('tenantId');

        if (is_string($routeTenant) && $routeTenant !== $tokenTenant) {
            $isAdmin = $request->attributes->get('auth.role') === 'admin';

            if (! $isAdmin || ! $this->organizationExists($routeTenant)) {
                return response()->json(['error' => 'tenant_mismatch'], 403);
            }

            $request->attributes->set('tenantId', $routeTenant);

            return $next($request);
        }

        $request->attributes->set('tenantId', $tokenTenant);

        return $next($request);
    }

    /**
     * Is this an organization the ERP actually has?
     *
     * Deliberately NOT memoised. A `static` cache here survives the request that
     * populated it — across every request in a test process, and for the life of
     * the worker under Octane — so an organization archived a minute ago would
     * keep clearing this check, and one created a minute ago would keep failing
     * it. A stale authorization answer is worse than a query. The query only
     * runs on the cross-tenant path anyway (route tenant ≠ token tenant), and it
     * is a single indexed EXISTS.
     *
     * Fails CLOSED. If the organization register is unreachable — not
     * migrated, wrong connection, ERP down — the honest answer is "cannot
     * confirm", and the only safe reading of that is no. Letting the
     * exception escape turned a would-be 403 into a 500: a tenant check that
     * crashes must never be mistaken for a tenant check that passed.
     *
     * An UNMAPPED tenant now takes the same path. resolve() throws, the catch
     * returns false, and the answer is 403. That is the correct reading: a
     * tenant the vocabulary layer cannot describe is not a tenant this
     * request may address.
     */
    private function organizationExists(string $tenantId): bool
    {
        try {
            $org = app(EntityResolver::class)->resolve($tenantId, 'Organization');

            return DB::table($org->table)
                ->where($org->tenantKey, $tenantId)
                ->whereNull('deleted_at')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
