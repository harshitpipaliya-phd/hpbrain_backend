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
use Ramsey\Uuid\Uuid;

/**
 * Auth. Ported from api/src/auth/auth.routes.ts.
 *
 * Auth users live in MySQL (hpbrain_auth_users), not the graph. An early Node
 * build resolved users through Neo4j, which meant nobody could sign in without
 * a reachable graph database — every screen rendered empty for want of a
 * token. Keeping identity in the relational store removes that failure mode
 * entirely, and is consistent with ADR-008 deferring Neo4j.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly EventPublisher $events)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenantId' => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = DB::table('hpbrain_auth_users')
            ->where('tenant_id', $data['tenantId'])
            ->where('email', $data['email'])
            ->first();

        // Same response for "no such user" and "wrong password", so the
        // endpoint cannot be used to enumerate valid accounts.
        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        $claims = ['id' => $user->id, 'tenantId' => $user->tenant_id, 'role' => $user->role];

        // Golden path stage (1): a principal arrives. There is no sessions
        // table — the session exists only as this event — so emit() is correct
        // here rather than an exception to the rule.
        //
        // NOT emitted on refresh(): a refreshed access token continues the
        // session it was issued under, and counting it as a new one would
        // inflate every session metric by the token TTL.
        //
        // NOT emitted on failed login either. A rejected credential is an audit
        // and security concern, not a stage of the reasoning loop; putting it
        // here would let an unauthenticated caller write rows into the loop's
        // event store at will.
        $this->events->emit(
            LoopEvent::SESSION_STARTED,
            (string) $user->tenant_id,
            'Session',
            Uuid::uuid4()->toString(),
            (string) $user->id,
            ['userId' => $user->id, 'role' => $user->role],
        );

        return response()->json([
            'accessToken'  => Jwt::issueAccess($claims),
            'refreshToken' => Jwt::issueRefresh($claims),
            'user'         => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $user->role],
        ]);
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

        $user = ['id' => $claims['sub'], 'tenantId' => $claims['tenantId'], 'role' => $claims['role']];

        return response()->json(['accessToken' => Jwt::issueAccess($user)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword'     => ['required', 'string', 'min:8'],
        ]);

        $id = $this->actorId($request);
        $user = DB::table('hpbrain_auth_users')->where('id', $id)->first();

        if (! $user || ! Hash::check($data['currentPassword'], $user->password_hash)) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        DB::table('hpbrain_auth_users')->where('id', $id)->update([
            'password_hash' => Hash::make($data['newPassword']),
            'updated_date'  => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Local development only — the route is not registered in production. */
    public function devToken(): JsonResponse
    {
        $user = ['id' => 'dev-user-1', 'tenantId' => 'demo-tenant', 'role' => 'admin'];

        return response()->json([
            'accessToken'  => Jwt::issueAccess($user),
            'refreshToken' => Jwt::issueRefresh($user),
        ]);
    }
}
