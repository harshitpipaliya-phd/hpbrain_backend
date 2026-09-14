<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Context\ContextEngine;
use App\Domain\Context\ContextQuery;
use App\Domain\Context\ResolvedContext;
use App\Domain\Universal\EntityResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Turning an HTTP request into a ContextQuery, in one place.
 *
 * WHY A CONCERN AND NOT THREE COPIES. Three endpoints resolve current context —
 * the context endpoint itself, and the two that open an AI conversation
 * (ConversationController and AiWorkspaceController, which write the same
 * sessions table from two different clients). Each needs the same three
 * validation rules and the same split between what the caller may supply and
 * what only the token may. Restating that in three controllers is how one of
 * them eventually reads `screen` from the body and `tenantId` from the query,
 * and the drift would be an authorization defect rather than an inconsistency.
 *
 * THE TENANT IS TAKEN FROM tenantId(), WHICH IS THE MIDDLEWARE'S ATTRIBUTE.
 * Not from the route, not from the body, not from the query string. That is the
 * single most important line in this file.
 */
trait ResolvesCurrentContext
{
    /**
     * Validation rules for the three caller-supplied context parameters.
     *
     * Merged into a controller's own rules, so a route that has other fields
     * keeps them. Every rule is `nullable`: context is always optional, and an
     * endpoint that opens a conversation from the home screen must not start
     * requiring a screen name.
     *
     * `objectType` is checked against the Brain's vocabulary and `screen` is
     * deliberately not — see ContextController::show() for why a screen nobody
     * has declared is an honest answer rather than a 422.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function contextRules(): array
    {
        return [
            'screen'     => ['nullable', 'string', 'max:100'],
            // 36 is the width of hpbrain_signals.related_entity_id — the column
            // that decides whether an id can be a signal's subject at all.
            'objectId'   => ['nullable', 'string', 'max:36'],
            'objectType' => ['nullable', 'string', Rule::in(EntityResolver::ENTITIES)],
        ];
    }

    /**
     * Resolve the caller's current context.
     *
     * @param  array<string, mixed>  $data  the request's validated input
     */
    protected function resolveContext(Request $request, array $data): ResolvedContext
    {
        return app(ContextEngine::class)->resolve(new ContextQuery(
            // Server-resolved by EnsureTenantScope. The whole point.
            tenantId: $this->tenantId($request),
            userId: $this->actorId($request),
            role: $this->tokenString($request, 'auth.role'),
            screen: $this->inputString($data, 'screen'),
            objectId: $this->inputString($data, 'objectId'),
            objectType: $this->inputString($data, 'objectType'),
            sessionId: $this->tokenString($request, 'auth.sessionId'),
            expiresAt: $this->tokenInt($request, 'auth.expiresAt'),
        ));
    }

    /**
     * A token-derived request attribute as a string, or null.
     *
     * Null rather than '' so "the token carried no role" stays distinct from
     * "the role is the empty string" — different facts about the token, which
     * an empty-string default would merge.
     */
    private function tokenString(Request $request, string $key): ?string
    {
        $value = $request->attributes->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function tokenInt(Request $request, string $key): ?int
    {
        $value = $request->attributes->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $data */
    private function inputString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
