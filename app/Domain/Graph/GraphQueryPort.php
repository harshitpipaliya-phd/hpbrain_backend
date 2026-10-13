<?php

declare(strict_types=1);

namespace App\Domain\Graph;

/**
 * THE SEAM ADR-008 DESCRIBES, MADE REAL.
 *
 * ADR-008 ("Defer Neo4j") says graph-shaped reads live behind a
 * `GraphQueryPort` interface with a MySQL implementation, so reintroducing
 * Neo4j later means adding an adapter, not unpicking call sites. Until this
 * file, that was prose: the name existed only in a comment in
 * `Api\GraphController` and in the ADR itself, never as code.
 * `GraphProjection` is this interface's MySQL implementation — the only one
 * that exists today, per ADR-008's own "revisit when" trigger not yet having
 * fired (3+ hop traversals routinely needed, or ~10^6 relationships for one
 * tenant — neither measured, so not acted on here).
 *
 * The first five method signatures below are copied verbatim from
 * GraphProjection's existing public methods, not redesigned — the point of
 * extracting this now is to formalize the seam that already exists, not to
 * change its shape. `reasoningTrail()` is new: the first read-side method
 * that composes the other five into a multi-hop walk, so the
 * Signal→Evidence→Case→Hypothesis→Recommendation→Decision chain has one
 * place to be read from instead of being re-derived per caller.
 */
interface GraphQueryPort
{
    /**
     * The graph a user sees on opening the screen: their own organization, and
     * the branches it genuinely has.
     *
     * @param  int  $depth  1 shows the organization's branches; 2 and 3 sample a
     *         few members of each so the layered shape is visible without the
     *         user clicking. Bounded by the node budget either way.
     * @param  array<int, string>  $include  intelligence branches the client wants
     * @return array<string, mixed>
     */
    public function overview(string $tenant, int $depth = 1, array $include = []): array;

    /**
     * One hop from one node. This is what clicking "expand" runs.
     *
     * @param  array<int, string>  $include
     * @return array<string, mixed>
     */
    public function expand(string $tenant, string $label, string $id, array $include = [], int $offset = 0): array;

    /**
     * What the right-hand panel shows: this row's own fields, its counts, and
     * the connections it has.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $tenant, string $label, string $id): ?array;

    /**
     * Entity search across every label the graph can draw.
     *
     * @param  array<int, string>  $labels  restrict to these, empty for all
     * @return array<string, mixed>
     */
    public function search(string $tenant, string $term, array $labels = []): array;

    /**
     * The metric strip above the graph.
     *
     * @return array<string, int|string|null>
     */
    public function summary(string $tenant): array;

    /**
     * The Signal→Evidence→Case→Hypothesis→Recommendation→Decision trail
     * reachable from one node in that chain — the read-side plumbing Phase
     * 5's "explain this signal" and "show the reasoning trail" GraphRAG
     * features build on. A bounded, intelligence-family-only walk: $label
     * must be one of GraphVocabulary's FAMILY_INTELLIGENCE labels (Signal,
     * Evidence, Case, Hypothesis, Recommendation, Decision, Capability), and
     * every edge it follows is one of GraphVocabulary::RELATIONSHIPS'
     * 'intelligence'-family entries — never an invented hop.
     *
     * @return array<string, mixed>  GraphBuilder-shaped {nodes, edges,
     *         truncated, ...} plus an ordered `trail` of
     *         {step, label, id, relationship}; {error: 'unsupported_label',
     *         supported: [...]} for a label outside the intelligence family.
     */
    public function reasoningTrail(string $tenant, string $label, string $id): array;
}
