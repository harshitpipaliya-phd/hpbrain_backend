<?php

declare(strict_types=1);

namespace App\Domain\Graph;

/**
 * Accumulates nodes and edges, de-duplicates them, and refuses to exceed its
 * node budget.
 *
 * WHY A BUDGET AND NOT A PAGE. The organizations this runs against hold 7,445
 * students and 398,831 imported records. A traversal that "just returns what it
 * finds" produces a hairball the browser cannot lay out and a reader cannot
 * read, and it does so by degrees — the query is correct at every step and the
 * screen is useless at the end. The cap is therefore enforced at the point of
 * insertion rather than trusted to each caller's LIMIT clause.
 *
 * WHEN THE BUDGET BITES, IT IS SAID OUT LOUD. `truncated` records which kind of
 * node was dropped and how many were asked for, and the API publishes it. A
 * graph silently missing half its neighbours reads as a graph with half as many
 * neighbours, which is a false statement about the organization.
 *
 * KEYS ARE `Label:id`. One row is one node however many paths reach it, so a
 * student reached from their section and from a signal is a single node with two
 * edges rather than two nodes.
 */
final class GraphBuilder
{
    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<string, array<string, mixed>> */
    private array $edges = [];

    /** @var array<string, array{kind: string, shown: int, total: int, reason: string}> */
    private array $truncated = [];

    public function __construct(private readonly int $maxNodes = 260)
    {
    }

    public static function key(string $label, string $id): string
    {
        return $label.':'.$id;
    }

    public function has(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    public function isFull(): bool
    {
        return count($this->nodes) >= $this->maxNodes;
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    /**
     * Add one entity node — a real row of a real table.
     *
     * @param  array<string, mixed>  $properties  small and display-safe; this is
     *         not a row dump, and nothing secret belongs in it
     * @return string|null the node key, or null if the budget refused it
     */
    public function node(
        string $label,
        string $id,
        string $title,
        ?string $subtitle = null,
        array $properties = [],
        bool $expandable = true,
        ?int $count = null,
        ?string $deepLink = null,
    ): ?string {
        $key = self::key($label, $id);

        if (isset($this->nodes[$key])) {
            return $key;
        }

        if ($this->isFull()) {
            return null;
        }

        $this->nodes[$key] = [
            'key'        => $key,
            'label'      => $label,
            // The graph contract models a node as carrying a set of labels and
            // the existing client reads node.labels[0]; MySQL gives each row
            // exactly one, but the shape has to hold either way.
            'labels'     => [$label],
            'id'         => $id,
            'title'      => $title,
            'subtitle'   => $subtitle,
            'family'     => GraphVocabulary::family($label),
            'kind'       => 'entity',
            'count'      => $count,
            'expandable' => $expandable,
            'properties' => $properties,
            // Which existing screen owns this entity, so the panel can offer
            // "open the full record" instead of this graph growing its own
            // duplicate detail views.
            'deepLink'   => $deepLink,
        ];

        return $key;
    }

    /**
     * Add a GROUP node: one circle standing for N rows of one label.
     *
     * This is how a population of thousands enters the graph without thousands
     * of circles. It is an aggregate, not a fabrication — `count` is a COUNT
     * over exactly the rows the node expands into, and the client renders it
     * differently from an entity so nobody can mistake one for the other.
     *
     * `groupId` must be stable for a (parent, label) pair or expanding the same
     * group twice produces two nodes.
     */
    public function group(
        string $groupId,
        string $ofLabel,
        string $title,
        int $count,
        ?string $subtitle = null,
        array $properties = [],
    ): ?string {
        $key = self::key('Group', $groupId);

        if (isset($this->nodes[$key])) {
            return $key;
        }

        if ($this->isFull()) {
            return null;
        }

        $this->nodes[$key] = [
            'key'        => $key,
            'label'      => 'Group',
            'labels'     => ['Group', $ofLabel],
            'id'         => $groupId,
            'title'      => $title,
            'subtitle'   => $subtitle,
            'family'     => GraphVocabulary::family($ofLabel),
            'kind'       => 'group',
            'groupOf'    => $ofLabel,
            'count'      => $count,
            'expandable' => $count > 0,
            'properties' => $properties,
            'deepLink'   => null,
        ];

        return $key;
    }

    /**
     * Connect two nodes. Silently ignored if either end was refused by the
     * budget — an edge to a node that is not in the response is a dangling
     * reference the renderer would have to guess about.
     */
    public function edge(?string $from, ?string $to, string $type, ?string $note = null): void
    {
        if ($from === null || $to === null || $from === $to) {
            return;
        }

        if (! isset($this->nodes[$from], $this->nodes[$to])) {
            return;
        }

        $id = $from.'|'.$type.'|'.$to;

        if (isset($this->edges[$id])) {
            return;
        }

        [$label, $family, $provenance] = GraphVocabulary::relationship($type);

        $this->edges[$id] = [
            'id'         => $id,
            'from'       => $from,
            'to'         => $to,
            'type'       => $type,
            'label'      => $label,
            'family'     => $family,
            'provenance' => $provenance,
            // A sentence about THIS edge specifically, where one exists — the
            // reasoning step behind a recommendation, the rule that matched a
            // record to a person. Null rather than a restatement of the type.
            'note'       => $note,
        ];
    }

    /** Record that more of something existed than was returned. */
    public function truncate(string $kind, int $shown, int $total, string $reason): void
    {
        if ($total <= $shown) {
            return;
        }

        $this->truncated[$kind] = [
            'kind'   => $kind,
            'shown'  => $shown,
            'total'  => $total,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>, truncated: array<int, array<string, mixed>>, nodeCount: int, edgeCount: int, nodeBudget: int}
     */
    public function result(): array
    {
        return [
            'nodes'      => array_values($this->nodes),
            'edges'      => array_values($this->edges),
            'truncated'  => array_values($this->truncated),
            'nodeCount'  => count($this->nodes),
            'edgeCount'  => count($this->edges),
            'nodeBudget' => $this->maxNodes,
        ];
    }
}
