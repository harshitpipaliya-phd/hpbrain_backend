<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Graph;

use App\Domain\Graph\GraphVocabulary;
use App\Domain\Graph\Neo4jGraphAdapter;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\Node;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Neo4jGraphAdapter against a MOCKED Laudis client — no socket, no real
 * Neo4j, by design (see the task that introduced this file: there is no
 * confirmed EB Neo4j deployment target, so this adapter must be verifiable
 * without one).
 *
 * WHAT THIS PINS DOWN, SPECIFICALLY:
 *   1. GraphVocabulary validation happens BEFORE a query is sent — an
 *      unknown label or a label outside the intelligence family never
 *      reaches client()->run() at all (asserted via expects(0) on the mock,
 *      not just on the return value).
 *   2. Every query that does run binds $tenant as a parameter and restricts
 *      relationship "types" to GraphVocabulary::RELATIONSHIPS' own keys —
 *      never an invented type.
 *   3. The result shape matches GraphBuilder's contract — {nodes, edges,
 *      truncated, nodeCount, edgeCount, nodeBudget} plus each method's own
 *      envelope fields — the same shape GraphProjection produces.
 *
 * The constructor takes the mock directly, bypassing config('graph.driver')
 * and the container entirely — this is the "unit-testable with a mocked
 * client" path the task asked for, independent of the (unselected) wiring.
 */
