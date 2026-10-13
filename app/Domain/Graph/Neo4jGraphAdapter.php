<?php

declare(strict_types=1);

namespace App\Domain\Graph;

use InvalidArgumentException;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\Node;
use RuntimeException;

/**
 * THE SELECTABLE-BUT-NOT-DEFAULT SECOND IMPLEMENTATION OF GraphQueryPort.
 *
 * ADR-008 defers Neo4j out of v1 and names MySQL (GraphProjection) the sole
 * store for that version; it also says the seam should make reintroducing
 * Neo4j later "adding an adapter, not unpicking call sites." This class is
 * that adapter, made real without reopening the ADR's decision:
 * config('graph.driver') still defaults to 'mysql' (config/graph.php), and
 * nothing in this installation's .env provisions real NEO4J_* credentials —
 * confirmed absent before this file was written, not assumed.
 *
 * LAZY CLIENT, DELIBERATELY. The constructor never builds a real
 * Laudis\Neo4j client and never touches a socket. client() does that, on
 * the FIRST actual query — not when the container resolves this class. That
 * means app()->make(Neo4jGraphAdapter::class) (which only happens at all
 * when graph.driver=neo4j is explicitly selected, never by default) never
 * risks a network attempt merely by existing, and a unit test can hand the
 * constructor a mocked ClientInterface directly and never go near
 * ClientBuilder or a socket at all.
 *
 * EVERY LABEL AND RELATIONSHIP TYPE IS CHECKED AGAINST GraphVocabulary
 * before it is placed in a Cypher string. assertKnownLabel() guards every
 * method that takes a caller-supplied $label; allKnownRelationshipTypes()
 * and intelligenceRelationshipTypes() are the only sources of relationship
 * type names used in any query here — a Cypher string in this file can
 * never ask Neo4j about a label or edge this installation has not declared.
 *
 * TENANT SCOPE. Every query binds $tenant as a Cypher parameter and every
 * MATCH filters nodes on tenantId = $tenant — never string-concatenated,
 * never a traversal that can cross tenants structurally. Same invariant
 * GraphProjection states for its own MySQL reads.
 *
 * OUTPUT SHAPE MATCHES GraphProjection's BY CONSTRUCTION, NOT BY PROMISE.
 * Every method assembles its response through the same GraphBuilder MySQL
 * uses — pure PHP bookkeeping, zero DB:: calls in it — so a future flip of
 * graph.driver is shape-compatible with existing clients from day one, not
 * from a separate reconciliation effort.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not replicate every business
 * rule GraphProjection composes from OrganizationStructureService,
 * FoundationCounts, DatasetRegistry etc. — those are MySQL-specific
 * derivations (teaching sections derived from student rows, dataset role
 * inference, and so on) with no Neo4j equivalent unless and until a real
 * sync pipeline populates one. This adapter reads whatever a tenant's graph
 * genuinely holds, generically, by label and relationship type, and is
 * honest about an empty result rather than falling back to MySQL to fill
 * gaps — a GraphQueryPort implementation that silently reads two stores
 * depending on what the first one lacks would defeat the point of the seam.
 */
final class Neo4jGraphAdapter implements GraphQueryPort
{
    private const OVERVIEW_BUDGET = 220;

    private const EXPAND_BUDGET = 140;

    private const NEIGHBOUR_LIMIT = 20;

    private const SEARCH_LIMIT = 50;

    /** Steps in the reasoningTrail() walk, not nodes in the result — mirrors GraphProjection's own bound. */
    private const TRAIL_MAX_STEPS = 50;

    private ?ClientInterface $builtClient = null;

    /**
     * @param  ClientInterface|null  $injectedClient  Tests pass a mock here
     *         directly. Production code never does — it is resolved via the
     *         container with no argument, and the real client (if this
     *         adapter is ever selected) is built lazily by client() below.
     */
    public function __construct(private readonly ?ClientInterface $injectedClient = null)
    {
    }

    /* ═══════════════════════════════════════════════════════════ overview ══ */

