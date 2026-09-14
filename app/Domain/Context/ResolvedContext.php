<?php

declare(strict_types=1);

namespace App\Domain\Context;

/**
 * What the user is currently looking at, as five independently-resolved layers.
 *
 * A DESCRIPTION, NOT A CONCLUSION. Nothing here is inferred, scored, ranked or
 * interpreted. The object is a row that was read, the organization is a row
 * that was read, the signals are the rows whose subject IS that object. No
 * layer's contents depend on another layer's contents, which is what keeps this
 * out of the reasoning path: Enterprise Brain is handed facts, and remains the
 * only thing in the system allowed to draw anything from them.
 *
 * `tenantId` is the SERVER-RESOLVED tenant (EnsureTenantScope), echoed so a
 * consumer can see which organization these facts belong to. It is never the
 * value a caller supplied.
 */
final class ResolvedContext
{
    public function __construct(
        public readonly string $tenantId,
        public readonly ContextLayer $object,
        public readonly ContextLayer $user,
        public readonly ContextLayer $organization,
        public readonly ContextLayer $signals,
        public readonly ContextLayer $session,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenantId'     => $this->tenantId,
            'object'       => $this->object->toArray(),
            'user'         => $this->user->toArray(),
            'organization' => $this->organization->toArray(),
            'signals'      => $this->signals->toArray(),
            'session'      => $this->session->toArray(),
        ];
    }
}
