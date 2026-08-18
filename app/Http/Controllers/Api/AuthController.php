<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Organization\OrganizationSignupService;
use App\Domain\Organization\SignupConflictException;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\SignupRequest;
use App\Support\Jwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Self-service organization signup. Creates a tenant and signs its
     * administrator in.
     *
     * THE ONLY PUBLIC WRITE IN THE API, which is why it is worth being explicit
     * about what it does and does not trust. It takes no tenant id — it MINTS
     * one, from school_setup's AUTO_INCREMENT — so there is nothing here for a
     * caller to point at another organization. Every field in the body is either
     * validated content or discarded. The tenant claim in the token that comes
     * back is the id the database allocated, not anything the browser sent, and
     * from the next request onwards EnsureTenantScope pins the session to it.
     *
     * It returns the SAME envelope as login, deliberately: the SPA finishes
     * signup already authenticated, and one response shape means one code path
     * on the client rather than a second, subtly different session bootstrap.
     *
     * Failure is atomic. OrganizationSignupService wraps every insert in a
     * transaction, so a caller either gets a complete organization or gets none
     * of it — never an id that half-exists.
     */
    public function signup(SignupRequest $request, OrganizationSignupService $signup): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $signup->provision($data);
        } catch (SignupConflictException $e) {
            // Lost a race on a unique key. The transaction rolled back, so
            // nothing was left behind and the message is safe to show.
            return response()->json([
                'error'   => 'signup_conflict',
                'message' => $e->getMessage(),
                'errors'  => ['email' => [$e->getMessage()]],
            ], 422);
        } catch (\Throwable $e) {
            // THE DETAIL GOES TO THE LOG, NOT TO THE BROWSER. A failed signup
            // is the one place where the exception text is most likely to name
            // a table, a column or a constraint of the shared ERP database.
            // Never the password: only non-credential fields are recorded.
            Log::error('Organization signup failed', [
                'exception'        => $e::class,
                'message'          => $e->getMessage(),
                'organizationName' => $data['organizationName'] ?? null,
                'email'            => $data['organizationEmail'] ?? null,
            ]);

            return response()->json([
                'error'   => 'signup_failed',
                'message' => 'We could not create your organization. Please try again.',
            ], 500);
        }

        $tenantId = $result['tenantId'];
        $userId = (string) $result['userId'];

        // Resolved rather than assumed to be 'tenant_admin'. The profile row was
        // just written as 'Admin', so this reads back through exactly the path
        // login uses — if that mapping ever changes, signup and login change
        // together instead of drifting apart.
        $role = $this->resolveRole($result['adminProfileId'], $tenantId);

        $claims = ['id' => $userId, 'tenantId' => $tenantId, 'role' => $role];

        $accessToken = Jwt::issueAccess($claims);
        $refreshToken = Jwt::issueRefresh($claims);

        $this->events->emit(
            LoopEvent::SESSION_STARTED,
            $tenantId,
            'Session',
            Uuid::uuid4()->toString(),
            $userId,
            ['userId' => $userId, 'role' => $role, 'via' => 'signup'],
        );

        return response()->json([
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            // The administrator is derived, so these come back from the service
            // — they are what was written, not what was submitted.
            'user' => [
                'id'         => $userId,
                'email'      => $result['adminEmail'],
                'firstName'  => $result['adminFirstName'],
                'lastName'   => $result['adminLastName'],
                'employeeNo' => null,
                'profileId'  => (string) $result['adminProfileId'],
                'role'       => $role,
            ],
            'organization' => $this->loadOrganization($tenantId),
        ], 201);
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
            // THE DIAGNOSTIC GOES TO THE LOG; THE CALLER GETS 401.
            //
            // This used to throw, which surfaced as a 500 on the login endpoint.
            // The intent was right — an installation where nothing maps Person
            // is misconfigured and somebody needs to be told — but the channel
            // was wrong, and permanent deletion made it reachable in ordinary
            // use: deleting the LAST remaining organization empties the mapping
            // table, and the next sign-in attempt answered "server error"
            // instead of "incorrect email or password".
            //
            // 500 is also the wrong answer on its own terms. The question asked
            // was "are these credentials valid", and when there is no tenant to
            // check them against the answer is no. Returning null lets the
            // caller's existing failure path produce that, unchanged.
            Log::error(
                'Login attempted but no tenant maps the Person entity, so no one can sign in. '
                .'If this is not an empty installation, seed hpbrain_entity_mappings (see EntityMappingSeeder).'
            );

            return [null, null];
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
                // The tenant check keeps logout from RE-CREATING a row for a
                // tenant that was just permanently deleted. updateOrInsert
                // inserts when there is nothing to update, and after a deletion
                // there never is — so signing out of a deleted organization
                // wrote tenant rows straight back into a table the purge had
                // just emptied. There is also nothing to revoke: the tenant's
                // tokens were destroyed with it, and refresh() now refuses them
                // on tenant existence regardless.
                if (($claims['type'] ?? null) === 'refresh'
                    && $this->tenantExists((string) ($claims['tenantId'] ?? ''))) {
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

        // A TOKEN FOR A TENANT THAT NO LONGER EXISTS IS NOT REFRESHABLE.
        //
        // This closed a real hole. The revocation check below asks whether the
        // jti is recorded as revoked, and treats "no row" as "not revoked" —
        // which it has to, because login() issues a refresh token WITHOUT
        // writing a row, so the first refresh after every login has no row to
        // find. Absence therefore meant "fine".
        //
        // After a tenant is permanently deleted its hpbrain_refresh_tokens rows
        // are destroyed along with everything else, so absence meant "fine"
        // there too: a refresh token minted before the deletion still verified
        // (the signature is valid and the expiry has not passed), still passed
        // the revocation check, and was handed a brand-new access token for a
        // tenant that no longer existed — plus a fresh row in
        // hpbrain_refresh_tokens, re-seeding data for the dead tenant. Measured
        // on the live database: HTTP 200 and six recreated rows.
        //
        // The tenant's existence is the thing absence could not distinguish, so
        // it is now checked directly. Nothing about authentication is relaxed:
        // this only ever turns a 200 into a 401.
        if (! $this->tenantExists((string) ($claims['tenantId'] ?? ''))) {
            return response()->json(['error' => 'invalid_token'], 401);
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
     * Whether this tenant is still one the application can authenticate.
     *
     * THE TEST IS THE SAME ONE LOGIN ALREADY USES. findPersonByEmail searches
     * the tenants returned by everyTenantWith('Person'), which is driven
     * entirely by active rows in hpbrain_entity_mappings. A tenant with no
     * Person mapping is not merely unauthorized, it is invisible to login — so
     * "can this tenant still be signed into" and "does it still map Person" are
     * the same question, and asking it this way keeps refresh exactly as
     * permissive as login rather than more or less so.
     *
     * That matters concretely: an earlier version of this checked for a row in
     * the Organization table instead, which refused a tenant whose people could
     * still log in perfectly well, because refresh was applying a stricter rule
     * than the endpoint that issued the token in the first place.
     *
     * It is false after a permanent deletion because TenantPurgeService sweeps
     * hpbrain_entity_mappings along with everything else — 39 rows on the tenant
     * this was verified against.
     *
     * It stays TRUE for an ARCHIVED organization, which is correct: archiving
     * takes an organization out of the list, not out of the world, and its
     * people can still sign in today.
     *
     * NOT called from AuthenticateJwt, and that is a deliberate trade rather
     * than an oversight. Putting it there would cost a query on EVERY
     * authenticated request — against this deployment's remote database, where
     * a trivial SELECT averages 480ms, that is a tax on the whole application
     * to catch a short-lived access token belonging to a deleted tenant. Those
     * tokens already reach nothing: every tenant-scoped read resolves through
     * EntityResolver, which fails closed and returns 404. The refresh path is
     * different because it MINTS new credentials and can extend a session
     * indefinitely, so it is worth the query.
     */
    private function tenantExists(string $tenantId): bool
    {
        if ($tenantId === '') {
            return false;
        }

        try {
            return $this->resolver->has($tenantId, 'Person');
        } catch (\Throwable) {
            // An unreadable mappings table cannot be distinguished from a
            // tenant that is genuinely gone, and refusing to extend a session is
            // the safe answer to that ambiguity.
            return false;
        }
    }

    /**
     * Load organization name and logo from the ERP tables.
     *
     * ARCHIVED ORGANIZATIONS RETURN THEIR REAL NAME, and that is a correction
     * rather than a relaxation. This query used to carry
     * `whereNull('d.deleted_at')`, so an archived organization matched no row
     * and fell through to the placeholder below — a session for tenant 8 came
     * back named "Organization 8" while the database plainly said "Lions".
     *
     * That placeholder then became the SPA's only name for the organization
     * (OrganizationRepository::list() excludes archived rows, correctly, so the
     * list offers no name to correct it with), and a manufactured name is
     * indistinguishable from a real one once it is sitting in session state.
     * The permanent-deletion dialog compared against it and refused every
     * attempt, because the server was comparing against the real name.
     *
     * The filter is gone because the question this method answers is "what is
     * this organization called", and archiving does not change the answer.
     * Archiving governs whether an organization is LISTED — that filter stays
     * exactly where it belongs, in OrganizationRepository::list().
     *
     * The placeholder is kept for the two cases where there genuinely is no
     * name to report: no row at all, and no table at all. Those are honest
     * fallbacks; the archived case never was.
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