    public function overview(string $tenant, int $depth = 1, array $include = []): array
    {
        $depth = max(1, min(3, $depth));
        $builder = new GraphBuilder(self::OVERVIEW_BUDGET);

        $orgRows = $this->run(
            'MATCH (org:Organization {tenantId: $tenant}) RETURN org LIMIT 1',
            ['tenant' => $tenant],
        );

        if ($orgRows === []) {
            return $builder->result() + [
                'root' => null, 'depth' => $depth, 'tenantId' => $tenant,
            ];
        }

        $org = $this->asNode($orgRows[0]->get('org'));
        $rootKey = $builder->node(
            'Organization',
            $tenant,
            $this->title($org, 'Organization '.$tenant),
            $this->property($org, 'industry'),
            $this->displayProperties($org),
            true,
            null,
            'home',
        );

        if ($rootKey !== null) {
            $types = $include === [] ? $this->allKnownRelationshipTypes() : $this->relationshipTypesFor($include);

            $neighbourRows = $this->run(
                'MATCH (org:Organization {tenantId: $tenant})-[r]->(n) '
                .'WHERE n.tenantId = $tenant AND type(r) IN $types '
                .'RETURN type(r) AS relType, n AS node LIMIT $limit',
                ['tenant' => $tenant, 'types' => $types, 'limit' => self::OVERVIEW_BUDGET],
            );

            foreach ($neighbourRows as $row) {
                $this->attachNeighbour($builder, $tenant, $rootKey, (string) $row->get('relType'), $this->asNode($row->get('node')));
            }
        }

        return $builder->result() + [
            'root'     => ['label' => 'Organization', 'id' => $tenant, 'key' => $rootKey],
            'depth'    => $depth,
            'tenantId' => $tenant,
        ];
    }

    /* ════════════════════════════════════════════════════════════ expand ══ */

    public function expand(string $tenant, string $label, string $id, array $include = [], int $offset = 0): array
    {
        $builder = new GraphBuilder(self::EXPAND_BUDGET);

        if (! GraphVocabulary::isKnownLabel($label)) {
            return $builder->result() + ['error' => 'unknown_label', 'supported' => array_keys(GraphVocabulary::LABEL_FAMILY)];
        }

        $originKey = GraphBuilder::key($label, $id);
        $types = $this->allKnownRelationshipTypes();

        $rows = $this->run(
            "MATCH (n {tenantId: \$tenant, id: \$id}) WHERE \$label IN labels(n) "
            .'MATCH (n)-[r]-(m) WHERE m.tenantId = $tenant AND type(r) IN $types '
            .'RETURN type(r) AS relType, startNode(r) = n AS outgoing, m AS node '
            .'SKIP $offset LIMIT $limit',
            ['tenant' => $tenant, 'id' => $id, 'label' => $label, 'types' => $types, 'offset' => $offset, 'limit' => self::NEIGHBOUR_LIMIT],
        );

        // ensureNode()-equivalent: the origin itself, so edges below have
        // somewhere to attach even when the caller never fetched it first.
        $originRows = $this->run(
            'MATCH (n {tenantId: $tenant, id: $id}) WHERE $label IN labels(n) RETURN n LIMIT 1',
            ['tenant' => $tenant, 'id' => $id, 'label' => $label],
        );

        if ($originRows !== []) {
            $origin = $this->asNode($originRows[0]->get('n'));
            $builder->node($label, $id, $this->title($origin, $label.' '.$id), null, $this->displayProperties($origin));
        }

        foreach ($rows as $row) {
            $relType = (string) $row->get('relType');
            $node = $this->asNode($row->get('node'));
            $outgoing = (bool) $row->get('outgoing');

            $this->attachNeighbour($builder, $tenant, $originKey, $relType, $node, $outgoing);
        }

        return $builder->result() + [
            'origin'   => ['label' => $label, 'id' => $id, 'key' => $originKey],
            'offset'   => $offset,
            'tenantId' => $tenant,
        ];
    }

    /* ════════════════════════════════════════════════════════════ detail ══ */

