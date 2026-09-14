<?php

declare(strict_types=1);

namespace App\Domain\Context;

/**
 * One layer of resolved context, and whether it is actually there.
 *
 * THE POINT OF THIS CLASS IS THE `reason` FIELD. Five layers are resolved
 * independently and any of them can legitimately come back empty — no object
 * was named, the tenant does not map Person, the row was deleted, the signal
 * store is not migrated on this connection. A response that expressed all of
 * those the same way, as an absent key or a null, would make "we looked and
 * there is nothing" indistinguishable from "we could not look". The AI
 * Assistant consumes this, and those two cases must never collapse: the first
 * is a fact about the organization, the second is a fact about the lookup.
 *
 * So an absent layer must NAME why, from a fixed vocabulary, and a present
 * layer carries no reason at all. There is no third state and no way to
 * construct one — absent() requires a reason and present() refuses to take one.
 *
 * This is the same discipline GroundedClaims applies to model output, at the
 * other end of the pipeline: nothing is presented as resolved unless it was.
 */
final class ContextLayer
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly bool $present,
        public readonly ?string $reason,
        public readonly array $data,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function present(array $data): self
    {
        return new self(true, null, $data);
    }

    /**
     * A layer that did not resolve, and why.
     *
     * $reason is a stable machine-readable token, not a sentence: the client
     * branches on it and the tests assert on it. See ContextEngine for the
     * complete vocabulary each layer can emit.
     *
     * @param  array<string, mixed>  $data  keys the shape promises, nulled out —
     *         so a consumer reading ->data['id'] gets null rather than a
     *         missing-key error on the absent path.
     */
    public static function absent(string $reason, array $data = []): self
    {
        return new self(false, $reason, $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['present' => $this->present];

        if (! $this->present) {
            $out['reason'] = $this->reason;
        }

        return $out + $this->data;
    }
}
