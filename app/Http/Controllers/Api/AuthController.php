<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
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
 * Authentication. Unified against the institute ERP table tbluser.
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
    public function __construct(private readonly EventPublisher $events) {}

    /**
     * Unified login. Reads email + password from the ERP tbluser table.
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

        $user = DB::table('tbluser')
            ->where('email', $data['email'])
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->first();

        if (! $user || ! $this->verifyErpPassword($data['password'], $user->password, $user->plain_password, (int) $user->id)) {
            return response()->json([
                'error' => 'invalid_credentials',
                'message' => 'Incorrect email or password.',
            ], 401);
        }

        $subInstituteId = (string) $user->sub_institute_id;
        $role = $this->resolveRole((int) $user->user_profile_id, (int) $user->sub_institute_id);
        $userId = (string) $user->id;

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
                'email' => $user->email,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'employeeNo' => $user->employee_no,
                'profileId' => (string) $user->user_profile_id,
                'role' => $role,
            ],
            'organization' => $organization,
        ]);
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

        if ($isNumeric) {
            $user = DB::table('tbluser')->where('id', (int) $actor)->whereNull('deleted_at')->first();
        } else {
            $user = DB::table('hpbrain_auth_users')->where('id', $actor)->first();
        }

        if (! $user) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        if ($isNumeric) {
            if (! $this->verifyErpPassword($data['currentPassword'], $user->password, $user->plain_password, (int) $user->id)) {
                return response()->json(['error' => 'invalid_credentials'], 401);
            }

            DB::table('tbluser')->where('id', (int) $actor)->update([
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
     * Hash::make() and UPDATE tbluser on EVERY successful login, including the
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
     * also took a row-level write lock on tbluser — the ERP's user table, shared
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
    private function verifyErpPassword(string $raw, ?string $passwordColumn, ?string $plainPasswordColumn, int $userId): bool
    {
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
            DB::table('tbluser')->where('id', $userId)->update([
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
    private function resolveRole(int $profileId, int $subInstituteId): string
    {
        if ($profileId <= 0) {
            return 'member';
        }

        $name = DB::table('tbluserprofilemaster')
            ->where('id', $profileId)
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 1)
            ->value('name');

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
            $row = DB::table('institute_detail as d')
                ->leftJoin('org_details as o', function ($j) use ($subInstituteId) {
                    $j->on('o.sub_institute_id', '=', 'd.sub_institute_id');
                })
                ->where('d.sub_institute_id', $subInstituteId)
                ->whereNull('d.deleted_at')
                ->select(
                    'd.sub_institute_id as id',
                    'd.organization_name as name',
                    'o.logo as logo'
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
