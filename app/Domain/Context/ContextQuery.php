<?php

declare(strict_types=1);

namespace App\Domain\Context;

/**
 * The inputs to one context lookup, separated by who is allowed to supply them.
 *
 * THE SPLIT IS THE WHOLE POINT. `tenantId`, `userId` and `role` are
 * SERVER-RESOLVED — they come from the verified JWT by way of AuthenticateJwt
 * and EnsureTenantScope, and no caller can set them. `screen`, `objectId` and
 * `objectType` are CALLER-SUPPLIED: they say what the user is looking at, which
 * is a claim about the browser, not a claim about authorization.
 *
 * Keeping them in one object with that distinction written down is what stops
 * the two being confused at a call site. A caller-supplied tenant would be an
 * authorization bypass; a caller-supplied objectId is merely a lookup that may
 * find nothing. ContextEngine treats them accordingly — the tenant is applied
 * as a predicate to every single read, and the object is looked for inside it.
 *
 * `sessionId` is the access token's jti and `expiresAt` its exp. Both are facts
 * about the session the caller is already holding; neither is a secret, and
 * neither is the token itself.
 */
final class ContextQuery
{
    public function __construct(
        /** Authoritative tenant, from EnsureTenantScope. Never from the caller. */
        public readonly string $tenantId,
        /** Authenticated user id — the ERP Person primary key, from the `sub` claim. */
        public readonly string $userId,
        /** Verified role claim, or null if the token carried none. */
        public readonly ?string $role = null,
        /** Caller-supplied screen identifier. See ScreenRegistry. */
        public readonly ?string $screen = null,
        /** Caller-supplied object id — a primary key in a mapped source table. */
        public readonly ?string $objectId = null,
        /**
         * Caller-supplied universal entity, overriding the screen's binding.
         * Required for a screen ScreenRegistry does not bind to one.
         */
        public readonly ?string $objectType = null,
        /** The access token's jti, if the request carried one. */
        public readonly ?string $sessionId = null,
        /** The access token's expiry as a unix timestamp, if known. */
        public readonly ?int $expiresAt = null,
    ) {
    }
}