    public function detail(string $tenant, string $label, string $id): ?array
    {
        if (! GraphVocabulary::isKnownLabel($label)) {
            return null;
        }

        $rows = $this->run(
            'MATCH (n {tenantId: $tenant, id: $id}) WHERE $label IN labels(n) RETURN n LIMIT 1',
            ['tenant' => $tenant, 'id' => $id, 'label' => $label],
        );

        if ($rows === []) {
            return null;
        }

        $node = $this->asNode($rows[0]->get('n'));
        $builder = new GraphBuilder(4);
        $key = $builder->node($label, $id, $this->title($node, $label.' '.$id), null, $this->displayProperties($node));

        if ($key === null) {
            return null;
        }

        $facts = [];

        foreach ($this->displayProperties($node) as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $facts[] = ['label' => $name, 'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : $value];
        }

        $connectionRows = $this->run(
            'MATCH (n {tenantId: $tenant, id: $id})-[r]-(m) WHERE m.tenantId = $tenant AND type(r) IN $types '
            .'RETURN type(r) AS relType, labels(m) AS nodeLabels, count(*) AS n',
            ['tenant' => $tenant, 'id' => $id, 'types' => $this->allKnownRelationshipTypes()],
        );

        $connections = [];

        foreach ($connectionRows as $row) {
            $count = (int) $row->get('n');

            if ($count <= 0) {
                continue;
            }

            $relType = (string) $row->get('relType');
            [$relLabel, , $provenance] = GraphVocabulary::relationship($relType);
            $nodeLabels = $row->get('nodeLabels');
            $nodeLabel = is_array($nodeLabels) ? ($nodeLabels[0] ?? $relLabel) : (string) $nodeLabels;

            $connections[] = ['label' => (string) $nodeLabel, 'relationship' => $relLabel, 'count' => $count, 'provenance' => $provenance];
        }

        return [
            'node'        => $builder->result()['nodes'][0],
            'facts'       => $facts,
            'connections' => $connections,
            'tenantId'    => $tenant,
        ];
    }

    /* ════════════════════════════════════════════════════════════ search ══ */

    public function search(string $tenant, string $term, array $labels = []): array
    {
        $term = trim($term);

        if ($term === '') {
            return ['query' => '', 'count' => 0, 'results' => []];
        }

        $wantedLabels = $labels === [] ? array_keys(GraphVocabulary::LABEL_FAMILY) : array_values(array_filter(
            $labels,
            fn (string $label): bool => GraphVocabulary::isKnownLabel($label),
        ));

        if ($wantedLabels === []) {
            return ['query' => $term, 'count' => 0, 'results' => []];
        }

        $builder = new GraphBuilder(400);

        $rows = $this->run(
            'MATCH (n {tenantId: $tenant}) '
            .'WHERE any(l IN labels(n) WHERE l IN $labels) '
            .'  AND (toLower(coalesce(n.title, "")) CONTAINS toLower($term) '
            .'       OR toLower(coalesce(n.name, "")) CONTAINS toLower($term)) '
            .'RETURN labels(n) AS nodeLabels, n AS node LIMIT $limit',
            ['tenant' => $tenant, 'labels' => $wantedLabels, 'term' => $term, 'limit' => self::SEARCH_LIMIT],
        );

        foreach ($rows as $row) {
            $node = $this->asNode($row->get('node'));
            $nodeLabels = $row->get('nodeLabels');
            $label = is_array($nodeLabels) ? (string) ($nodeLabels[0] ?? 'Organization') : (string) $nodeLabels;

            if (! GraphVocabulary::isKnownLabel($label)) {
                continue;
            }

            $builder->node($label, (string) $this->property($node, 'id', ''), $this->title($node, $label), null, $this->displayProperties($node));
        }

        $results = array_map(
            fn (array $node): array => ['label' => $node['label'], 'labels' => $node['labels'], 'properties' => $node['properties'] + ['id' => $node['id']]],
            $builder->result()['nodes'],
        );

        return ['query' => $term, 'count' => count($results), 'results' => $results];
    }

    /* ═══════════════════════════════════════════════════════════ summary ══ */

    public function summary(string $tenant): array
    {
        $rows = $this->run(
            'MATCH (n {tenantId: $tenant}) RETURN labels(n)[0] AS label, count(n) AS n',
            ['tenant' => $tenant],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->get('label')] = (int) $row->get('n');
        }

        // Best-effort mapping onto GraphProjection::summary()'s key shape.
        // departmentSource/memberType/records/datasets have no Neo4j
        // equivalent unless a real sync populates one (see class docblock) —
        // reported as null/0 rather than guessed.
        return [
            'departments'      => $counts['Department'] ?? 0,
            'departmentSource' => null,
            'memberType'       => null,
            'people'           => $counts['Person'] ?? 0,
            'students'         => $counts['Student'] ?? 0,
            'records'          => 0,
            'datasets'         => $counts['Dataset'] ?? 0,
            'signals'          => $counts['Signal'] ?? 0,
            'evidence'         => $counts['Evidence'] ?? 0,
            'cases'            => $counts['Case'] ?? 0,
            'recommendations'  => $counts['Recommendation'] ?? 0,
            'decisions'        => $counts['Decision'] ?? 0,
            'capabilities'     => $counts['Capability'] ?? 0,
        ];
    }

    /* ═══════════════════════════════════════════════════ reasoning trail ══ */

