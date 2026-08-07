<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Http\Controllers\Controller;
use App\Support\Jwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * Authentication. Unified against the tenant's own employee record.
 *
 * LOGIN IS THE ONE OPERATION WITH NO TENANT TO RESOLVE AGAINST, and that is
 * worth stating because it is the awkward case in an otherwise per-tenant
 * design. Everywhere else the tenant arrives in a verified JWT claim and the
 * source tables follow from it. Here the caller offers an email address and
 * nothing else — the tenant is precisely what the lookup is trying to
 * establish, so it cannot also be its input.
 *
 * The answer is to search every tenant that maps Person and let the matching
 * row name the tenant. Tenants sharing one source table are searched in a
 * single query (see findPersonByEmail), so the common case where an
 * installation runs one ERP costs one query, exactly as before. A second
 * industry on its own tables adds one more query rather than changing code.
 *
 * The alternative — a designated "identity tenant" whose tables are searched
 * for everyone — was rejected: it reintroduces exactly the hardcoded source
 * this work removes, and silently locks out any tenant not on it.
 *
 * NOT YET UNIVERSAL: password, plain_password, deleted_at and updated_at are
 * still named literally. They are credential and audit conventions rather
 * than entity fields, so they sit outside the universal field set. Recorded
 * rather than papered over with a fallback.
 *
 * Identity lives in the ERP, not in hpbrain_auth_users. An earlier build
 * maintained a parallel auth_users table and three separate login endpoints
 * (credential, external proxy, dev bypass). That created:
 *
 *   - identity divergence between ERP and Brain
 *   - a tenantId field in the login body (the rule is: tenant comes from the
 *     verified JWT, never from user input)
 *   - a dev-bypass backdoor
 *   - plaintext password columns being used as a verification path
 *
 * This controller replaces all three with one endpoint that reads the real
 * employee record, resolves the organization from sub_institute_id, resolves
 * the role from the ERP profile table, and issues a JWT whose tenant claim is
 * the verified sub_institute_id.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly EntityResolver $resolver,
    ) {}

    /**
     * Unified login. Reads email + password from the tenant's employee record.
     *
     * Request:
     *   { "email": "...", "password": "..." }
     *
     * Response:
     *   { accessToken, refreshToken, user, organization }
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        [$user, $person] = $this->findPersonByEmail($data['email']);

        if (! $user || ! $this->verifyErpPassword(
            $data['password'],
            $user->password,
            $user->plain_password,
            (int) $user->{$person->primaryKey},
            $person,
        )) {
            return response()->json([
                'error' => 'invalid_credentials',
                'message' => 'Incorrect email or password.',
            ], 401);
        }

        $subInstituteId = (string) $user->{$person->tenantKey};
        $role = $this->resolveRole((int) $user->{$person->field('profile')}, $subInstituteId);
        $userId = (string) $user->{$person->primaryKey};

        $claims = [
            'id' => $userId,
            'tenantId' => $subInstituteId,
            'role' => $role,
        ];

        $accessToken = Jwt::issueAccess($claims);
        $refreshToken = Jwt::issueRefresh($claims);

        $this->events->emit(
            LoopEvent::SESSION_STARTED,
            $subInstituteId,
            'Session',
            Uuid::uuid4()->toString(),
            $userId,
            ['userId' => $userId, 'role' => $role],
        );

        $organization = $this->loadOrganization($subInstituteId);

        return response()->json([
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'user' => [
                'id' => $userId,
                'email' => $user->{$person->field('email')},
                'firstName' => $user->{$person->field('firstName')},
                'lastName' => $user->{$person->field('lastName')},
                'employeeNo' => $user->{$person->field('externalRef')},
                'profileId' => (string) $user->{$person->field('profile')},
                'role' => $role,
            ],
            'organization' => $organization,
        ]);
    }

    /**
     * Find the employee record for an email across every tenant mapping Person.
     *
     * Tenants are grouped by the SHAPE of their source — table plus the columns
     * this lookup touches — so an installation where every tenant shares one
     * ERP issues a single query with the tenant keys as an IN list, which is
     * what the hardcoded version did. Two source systems cost two queries.
     *
     * Groups are searched in tenant order and the first match wins. That
     * ordering only becomes observable if one address exists in two tenants at
     * once; it does not today (verified against the live database: zero
     * addresses span more than one tenant), and the previous implementation had
     * no defined ordering for that case either.
     *
     * @return array{0: ?object, 1: ?ResolvedSource}
     */
    private function findPersonByEmail(string $email): array
    {
        $sources = $this->resolver->everyTenantWith('Person');

        if ($sources === []) {
            throw new \RuntimeException(
                'No tenant maps the Person entity, so no one can sign in. '
                .'Seed hpbrain_entity_mappings (see EntityMappingSeeder).'
            );
        }

        /** @var array<string, array{source: ResolvedSource, tenants: array<int, string>}> $groups */
        $groups = [];

        foreach ($sources as $tenantId => $source) {
            $shape = implode('|', [
                $source->table,
                $source->tenantKey,
                $source->primaryKey,
                $source->field('email'),
                $source->field('status'),
            ]);

            $groups[$shape]['source'] = $source;
            $groups[$shape]['tenants'][] = $tenantId;
        }

        $firstSource = null;

        foreach ($groups as $group) {
            $source = $group['source'];
            $firstSource ??= $source;

            $row = DB::table($source->table)
                ->where($source->field('email'), $email)
                ->where($source->field('status'), 1)
                ->whereIn($source->tenantKey, $group['tenants'])
                ->whereNull('deleted_at')
                ->first();

            if ($row) {
                return [$row, $source];
            }
        }

        // No match. A source is still returned so the caller's failure path has
        // something to reference; $row being null is what denies the login.
        return [null, $firstSource];
    }

    /**
     * Logout. Revokes the supplied refresh token so it cannot be reused.
     *
     * The access token is short-lived and stateless; it expires naturally.
     * The refresh token is long-lived and is the thing that must be killed.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = (string) $request->input('refreshToken', '');

        if ($token !== '') {
            try {
                $claims = Jwt::verify($token);
                if (($claims['type'] ?? null) === 'refresh') {
                    DB::table('hpbrain_refresh_tokens')->updateOrInsert(
                        ['jti' => $claims['jti'] ?? ''],
                        [
                            'tenant_id' => (string) ($claims['tenantId'] ?? ''),
                            'user_id' => (string) ($claims['sub'] ?? ''),
                            'expires_at' => (new \DateTimeImmutable('@'.($claims['exp'] ?? 0)))->format('Y-m-d H:i:s'),
                            'revoked_at' => now()->format('Y-m-d H:i:s'),
                        ]
                    );
                }
            } catch (\Throwable) {
                // If the token is malformed, there is nothing to revoke.
            }
        }

        return response()->json(['ok' => true]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = (string) $request->input('refreshToken');

        try {
            $claims = Jwt::verify($token);
        } catch (\Throwable) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        if (($claims['type'] ?? null) !== 'refresh') {
            return response()->json(['error' => 'wrong_token_type'], 401);
        }

        $jti = (string) ($claims['jti'] ?? '');

        if ($jti !== '' && DB::table('hpbrain_refresh_tokens')->where('jti', $jti)->whereNotNull('revoked_at')->exists()) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        if ($jti !== '') {
            DB::table('hpbrain_refresh_tokens')->updateOrInsert(
                ['jti' => $jti],
                [
                    'tenant_id' => (string) ($claims['tenantId'] ?? ''),
                    'user_id' => (string) ($claims['sub'] ?? ''),
                    'expires_at' => (new \DateTimeImmutable('@'.($claims['exp'] ?? 0)))->format('Y-m-d H:i:s'),
                    'revoked_at' => now()->format('Y-m-d H:i:s'),
                ]
            );
        }

        $user = [
            'id' => $claims['sub'],
            'tenantId' => $claims['tenantId'],
            'role' => $claims['role'],
        ];

        $newJti = Uuid::uuid4()->toString();
        $accessToken = Jwt::issueAccess($user);
        $refreshToken = Jwt::issueRefresh($user, 604800, $newJti);

        DB::table('hpbrain_refresh_tokens')->insert([
            'jti' => $newJti,
            'tenant_id' => (string) ($user['tenantId'] ?? ''),
            'user_id' => (string) ($user['id'] ?? ''),
            'expires_at' => (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'),
            'revoked_at' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8'],
        ]);

        $actor = (string) $this->actorId($request);
        $isNumeric = is_numeric($actor);

        // Unlike login, this runs behind the tenant middleware, so the tenant
        // is already verified and the source resolves directly.
        $person = $isNumeric
            ? $this->resolver->resolve($this->authTenantId($request), 'Person')
            : null;

        if ($isNumeric) {
            $user = DB::table($person->table)
                ->where($person->primaryKey, (int) $actor)
                ->where($person->tenantKey, $this->authTenantId($request))
                ->whereNull('deleted_at')
                ->first();
        } else {
            $user = DB::table('hpbrain_auth_users')->where('id', $actor)->first();
        }

        if (! $user) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        if ($isNumeric) {
            if (! $this->verifyErpPassword(
                $data['currentPassword'],
                $user->password,
                $user->plain_password,
                (int) $user->{$person->primaryKey},
                $person,
            )) {
                return response()->json(['error' => 'invalid_credentials'], 401);
            }

            DB::table($person->table)->where($person->primaryKey, (int) $actor)->update([
                'password' => Hash::make($data['newPassword']),
                'plain_password' => null,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        } else {
            if (! Hash::check($data['currentPassword'], $user->password_hash)) {
                return response()->json(['error' => 'invalid_credentials'], 401);
            }

            DB::table('hpbrain_auth_users')->where('id', $actor)->update([
                'password_hash' => Hash::make($data['newPassword']),
                'updated_date' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Verify an ERP password with backward-compatible migration.
     *
     * Order of checks:
     *   1. Laravel Hash::check against the password column (bcrypt/argon).
     *   2. Direct string comparison against the password column (legacy plain/md5).
     *   3. Direct string comparison against plain_password (legacy plaintext).
     *
     * THE REHASH ONLY RUNS WHEN THERE IS SOMETHING TO MIGRATE. This used to call
     * Hash::make() and UPDATE the user row on EVERY successful login, including the
     * overwhelming majority where the stored value was already a correct bcrypt
     * hash and the write changed nothing but the salt.
     *
     * bcrypt is expensive by design, and that is the point of it — but it means
     * the waste was not small. Measured at the configured cost of 12:
     *
     *     Hash::check  ~376 ms   (necessary: this is the verification)
     *     Hash::make   ~336 ms   (pure waste on an already-hashed password)
     *
     * So roughly half the CPU cost of every login bought nothing, and each login
     * also took a row-level write lock on the ERP's user table, which is shared
     * with the institute system — turning a read-only operation into a write and
     * serialising concurrent logins by the same user.
     *
     * Now: branch 1 (already hashed) rehashes only when Hash::needsRehash() says
     * the stored hash is below the configured cost, which is the one case where
     * re-writing it is actually an upgrade. Branches 2 and 3 are the legacy
     * plaintext/md5 columns and must always migrate — that is the whole reason
     * this method exists.
     *
     * The raw password is never logged or returned.
     */
    private function verifyErpPassword(
        string $raw,
        ?string $passwordColumn,
        ?string $plainPasswordColumn,
        int $userId,
        ResolvedSource $person,
    ): bool {
        $verified = false;
        $needsMigration = false;

        if ($passwordColumn !== null && Hash::check($raw, $passwordColumn)) {
            $verified = true;
            // Already a real hash. Re-write it only to raise a stale work factor.
            $needsMigration = Hash::needsRehash($passwordColumn) || $plainPasswordColumn !== null;
        } elseif ($passwordColumn !== null && hash_equals($passwordColumn, $raw)) {
            $verified = true;
            $needsMigration = true;
        } elseif ($plainPasswordColumn !== null && hash_equals($plainPasswordColumn, $raw)) {
            $verified = true;
            $needsMigration = true;
        }

        if ($verified && $needsMigration) {
            DB::table($person->table)->where($person->primaryKey, $userId)->update([
                'password' => Hash::make($raw),
                'plain_password' => null,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        return $verified;
    }

    /**
     * Resolve the Brain role from the ERP profile name.
     *
     * Mapping is name-based because the ERP profile table is free-form text.
     * Profiles containing 'super admin' become admin, 'admin' becomes
     * tenant_admin, 'manager' becomes manager, 'analyst' becomes analyst,
     * 'viewer' becomes viewer, everything else becomes member.
     */
    private function resolveRole(int $profileId, string $tenantId): string
    {
        if ($profileId <= 0) {
            return 'member';
        }

        $profile = $this->resolver->resolve($tenantId, 'PersonProfile');

        $name = DB::table($profile->table)
            ->where($profile->primaryKey, $profileId)
            ->where($profile->tenantKey, $tenantId)
            ->where($profile->field('status'), 1)
            ->value($profile->field('name'));

        if ($name === null) {
            return 'member';
        }

        $lower = strtolower($name);

        if (str_contains($lower, 'super admin') || str_contains($lower, 'superadmin')) {
            return 'admin';
        }
        if (str_contains($lower, 'admin')) {
            return 'tenant_admin';
        }
        if (str_contains($lower, 'manager') || str_contains($lower, 'head')) {
            return 'manager';
        }
        if (str_contains($lower, 'analyst')) {
            return 'analyst';
        }
        if (str_contains($lower, 'viewer') || str_contains($lower, 'readonly') || str_contains($lower, 'read-only')) {
            return 'viewer';
        }

        return 'member';
    }

    /**
     * Load organization name and logo from the ERP tables.
     */
    private function loadOrganization(string $subInstituteId): array
    {
        try {
            $org = $this->resolver->resolve($subInstituteId, 'Organization');
            $profile = $this->resolver->resolve($subInstituteId, 'OrganizationProfile');

            $row = DB::table($org->table.' as d')
                ->leftJoin($profile->table.' as o', function ($j) use ($org, $profile) {
                    $j->on('o.'.$profile->tenantKey, '=', 'd.'.$org->tenantKey);
                })
                ->where('d.'.$org->tenantKey, $subInstituteId)
                ->whereNull('d.deleted_at')
                ->select(
                    'd.'.$org->field('id').' as id',
                    'd.'.$org->field('name').' as name',
                    'o.'.$profile->field('logo').' as logo'
                )
                ->first();

            if ($row) {
                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'logo' => $row->logo !== null ? (string) $row->logo : null,
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'no such table') || str_contains($message, "doesn't exist")) {
                return [
                    'id' => $subInstituteId,
                    'name' => 'Organization '.$subInstituteId,
                    'logo' => null,
                ];
            }

            throw $e;
        }

        return [
            'id' => $subInstituteId,
            'name' => 'Organization '.$subInstituteId,
            'logo' => null,
        ];
    }
}
