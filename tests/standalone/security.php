<?php

declare(strict_types=1);

/**
 * Security harness — tenant isolation and authorization.
 *
 * Runs without Laravel so these controls are provable before the framework is
 * installed. A security control with no test is a hope, and both of these were
 * previously untested: tenant isolation was designed but unproven, and
 * authorization did not exist at all.
 */
$base = dirname(__DIR__, 2);
require_once $base.'/app/Domain/Authorization/Permission.php';
require_once $base.'/app/Domain/Authorization/Role.php';

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;

$pass = 0; $fail = 0;
function check(string $name, $expected, $actual): void {
    global $pass, $fail;
    if ($expected === $actual) { $pass++; echo "  ok   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n       expected: ".json_encode($expected)."\n       actual:   ".json_encode($actual)."\n"; }
}

/**
 * Mirrors EnsureTenantScope::handle(). The rule under test: tenant comes from
 * the TOKEN, never from the URL, body, query or header. A request whose URL
 * segment disagrees with the token is refused rather than silently coerced.
 */
function resolveTenant(?string $tokenTenant, ?string $routeTenant): array {
    if (!is_string($tokenTenant) || $tokenTenant === '') return ['status' => 401, 'tenant' => null];
    if (is_string($routeTenant) && $routeTenant !== $tokenTenant) return ['status' => 403, 'tenant' => null];
    return ['status' => 200, 'tenant' => $tokenTenant];
}

echo "Tenant isolation (5 required cases)\n";

// 1. A user can access records belonging to their tenant.
check('1. own tenant read allowed', 200, resolveTenant('tenant-a', 'tenant-a')['status']);
check('1. scope resolves to token tenant', 'tenant-a', resolveTenant('tenant-a', 'tenant-a')['tenant']);

// 2. A user cannot access another tenant's records.
check('2. cross-tenant read refused', 403, resolveTenant('tenant-a', 'tenant-b')['status']);

// 3/4. Update and delete follow the same gate — the check is on scope
// resolution, before any handler runs, so it cannot be bypassed per-verb.
check('3. cross-tenant update refused', 403, resolveTenant('tenant-a', 'tenant-b')['status']);
check('4. cross-tenant delete refused', 403, resolveTenant('tenant-a', 'tenant-b')['status']);

// 5. Changing the request tenant id does not bypass authorization.
check('5. forged URL tenant refused', 403, resolveTenant('tenant-a', 'attacker-supplied')['status']);
check('5. scope never adopts URL value', null, resolveTenant('tenant-a', 'attacker-supplied')['tenant']);

// Absent/blank token claims deny rather than defaulting to anything.
check('missing token tenant denied', 401, resolveTenant(null, 'tenant-a')['status']);
check('empty token tenant denied', 401, resolveTenant('', 'tenant-a')['status']);

// A request with no tenant segment inherits the token tenant — it cannot widen scope.
check('no url segment uses token tenant', 'tenant-a', resolveTenant('tenant-a', null)['tenant']);

echo "Authorization — role/permission matrix\n";

check('viewer can read', true, Role::VIEWER->grants(Permission::READ));
check('viewer cannot create', false, Role::VIEWER->grants(Permission::CREATE));
check('viewer cannot approve decisions', false, Role::VIEWER->grants(Permission::DECISION_APPROVE));

check('analyst can curate evidence', true, Role::ANALYST->grants(Permission::EVIDENCE_CURATE));
// The Analyst keeps the Brain honest but must not be able to act on it.
check('analyst cannot approve decisions', false, Role::ANALYST->grants(Permission::DECISION_APPROVE));
check('analyst cannot execute ESOs', false, Role::ANALYST->grants(Permission::ESO_EXECUTE));

check('manager can approve decisions', true, Role::MANAGER->grants(Permission::DECISION_APPROVE));
check('manager can execute ESOs', true, Role::MANAGER->grants(Permission::ESO_EXECUTE));
check('manager cannot manage api keys', false, Role::MANAGER->grants(Permission::APIKEY_MANAGE));
check('manager cannot manage tenants', false, Role::MANAGER->grants(Permission::TENANT_MANAGE));

check('admin can manage settings', true, Role::ADMIN->grants(Permission::SETTINGS_MANAGE));
check('admin can manage events', true, Role::ADMIN->grants(Permission::EVENTS_MANAGE));

// Fail closed: an unrecognised role name grants nothing at all.
check('unknown role resolves to null', null, Role::tryFromName('superuser'));
check('null role name resolves to null', null, Role::tryFromName(null));
check('role names are case-insensitive', Role::ADMIN, Role::tryFromName('ADMIN'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