    public function reasoningTrail(string $tenant, string $label, string $id): array
    {
        if (GraphVocabulary::family($label) !== GraphVocabulary::FAMILY_INTELLIGENCE) {
            return [
                'error'     => 'unsupported_label',
                'supported' => array_keys(array_filter(
                    GraphVocabulary::LABEL_FAMILY,
                    fn (string $family): bool => $family === GraphVocabulary::FAMILY_INTELLIGENCE,
                )),
            ];
        }

        $builder = new GraphBuilder(self::EXPAND_BUDGET);
        $startRows = $this->run(
            'MATCH (n {tenantId: $tenant, id: $id}) WHERE $label IN labels(n) RETURN n LIMIT 1',
            ['tenant' => $tenant, 'id' => $id, 'label' => $label],
        );

        if ($startRows === []) {
            return $builder->result() + ['origin' => ['label' => $label, 'id' => $id], 'trail' => [], 'tenantId' => $tenant];
        }

        $startNode = $this->asNode($startRows[0]->get('n'));
        $startKey = $builder->node($label, $id, $this->title($startNode, $label.' '.$id), null, $this->displayProperties($startNode));

        if ($startKey === null) {
            return $builder->result() + ['origin' => ['label' => $label, 'id' => $id], 'trail' => [], 'tenantId' => $tenant];
        }

        $types = $this->intelligenceRelationshipTypes();
        $trail = [['step' => 0, 'label' => $label, 'id' => $id, 'relationship' => null]];
        $visited = [$startKey => true];
        $frontier = [['key' => $startKey, 'label' => $label, 'id' => $id]];
        $step = 0;

        while ($frontier !== [] && $step < self::TRAIL_MAX_STEPS && ! $builder->isFull()) {
            $current = array_shift($frontier);
            $step++;

            $rows = $this->run(
                'MATCH (n {tenantId: $tenant, id: $id})-[r]-(m) WHERE m.tenantId = $tenant AND type(r) IN $types '
                .'RETURN type(r) AS relType, startNode(r) = n AS outgoing, m AS node LIMIT $limit',
                ['tenant' => $tenant, 'id' => $current['id'], 'types' => $types, 'limit' => self::NEIGHBOUR_LIMIT],
            );

            foreach ($rows as $row) {
                $neighbourNode = $this->asNode($row->get('node'));
                $neighbourLabels = $this->nodeLabels($row->get('node'));
                $neighbourLabel = $neighbourLabels[0] ?? null;

                if ($neighbourLabel === null || GraphVocabulary::family($neighbourLabel) !== GraphVocabulary::FAMILY_INTELLIGENCE) {
                    continue;
                }

                $neighbourId = (string) $this->property($neighbourNode, 'id', '');
                $neighbourKey = GraphBuilder::key($neighbourLabel, $neighbourId);

                if (isset($visited[$neighbourKey])) {
                    continue;
                }

                $relType = (string) $row->get('relType');
                $outgoing = (bool) $row->get('outgoing');
                $builder->node($neighbourLabel, $neighbourId, $this->title($neighbourNode, $neighbourLabel.' '.$neighbourId), null, $this->displayProperties($neighbourNode));

                if ($outgoing) {
                    $builder->edge($current['key'], $neighbourKey, $relType);
                } else {
                    $builder->edge($neighbourKey, $current['key'], $relType);
                }

                $visited[$neighbourKey] = true;
                $frontier[] = ['key' => $neighbourKey, 'label' => $neighbourLabel, 'id' => $neighbourId];

                $trail[] = [
                    'step'         => $step,
                    'label'        => $neighbourLabel,
                    'id'           => $neighbourId,
                    'relationship' => GraphVocabulary::relationship($relType)[0],
                ];
            }
        }

        return $builder->result() + [
            'origin'   => ['label' => $label, 'id' => $id, 'key' => $startKey],
            'trail'    => $trail,
            'tenantId' => $tenant,
        ];
    }

    /* ─────────────────────────────────────────────────────── the client ── */

    private function client(): ClientInterface
    {
        return $this->injectedClient ?? ($this->builtClient ??= $this->buildClient());
    }

