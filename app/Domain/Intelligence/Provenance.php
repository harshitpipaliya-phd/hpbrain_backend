<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Where a figure came from, in enough detail that a reader can go and check it.
 *
 * Architecture Invariant 2 — "every decision exposes its reasoning" — is not
 * satisfied by a comment in the source. It is satisfied when the number that
 * reaches the screen carries, in the response body, the tables it was read
 * from, the filter that selected the rows, how many rows there were, and the
 * arithmetic that turned them into the figure.
 *
 * SOURCES ARE RECORDED AS A FILTER, NOT AS A ROW LIST. A knowledge domain built
 * from 65,268 complaints cannot ship its evidence inline, and truncating to the
 * first ten would misrepresent what the figure was computed over. The honest
 * form is the predicate plus the count: anyone can re-run it and get the same
 * rows back. `sampleN` is therefore the population size, never a sample size.
 */
final class Provenance implements \ArrayAccess, \JsonSerializable
{
    /** @var array<int, array<string, mixed>> */
    private array $sources = [];

    /**
     * @param string $computation Plain arithmetic, e.g. "closed / total".
     *                            Written so a reader can reproduce the figure
     *                            from the source counts alone.
     */
    public function __construct(private readonly string $computation)
    {
    }

    public static function of(string $computation): self
    {
        return new self($computation);
    }

    /**
     * Name one set of rows the figure was computed over.
     *
     * @param string               $table  Physical table, so the reader can query it.
     * @param array<string, mixed> $filter The predicate that selected the rows.
     * @param int                  $rows   How many rows matched.
     */
    public function from(string $table, array $filter, int $rows): self
    {
        $this->sources[] = ['table' => $table, 'filter' => $filter, 'rows' => $rows];

        return $this;
    }

    /** Total rows across every source — the denominator behind the claim. */
    public function rowCount(): int
    {
        return array_sum(array_column($this->sources, 'rows'));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'computation' => $this->computation,
            'sources'     => $this->sources,
            'totalRows'   => $this->rowCount(),
        ];
    }

    /* ─────────────────── read as an array, like every other field ─────────────────── */

    /**
     * ArrayAccess exists so a Provenance reads the same way in PHP as it does in JSON.
     *
     * The analyzers hand back payloads where `confidence` is already a plain array —
     * KnowledgeAnalyzer serialises it, because the state summary and the recommendation
     * engine both need to read `['value']` out of it. Provenance was left as an object,
     * so `$gap['provenance']['sources']` worked over HTTP and threw in process, and the
     * first thing to notice was a test asserting on tenant scope.
     *
     * Serialising at every one of the fifteen construction sites would work and would
     * be fifteen places to forget. Reading through to jsonSerialize() makes the two
     * views the same by construction, which is the property that was missing.
     *
     * WRITES ARE REFUSED. A provenance is a record of how a figure was derived; if it
     * could be edited after the fact it would stop being evidence of anything.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->jsonSerialize());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->jsonSerialize()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Provenance is immutable: it records how a figure was derived and cannot be edited afterwards.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Provenance is immutable: it records how a figure was derived and cannot be edited afterwards.');
    }
}