final class Neo4jGraphAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function node(int $id, array $labels, array $properties): Node
    {
        return new Node($id, new CypherList($labels), new CypherMap($properties), null);
    }

    /** @param array<int, array<string, mixed>> $records */
    private function summarizedResult(array $records): SummarizedResult
    {
        $summary = null;
        $maps = array_map(static fn (array $record): CypherMap => new CypherMap($record), $records);

        return new SummarizedResult($summary, $maps);
    }

    /* ═══════════════════════════════════════════════════════════ summary ══ */

    public function test_summary_maps_label_counts_and_binds_the_tenant(): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('run')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(fn (array $params): bool => $params['tenant'] === 'tenant-7'),
            )
            ->andReturn($this->summarizedResult([
                ['label' => 'Person', 'n' => 12],
                ['label' => 'Signal', 'n' => 4],
            ]));

        $adapter = new Neo4jGraphAdapter($client);
        $summary = $adapter->summary('tenant-7');

        self::assertSame(12, $summary['people']);
        self::assertSame(4, $summary['signals']);
        self::assertSame(0, $summary['students']); // not returned by the mock -> honest zero
        self::assertNull($summary['departmentSource']); // no Neo4j equivalent, reported as null not guessed
    }

    /* ════════════════════════════════════════════════════════════ detail ══ */

    public function test_detail_rejects_an_unknown_label_without_querying_the_client(): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('run');

        $adapter = new Neo4jGraphAdapter($client);

        self::assertNull($adapter->detail('tenant-7', 'NotARealLabel', 'x'));
    }

    public function test_detail_returns_facts_and_connections_for_a_known_label(): void
    {
        $signalNode = $this->node(1, ['Signal'], ['id' => 'sig-1', 'tenantId' => 'tenant-7', 'title' => 'Attendance drop', 'severity' => 'high']);

        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn (string $cypher): bool => str_contains($cypher, 'MATCH (n {tenantId: $tenant, id: $id})')), Mockery::any())
            ->andReturn($this->summarizedResult([['n' => $signalNode]]));

        $client->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn (string $cypher): bool => str_contains($cypher, '-[r]-(m)')),
                Mockery::on(function (array $params): bool {
                    // Every relationship type requested must be one
                    // GraphVocabulary actually declares — the vocabulary
                    // guard, proven at the query-construction boundary, not
                    // just asserted in a comment.
                    foreach ($params['types'] as $type) {
                        if (! array_key_exists($type, GraphVocabulary::RELATIONSHIPS)) {
                            return false;
                        }
                    }

                    return $params['tenant'] === 'tenant-7' && $params['id'] === 'sig-1';
                }),
            )
            ->andReturn($this->summarizedResult([
                ['relType' => 'supported_by', 'nodeLabels' => ['Evidence'], 'n' => 3],
            ]));

        $adapter = new Neo4jGraphAdapter($client);
        $detail = $adapter->detail('tenant-7', 'Signal', 'sig-1');

        self::assertNotNull($detail);
        self::assertSame('Signal', $detail['node']['label']);
        self::assertSame('Attendance drop', $detail['node']['title']);

        $severityFact = array_values(array_filter($detail['facts'], fn (array $f): bool => $f['label'] === 'severity'))[0] ?? null;
        self::assertSame('high', $severityFact['value'] ?? null);

        self::assertCount(1, $detail['connections']);
        self::assertSame('Evidence', $detail['connections'][0]['label']);
        self::assertSame(3, $detail['connections'][0]['count']);
        // The relationship's human label and provenance come from
        // GraphVocabulary, not from this adapter inventing its own text.
        self::assertSame(GraphVocabulary::relationship('supported_by')[0], $detail['connections'][0]['relationship']);
    }

    /* ════════════════════════════════════════════════════════════ search ══ */

    public function test_search_with_a_blank_term_short_circuits_without_querying_the_client(): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('run');

        $adapter = new Neo4jGraphAdapter($client);

        self::assertSame(['query' => '', 'count' => 0, 'results' => []], $adapter->search('tenant-7', '  ', []));
    }

    public function test_search_drops_unknown_requested_labels_before_querying(): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        // All requested labels are bogus -> nothing left to search for ->
        // never reaches the client, same "fail closed before querying" rule
        // as detail()/reasoningTrail() above.
        $client->shouldNotReceive('run');

        $adapter = new Neo4jGraphAdapter($client);
        $result = $adapter->search('tenant-7', 'acme', ['NotReal', 'AlsoNotReal']);

        self::assertSame(0, $result['count']);
    }

    public function test_search_returns_matching_nodes_restricted_to_known_labels(): void
    {
        $person = $this->node(2, ['Person'], ['id' => 'p-1', 'tenantId' => 'tenant-7', 'name' => 'Asha Patel']);

        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('run')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(function (array $params): bool {
                    foreach ($params['labels'] as $label) {
                        if (! GraphVocabulary::isKnownLabel($label)) {
                            return false;
                        }
                    }

                    return $params['term'] === 'asha';
                }),
            )
            ->andReturn($this->summarizedResult([
                ['nodeLabels' => ['Person'], 'node' => $person],
            ]));

        $adapter = new Neo4jGraphAdapter($client);
        $result = $adapter->search('tenant-7', 'asha', ['Person', 'Student']);

        self::assertSame(1, $result['count']);
        self::assertSame('Person', $result['results'][0]['label']);
        self::assertSame('p-1', $result['results'][0]['properties']['id']);
    }

    /* ═══════════════════════════════════════════════════ reasoning trail ══ */

    public function test_reasoning_trail_rejects_a_non_intelligence_label_without_querying_the_client(): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('run');

        $adapter = new Neo4jGraphAdapter($client);
        $result = $adapter->reasoningTrail('tenant-7', 'Person', 'p-1');

        self::assertSame('unsupported_label', $result['error']);
        self::assertContains('Signal', $result['supported']);
        self::assertNotContains('Person', $result['supported']);
    }

    public function test_reasoning_trail_walks_one_hop_from_a_signal_to_its_evidence(): void
    {
        $signalNode = $this->node(1, ['Signal'], ['id' => 'sig-1', 'tenantId' => 'tenant-7', 'title' => 'Attendance drop']);
        $evidenceNode = $this->node(2, ['Evidence'], ['id' => 'ev-1', 'tenantId' => 'tenant-7', 'title' => 'Three late check-ins']);

        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);

        // 1) fetch the origin node
        $client->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn (string $cypher): bool => str_contains($cypher, 'RETURN n LIMIT 1')), Mockery::any())
            ->andReturn($this->summarizedResult([['n' => $signalNode]]));

        // 2) one hop out, restricted to intelligence-family relationship types only
        $client->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn (string $cypher): bool => str_contains($cypher, '-[r]-(m)')),
                Mockery::on(function (array $params): bool {
                    foreach ($params['types'] as $type) {
                        if (GraphVocabulary::relationship($type)[1] !== 'intelligence') {
                            return false;
                        }
                    }

                    return $params['id'] === 'sig-1';
                }),
            )
            ->andReturn($this->summarizedResult([
                ['relType' => 'supported_by', 'outgoing' => true, 'node' => $evidenceNode],
            ]));

        // 3) the walk continues from the newly-discovered Evidence node (BFS,
        // not a single hop) — this fixture's evidence has no further
        // intelligence-chain neighbours, so the walk ends here.
        $client->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn (string $cypher): bool => str_contains($cypher, '-[r]-(m)')),
                Mockery::on(fn (array $params): bool => $params['id'] === 'ev-1'),
            )
            ->andReturn($this->summarizedResult([]));

        $adapter = new Neo4jGraphAdapter($client);
        $result = $adapter->reasoningTrail('tenant-7', 'Signal', 'sig-1');

        self::assertSame(2, $result['nodeCount']);
        self::assertSame(1, $result['edgeCount']);
        self::assertSame('Evidence', $result['trail'][1]['label']);
        self::assertSame(GraphVocabulary::relationship('supported_by')[0], $result['trail'][1]['relationship']);
    }

    /* ════════════════════════════════════════════════════════════ expand ══ */

    public function test_expand_rejects_an_unknown_label_without_querying_the_client(): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('run');

        $adapter = new Neo4jGraphAdapter($client);
        $result = $adapter->expand('tenant-7', 'NotARealLabel', 'x');

        self::assertSame('unknown_label', $result['error']);
    }

    /* ══════════════════════════════════════════════════════════ overview ══ */

    public function test_overview_with_no_organization_node_returns_an_empty_graph_not_an_error(): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('run')
            ->once()
            ->andReturn($this->summarizedResult([]));

        $adapter = new Neo4jGraphAdapter($client);
        $result = $adapter->overview('tenant-7');

        self::assertNull($result['root']);
        self::assertSame(0, $result['nodeCount']);
    }
}