    private function buildClient(): ClientInterface
    {
        $uri = (string) config('graph.neo4j.uri', '');

        if ($uri === '') {
            // Fails closed, loudly, the moment a real query is attempted —
            // never silently returns an empty graph. This installation has
            // no NEO4J_* configured, so this is the expected outcome unless
            // and until that changes (it is not changed by this file).
            throw new RuntimeException(
                'Neo4jGraphAdapter has no graph.neo4j.uri configured. It is selectable '
                .'via graph.driver=neo4j but not provisioned in this installation '
                .'(see config/graph.php) — this adapter exists and is unit-testable '
                .'with a mocked client, but was never meant to reach a live server here.',
            );
        }

        return ClientBuilder::create()
            ->withDriver('neo4j', $uri, Authenticate::basic(
                (string) config('graph.neo4j.username', ''),
                (string) config('graph.neo4j.password', ''),
            ))
            ->build();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<int, CypherMap>
     */
    private function run(string $cypher, array $parameters): array
    {
        return $this->client()->run($cypher, $parameters)->list();
    }

    /* ──────────────────────────────────────────────── vocabulary guards ── */

    /** @return array<int, string> every relationship type this installation declares */
    private function allKnownRelationshipTypes(): array
    {
        return array_keys(GraphVocabulary::RELATIONSHIPS);
    }

    /** @return array<int, string> relationship types in the 'intelligence' family only */
    private function intelligenceRelationshipTypes(): array
    {
        return array_values(array_filter(
            array_keys(GraphVocabulary::RELATIONSHIPS),
            fn (string $type): bool => GraphVocabulary::relationship($type)[1] === GraphVocabulary::FAMILY_INTELLIGENCE,
        ));
    }

    /**
     * Relationship types plausibly relevant to the requested intelligence
     * branches ($include, e.g. 'signals', 'cases') — a best-effort narrowing
     * for overview(), never a source of a type GraphVocabulary doesn't know:
     * every candidate still comes from allKnownRelationshipTypes().
     *
     * @param  array<int, string>  $include
     * @return array<int, string>
     */
    private function relationshipTypesFor(array $include): array
    {
        $structural = ['has_department', 'employs', 'enrolls', 'contains'];
        $wanted = array_merge($structural, $include);

        return array_values(array_filter(
            $this->allKnownRelationshipTypes(),
            fn (string $type): bool => in_array($type, $wanted, true) || str_contains($type, 'contain') || str_contains($type, 'holds'),
        ));
    }

    /* ─────────────────────────────────────────────────────── node mapping ── */

    private function asNode(mixed $value): Node
    {
        if (! $value instanceof Node) {
            throw new InvalidArgumentException('Expected a Neo4j Node record, got '.get_debug_type($value));
        }

        return $value;
    }

    /** @return array<int, string> */
    private function nodeLabels(mixed $value): array
    {
        return $value instanceof Node ? $value->getLabels()->toArray() : [];
    }

    private function property(Node $node, string $key, mixed $default = null): mixed
    {
        $properties = $node->getProperties();

        return $properties->hasKey($key) ? $properties->get($key) : $default;
    }

    private function title(Node $node, string $fallback): string
    {
        $title = $this->property($node, 'title') ?? $this->property($node, 'name');

        return $title === null || $title === '' ? $fallback : (string) $title;
    }

    /**
     * Small and display-safe, same contract GraphBuilder::node() documents
     * for its $properties argument — this is every scalar property the
     * node carries, not a row dump.
     *
     * @return array<string, mixed>
     */
    private function displayProperties(Node $node): array
    {
        $out = [];

        foreach ($node->getProperties()->toArray() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Attach one neighbour row (as returned by overview()/expand()'s Cypher)
     * to the builder, validating the relationship type against
     * GraphVocabulary before it reaches edge() (edge() itself already falls
     * back to a generic 'organizational' family for an unknown type, per
     * GraphVocabulary::relationship() — this guard stops that fallback from
     * ever firing for a type this adapter chose to query).
     */
    private function attachNeighbour(GraphBuilder $builder, string $tenant, ?string $fromKey, string $relType, Node $node, bool $outgoing = true): void
    {
        if (! in_array($relType, $this->allKnownRelationshipTypes(), true)) {
            return;
        }

        $labels = $node->getLabels()->toArray();
        $label = $labels[0] ?? null;

        if ($label === null || ! GraphVocabulary::isKnownLabel($label)) {
            return;
        }

        $id = (string) $this->property($node, 'id', '');
        $key = $builder->node($label, $id, $this->title($node, $label.' '.$id), null, $this->displayProperties($node));

        if ($outgoing) {
            $builder->edge($fromKey, $key, $relType);
        } else {
            $builder->edge($key, $fromKey, $relType);
        }
    }
}
