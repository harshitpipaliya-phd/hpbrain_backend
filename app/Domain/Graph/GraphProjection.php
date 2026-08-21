<?php

declare(strict_types=1);

namespace App\Domain\Graph;

use App\Domain\Industry\Vocabulary;
use App\Domain\Intelligence\OrganizationDataProfiler;
use App\Domain\Organization\FoundationCounts;
use App\Domain\Organization\OrganizationStructureService;
use App\Domain\School\AcademicSections;
use App\Domain\School\DatasetRegistry;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Repositories\OrganizationRepository;
use App\Services\TenantScopedCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * THE QUERY LAYER BEHIND GRAPH EXPLORER. An adapter, not a second architecture.
 *
 * WHAT THIS IS ALLOWED TO DO. Read. Every method here composes services that
 * already own their answers — OrganizationStructureService for what a department
 * is, FoundationCounts for how many people there are, EntityResolver for which
 * table a tenant keeps them in, DatasetRegistry for which import is the academic
 * one — and turns their output into nodes and edges. It computes no intelligence
 * of its own, writes nothing, and does not touch IntelligenceEngine or any
 * analyzer. A number on the graph screen and the same number on the Organization
 * screen come from the same service call, so the two cannot disagree.
 *
 * WHAT IT MUST NEVER DO. Invent a relationship. Every edge it emits is declared
 * in GraphVocabulary with the column that produces it, and that clause travels
 * with the edge to the client. Where two entities are not joined by anything the
 * database records, the graph shows no edge between them, however natural one
 * would look.
 *
 * TENANT SCOPE. Every query filters on the tenant passed in, and the controller
 * only ever passes the tenant resolved from the caller's token. There is no code
 * path here that reads a tenant from a query string, a node id or a group key —
 * a group key naming another organization's department resolves against THIS
 * tenant's rows and finds nothing.
 *
 * SIZE. These organizations hold 7,445 students and 398,831 imported records, so
 * nothing here is sized by the dataset: populations enter the graph as GROUP
 * nodes carrying a COUNT, every member list is a LIMIT, and GraphBuilder refuses
 * to exceed its node budget whatever the queries return. Expansion is what the
 * user asks for, one hop at a time.
 *
 * EMPTINESS IS A FACT, NOT A FAILURE. A branch with no rows is omitted rather
 * than drawn as a zero. An organization with staff and no students shows no
 * student branch at all, because a zero would invite the reader to wonder what
 * went wrong with an import that never existed.
 */
final class GraphProjection
{
    /** Nodes one overview may contain, before the client has expanded anything. */
    private const OVERVIEW_BUDGET = 220;

    /** Nodes one expansion may add. */
    private const EXPAND_BUDGET = 140;

    /** Departments drawn individually before the rest become a "+N more" group. */
    private const DEPARTMENTS_INLINE = 12;

    /** A population at or below this size is drawn as individuals, not a group. */
    private const INLINE_THRESHOLD = 8;

    /** Members returned per group expansion page. */
    private const GROUP_PAGE = 24;

    /** Neighbours of one kind returned when expanding an entity. */
    private const NEIGHBOUR_LIMIT = 20;

    /** Members sampled per group when the caller asks for depth 2 or 3. */
    private const DEPTH_SAMPLE = 5;

    /** Rows a single search label may return. */
    private const SEARCH_LIMIT = 12;

    /**
     * Signals scanned when matching one student's enrolment number.
     *
     * The match is on metadata.externalRef inside a JSON column, which no index
     * covers, so this is a scan of the tenant's signals. It is bounded here
     * rather than left to run: 10,400 rows is tens of milliseconds and 10
     * million would not be.
     */
    private const SIGNAL_SCAN_CEILING = 60000;

    /**
     * How long a cached graph may live.
     *
     * Long, because the FINGERPRINT decides whether an entry is still correct,
     * not this number — the same rule IntelligenceEngine states. Its only job is
     * to stop keys for data versions nobody will ask for again from
     * accumulating. Six hours.
     */
    private const CACHE_TTL_SECONDS = 21600;

    /** @var array<string, array<string, int>> tenant => table => count */
    private array $loopCountCache = [];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly OrganizationStructureService $structure,
        private readonly FoundationCounts $foundation,
        private readonly OrganizationRepository $organizations,
        private readonly DatasetRegistry $datasets,
        private readonly AcademicSections $sections,
        private readonly Vocabulary $vocabulary,
        private readonly TenantScopedCache $cache,
        private readonly OrganizationDataProfiler $profiler,
    ) {
    }

    /* ═════════════════════════════════════════════════════════════ cache ══ */

    /*
      WHY THESE READS ARE CACHED, AND WHY ON A FINGERPRINT.

      The graph is composed of a dozen or so separate reads — the structure
      service, the foundation counts, the loop-table union, the dataset list,
      then a query per branch. Against this deployment's remote database a bare
      round trip costs on the order of a second, so the OVERVIEW IS DOMINATED BY
      QUERY COUNT rather than by any query being slow: measured cold, depth 2 on
      the Lions tenant took 27 seconds to produce 76 nodes, of which almost all
      was latency.

      A TTL cache would fix the speed and break the product: the whole premise
      here is that importing data changes what the screens say next, and a graph
      that keeps showing yesterday's branches for an hour after an import is
      worse than a slow one. So the key carries
      OrganizationDataProfiler::dataVersion() — the same fingerprint of row
      counts and high-water timestamps that IntelligenceEngine and the school
      caches are keyed on. Any write to the tenant's data changes the key and the
      next read recomputes; every read in between is free, and an import
      invalidates the graph, the intelligence and the academic structure
      together.

      THE KEY CONTAINS THE TENANT, and TenantScopedCache tracks it per tenant, so
      no two organizations can collide on an entry and forgetting one tenant
      cannot serve or discard another's.
    */

    /**
     * Remember a graph read against this tenant's data fingerprint.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $compute
     * @return TValue
     */
    private function remember(string $tenant, string $key, \Closure $compute): mixed
    {
        try {
            $version = $this->profiler->dataVersion($tenant);
        } catch (Throwable) {
            // No fingerprint, no cache. Recomputing every time is slow; serving
            // an entry whose freshness cannot be established is wrong.
            return $compute();
        }

        return $this->cache->remember(
            $tenant,
            'hpbrain:graph:v1:'.$tenant.':'.$version.':'.$key,
            self::CACHE_TTL_SECONDS,
            $compute,
        );
    }

    /* ══════════════════════════════════════════════════════════ overview ══ */

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
    public function overview(string $tenant, int $depth = 1, array $include = []): array
    {
        $depth = max(1, min(3, $depth));
        $branches = $include;
        sort($branches);

        return $this->remember(
            $tenant,
            'overview:'.$depth.':'.implode(',', $branches),
            fn (): array => $this->buildOverview($tenant, $depth, $include),
        );
    }

    /** @return array<string, mixed> */
    private function buildOverview(string $tenant, int $depth, array $include): array
    {
        $builder = new GraphBuilder(self::OVERVIEW_BUDGET);

        $organization = $this->organizationRow($tenant);
        $summary = $this->summary($tenant);

        $rootKey = $builder->node(
            'Organization',
            $tenant,
            $organization['name'] ?? ('Organization '.$tenant),
            $organization['industry'] ?? null,
            [
                'tenantId'    => $tenant,
                'orgCode'     => $organization['orgCode'] ?? null,
                'industry'    => $organization['industry'] ?? null,
                'legalName'   => $organization['legalName'] ?? null,
                'status'      => $organization['status'] ?? 'active',
                'sourceTable' => $organization['sourceTable'] ?? null,
            ],
            true,
            null,
            'home',
        );

        $frontier = $this->attachOrganizationBranches($builder, $tenant, $rootKey, $include, $summary);

        // Depth 2 and 3 sample a handful of members from each branch so the
        // reader can see the shape — organization, department, staff, students —
        // without clicking. A sample, and it says so in `truncated`.
        for ($level = 2; $level <= $depth; $level++) {
            $next = [];

            foreach ($frontier as $node) {
                if ($builder->isFull()) {
                    break;
                }

                $next = array_merge($next, $this->sampleFrom($builder, $tenant, $node, $include));
            }

            $frontier = $next;
        }

        return $builder->result() + [
            'root'      => ['label' => 'Organization', 'id' => $tenant, 'key' => $rootKey],
            'depth'     => $depth,
            'summary'   => $summary,
            'available' => $this->availability($tenant, $summary),
            'labels'    => $this->activeLabels($tenant, $summary),
            'tenantId'  => $tenant,
        ];
    }

    /**
     * The branches an organization actually has, each omitted when empty.
     *
     * @param  array<int, string>  $include
     * @param  array<string, mixed>  $summary
     * @return array<int, array{key: string, label: string, id: string}>  the frontier for deeper levels
     */
    private function attachOrganizationBranches(
        GraphBuilder $builder,
        string $tenant,
        ?string $rootKey,
        array $include,
        array $summary,
    ): array {
        if ($rootKey === null) {
            return [];
        }

        $frontier = [];
        $structure = $this->structure->forTenant($tenant);
        $departments = $structure['departments'];

        /* ── Departments (HR units, or the teaching sections derived from the
              students where the source system records no units). ───────────── */
        if ($departments !== []) {
            $inline = array_slice($departments, 0, self::DEPARTMENTS_INLINE);

            foreach ($inline as $department) {
                $key = $this->departmentNode($builder, $tenant, $department);
                $builder->edge($rootKey, $key, 'has_department');

                if ($key !== null) {
                    $frontier[] = ['key' => $key, 'label' => 'Department', 'id' => (string) $department['id']];
                }
            }

            $remaining = count($departments) - count($inline);

            if ($remaining > 0) {
                $key = $builder->group(
                    $this->groupId('department', 'Organization', $tenant),
                    'Department',
                    $remaining.' more '.$this->vocabulary->words($tenant, 'OrganizationUnit'),
                    $remaining,
                    'Not drawn — expand to load',
                    ['offset' => count($inline)],
                );
                $builder->edge($rootKey, $key, 'contains');
            }

            $builder->truncate(
                'Department',
                count($inline),
                count($departments),
                'The organization has '.count($departments).' '.$this->vocabulary->words($tenant, 'OrganizationUnit')
                .'; the first '.count($inline).' are drawn and the rest are behind a group node.',
            );
        }

        /* ── Staff. Never merged with students: they are different populations
              and FoundationCounts documents why. ──────────────────────────── */
        $staff = (int) ($summary['people'] ?? 0);

        if ($staff > 0) {
            $key = $this->populationBranch(
                $builder,
                $tenant,
                $rootKey,
                'Person',
                $staff,
                ucfirst($this->vocabulary->words($tenant, 'Person')),
                'employs',
                fn (int $limit) => $this->staffNodes($builder, $tenant, $limit, 0),
            );

            if ($key !== null) {
                $frontier[] = $key;
            }
        }

        /* ── Students. Always a group: the smallest school here has thousands. */
        $students = (int) ($summary['students'] ?? 0);

        if ($students > 0) {
            $key = $builder->group(
                $this->groupId('student', 'Organization', $tenant),
                'Student',
                'Students',
                $students,
                'One row per enrolment number',
            );
            $builder->edge($rootKey, $key, 'enrolls');

            if ($key !== null) {
                $frontier[] = ['key' => $key, 'label' => 'Group', 'id' => $this->groupId('student', 'Organization', $tenant)];
            }
        }

        /* ── Imported datasets. Few, real, and the origin of every signal. ─── */
        foreach ($this->datasetRows($tenant) as $dataset) {
            $key = $builder->node(
                'Dataset',
                (string) $dataset['key'],
                (string) $dataset['name'],
                $dataset['role'] === null ? 'Imported dataset' : ucfirst((string) $dataset['role']).' dataset',
                [
                    'records'    => $dataset['records'],
                    'role'       => $dataset['role'],
                    'sourceType' => $dataset['sourceType'],
                    'lastSynced' => $dataset['lastSynced'],
                ],
                true,
                (int) $dataset['records'],
                'ingestion',
            );
            $builder->edge($rootKey, $key, 'contains');

            if ($key !== null) {
                $frontier[] = ['key' => $key, 'label' => 'Dataset', 'id' => (string) $dataset['key']];
            }
        }

        /* ── The intelligence loop. Each branch only where rows exist, and only
              where the caller asked for it. ────────────────────────────────── */
        $loop = [
            'signals'         => ['Signal', 'Signals', 'contains'],
            'evidence'        => ['Evidence', 'Evidence', 'contains'],
            'cases'           => ['Case', 'Cases', 'contains'],
            'recommendations' => ['Recommendation', 'Recommendations', 'contains'],
            'decisions'       => ['Decision', 'Decisions', 'contains'],
            'capabilities'    => ['Capability', 'Capabilities', 'holds_capability'],
        ];

        foreach ($loop as $branch => [$label, $title, $type]) {
            if (! $this->wants($include, $branch)) {
                continue;
            }

            $count = (int) ($summary[$branch] ?? 0);

            if ($count <= 0) {
                continue;
            }

            $key = $this->populationBranch(
                $builder,
                $tenant,
                $rootKey,
                $label,
                $count,
                $title,
                $type,
                fn (int $limit) => $this->loopNodes($builder, $tenant, $label, $limit, 0),
            );

            if ($key !== null) {
                $frontier[] = $key;
            }
        }

        return $frontier;
    }

    /**
     * A population hangs off the organization either as individuals (when there
     * are few enough to read) or as one group node carrying its count.
     *
     * @param  callable(int): array<int, array{key: string, label: string, id: string}>  $inline
     * @return array{key: string, label: string, id: string}|null
     */
    private function populationBranch(
        GraphBuilder $builder,
        string $tenant,
        string $rootKey,
        string $label,
        int $count,
        string $title,
        string $edgeType,
        callable $inline,
    ): ?array {
        if ($count <= self::INLINE_THRESHOLD) {
            $added = $inline($count);

            foreach ($added as $node) {
                $builder->edge($rootKey, $node['key'], $edgeType);
            }

            return null;
        }

        $groupId = $this->groupId(strtolower($label), 'Organization', $tenant);
        $key = $builder->group($groupId, $label, $title, $count);
        $builder->edge($rootKey, $key, $edgeType);

        return $key === null ? null : ['key' => $key, 'label' => 'Group', 'id' => $groupId];
    }

    /**
     * A few members of one frontier node, for depth 2 and 3.
     *
     * Deliberately small. The purpose is to show the reader that the branch has
     * members and what they look like, not to load the branch.
     *
     * @param  array{key: string, label: string, id: string}  $node
     * @param  array<int, string>  $include
     * @return array<int, array{key: string, label: string, id: string}>
     */
    private function sampleFrom(GraphBuilder $builder, string $tenant, array $node, array $include): array
    {
        $expansion = $node['label'] === 'Group'
            ? $this->expandGroup($builder, $tenant, $node['id'], 0, self::DEPTH_SAMPLE)
            : $this->expandEntity($builder, $tenant, $node['label'], $node['id'], $include, self::DEPTH_SAMPLE);

        return $expansion;
    }

    /* ════════════════════════════════════════════════════════════ expand ══ */

    /**
     * One hop from one node. This is what clicking "expand" runs.
     *
     * @param  array<int, string>  $include
     * @return array<string, mixed>
     */
    public function expand(string $tenant, string $label, string $id, array $include = [], int $offset = 0): array
    {
        // Cached on the same fingerprint as the overview. Collapsing and
        // re-expanding a branch is the single most common thing a reader does on
        // this screen, and without this each round trip pays for the queries
        // again.
        return $this->remember(
            $tenant,
            'expand:'.$label.':'.$id.':'.$offset,
            fn (): array => $this->buildExpansion($tenant, $label, $id, $include, $offset),
        );
    }

    /** @return array<string, mixed> */
    private function buildExpansion(string $tenant, string $label, string $id, array $include, int $offset): array
    {
        $builder = new GraphBuilder(self::EXPAND_BUDGET);

        if ($label === 'Group') {
            $this->expandGroup($builder, $tenant, $id, $offset, self::GROUP_PAGE);
        } else {
            if (! GraphVocabulary::isKnownLabel($label)) {
                return $builder->result() + ['error' => 'unknown_label', 'supported' => array_keys(GraphVocabulary::LABEL_FAMILY)];
            }

            $this->expandEntity($builder, $tenant, $label, $id, $include, self::NEIGHBOUR_LIMIT);
        }

        return $builder->result() + [
            'origin'   => ['label' => $label, 'id' => $id, 'key' => GraphBuilder::key($label, $id)],
            'offset'   => $offset,
            'tenantId' => $tenant,
        ];
    }

    /**
     * Members of a group node, one page at a time.
     *
     * The group id carries the member label and the scope it belongs to
     * (`student@Organization:1000010`). The scope is re-read from THIS tenant's
     * rows, so a hand-edited group id naming another organization's department
     * matches nothing rather than leaking.
     *
     * @return array<int, array{key: string, label: string, id: string}>
     */
    private function expandGroup(GraphBuilder $builder, string $tenant, string $groupId, int $offset, int $limit): array
    {
        [$member, $scopeLabel, $scopeId] = $this->parseGroupId($groupId);

        if ($member === null) {
            return [];
        }

        /*
          A PLACEHOLDER FOR THE GROUP ITSELF, so the edges below have something
          to hang from inside THIS response. It carries `placeholder => true`
          because the client already holds the real group node with its real
          count, and a merge that overwrote it with this one would replace a
          population of 7,445 with a zero.
        */
        $groupKey = $builder->group($groupId, ucfirst($member), ucfirst($member), 0, null, ['placeholder' => true]);
        $edgeType = 'contains';
        $added = null;

        // Group expansions, by what the group holds and what it hangs from.
        if ($member === 'person' && $scopeLabel === 'Organization') {
            $added = $this->staffNodes($builder, $tenant, $limit, $offset);
            $edgeType = 'employs';
        } elseif ($member === 'person' && $scopeLabel === 'Department') {
            $added = $this->staffNodes($builder, $tenant, $limit, $offset, $scopeId);
            $edgeType = 'works_in';
        } elseif ($member === 'student' && $scopeLabel === 'Organization') {
            $added = $this->studentNodes($builder, $tenant, $limit, $offset);
            $edgeType = 'enrolls';
        } elseif ($member === 'student' && $scopeLabel === 'Department') {
            $added = $this->studentNodes($builder, $tenant, $limit, $offset, $scopeId);
            $edgeType = 'enrolled_in';
        } elseif ($member === 'student' && $scopeLabel === 'Standard') {
            $added = $this->studentNodes($builder, $tenant, $limit, $offset, null, $scopeId);
            $edgeType = 'in_standard';
        } elseif ($member === 'department' && $scopeLabel === 'Organization') {
            $added = [];
            $departments = array_slice($this->structure->forTenant($tenant)['departments'], self::DEPARTMENTS_INLINE + $offset, $limit);

            foreach ($departments as $department) {
                $key = $this->departmentNode($builder, $tenant, $department);

                if ($key !== null) {
                    $added[] = ['key' => $key, 'label' => 'Department', 'id' => (string) $department['id']];
                }
            }
        } elseif ($member === 'evidence' && $scopeLabel === 'Signal') {
            $added = $this->evidenceNodes($builder, $tenant, $scopeId, $limit, $offset);
            $edgeType = 'supported_by';
        } elseif (in_array($member, ['signal', 'case', 'recommendation', 'decision', 'capability', 'evidence'], true) && $scopeLabel === 'Organization') {
            $added = $this->loopNodes($builder, $tenant, ucfirst($member) === 'Case' ? 'Case' : ucfirst($member), $limit, $offset);
            $edgeType = $member === 'capability' ? 'holds_capability' : 'contains';
        } elseif ($member === 'signal' && $scopeLabel === 'Dataset') {
            $added = $this->signalNodesForDataset($builder, $tenant, $scopeId, $limit, $offset);
            $edgeType = 'generated';
        } elseif ($member === 'signal' && $scopeLabel === 'Student') {
            $added = $this->signalNodesForStudent($builder, $tenant, $scopeId, $limit, $offset);
            $edgeType = 'raised_signal';
        }

        if ($added === null) {
            return [];
        }

        foreach ($added as $node) {
            $builder->edge($groupKey, $node['key'], $edgeType);
        }

        return $added;
    }

    /**
     * One hop from a real row.
     *
     * @param  array<int, string>  $include
     * @return array<int, array{key: string, label: string, id: string}>
     */
    private function expandEntity(
        GraphBuilder $builder,
        string $tenant,
        string $label,
        string $id,
        array $include,
        int $limit,
    ): array {
        $selfKey = $this->ensureNode($builder, $tenant, $label, $id);

        if ($selfKey === null) {
            return [];
        }

        return match ($label) {
            'Organization'   => $this->attachOrganizationBranches($builder, $tenant, $selfKey, $include, $this->summary($tenant)),
            'Department'     => $this->expandDepartment($builder, $tenant, $selfKey, $id, $limit),
            'Person'         => $this->expandPerson($builder, $tenant, $selfKey, $id, $limit),
            'Student'        => $this->expandStudent($builder, $tenant, $selfKey, $id, $limit),
            'Standard'       => $this->expandStandard($builder, $tenant, $selfKey, $id, $limit),
            'Subject'        => $this->expandSubject($builder, $tenant, $selfKey, $id, $limit),
            'Dataset'        => $this->expandDataset($builder, $tenant, $selfKey, $id, $limit),
            'Signal'         => $this->expandSignal($builder, $tenant, $selfKey, $id, $limit),
            'Case'           => $this->expandCase($builder, $tenant, $selfKey, $id, $limit),
            'Recommendation' => $this->expandRecommendation($builder, $tenant, $selfKey, $id, $limit),
            'Capability'     => $this->expandCapability($builder, $tenant, $selfKey, $id, $limit),
            'Evidence'       => $this->expandEvidence($builder, $tenant, $selfKey, $id),
            'Decision'       => $this->expandDecision($builder, $tenant, $selfKey, $id),
            'Hypothesis'     => $this->expandHypothesis($builder, $tenant, $selfKey, $id),
            default          => [],
        };
    }

    /* ─────────────────────────── per-label expansion ─────────────────────── */

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandDepartment(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $department = $this->department($tenant, $id);

        if ($department === null) {
            return [];
        }

        $out = [];
        $members = (int) ($department['members'] ?? 0);

        // A department's members are STAFF when the units came from the HR
        // system and STUDENTS when they were derived from the students'
        // standards. They are never relabelled — OrganizationStructureService
        // publishes memberType precisely so this cannot be got wrong.
        if ($department['memberType'] === 'staff') {
            $nodes = $this->staffNodes($builder, $tenant, $limit, 0, $id);

            foreach ($nodes as $node) {
                $builder->edge($selfKey, $node['key'], 'works_in');
            }

            $out = array_merge($out, $nodes);
            $builder->truncate('Person', count($nodes), $members, 'Showing the first '.count($nodes).' of '.$members.' staff in this unit.');

            if ($members > count($nodes)) {
                $key = $builder->group($this->groupId('person', 'Department', $id), 'Person', ($members - count($nodes)).' more staff', $members - count($nodes), null, ['offset' => count($nodes)]);
                $builder->edge($selfKey, $key, 'contains');
            }
        } elseif ($department['memberType'] === 'students') {
            $nodes = $this->studentNodes($builder, $tenant, $limit, 0, $id);

            foreach ($nodes as $node) {
                $builder->edge($selfKey, $node['key'], 'enrolled_in');
            }

            $out = array_merge($out, $nodes);
            $builder->truncate('Student', count($nodes), $members, 'Showing the first '.count($nodes).' of '.$members.' students in this section.');

            if ($members > count($nodes)) {
                $key = $builder->group($this->groupId('student', 'Department', $id), 'Student', ($members - count($nodes)).' more students', $members - count($nodes), null, ['offset' => count($nodes)]);
                $builder->edge($selfKey, $key, 'contains');
            }
        }

        foreach ($this->capabilityNodesFor($builder, $tenant, 'Department', $id, $limit) as $node) {
            $builder->edge($selfKey, $node['key'], 'has_capability');
            $out[] = $node;
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandPerson(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $out = [];
        $person = $this->person($tenant, $id);

        if ($person === null) {
            return [];
        }

        if (($person['departmentId'] ?? null) !== null) {
            $department = $this->department($tenant, (string) $person['departmentId']);

            if ($department !== null) {
                $key = $this->departmentNode($builder, $tenant, $department);
                $builder->edge($selfKey, $key, 'works_in');

                if ($key !== null) {
                    $out[] = ['key' => $key, 'label' => 'Department', 'id' => (string) $department['id']];
                }
            }
        }

        foreach ($this->capabilityNodesFor($builder, $tenant, 'Person', $id, $limit) as $node) {
            $builder->edge($selfKey, $node['key'], 'has_capability');
            $out[] = $node;
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandStudent(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $student = $this->student($tenant, $id);

        if ($student === null) {
            return [];
        }

        $out = [];
        $ref = (string) $student['student_ref'];

        /* The section this student's standard places them in — the same grade
           definition the Departments screen counts its section cards with. */
        $standard = $student['academic_standard'] ?: $student['standard'];

        if ($standard !== null && $standard !== '') {
            $key = $builder->node(
                'Standard',
                (string) $standard,
                (string) $standard,
                'Standard',
                ['standard' => (string) $standard],
            );
            $builder->edge($selfKey, $key, 'in_standard');

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Standard', 'id' => (string) $standard];
            }
        }

        /* Subjects this student has results in. Covered by
           (tenant_id, dataset, category) plus (tenant_id, dataset, subject_ref). */
        $academic = $this->datasets->academic($tenant);

        if ($academic !== null) {
            $rows = DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenant)
                ->where('dataset', $academic)
                ->where('subject_ref', $ref)
                ->whereNotNull('category')
                ->selectRaw('category, COUNT(*) AS records, AVG(metric_value) AS mean_score')
                ->groupBy('category')
                ->orderByDesc('records')
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                $key = $builder->node(
                    'Subject',
                    (string) $row->category,
                    (string) $row->category,
                    'Subject',
                    ['dataset' => $academic],
                    true,
                    (int) $row->records,
                );
                $builder->edge(
                    $selfKey,
                    $key,
                    'has_result',
                    (int) $row->records.' recorded '.((int) $row->records === 1 ? 'assessment' : 'assessments')
                    .($row->mean_score === null ? '' : ', mean mark '.round((float) $row->mean_score, 1)),
                );

                if ($key !== null) {
                    $out[] = ['key' => $key, 'label' => 'Subject', 'id' => (string) $row->category];
                }
            }
        }

        /* The fee register, where the tenant has one. A receipt is not an
           entity, so what is drawn is the dataset the receipts live in and how
           many of them carry this student's number. */
        $fees = $this->datasets->fees($tenant);

        if ($fees !== null && (int) ($student['fee_records'] ?? 0) > 0) {
            $key = $this->datasetNode($builder, $tenant, $fees);
            $builder->edge(
                $selfKey,
                $key,
                'recorded_in',
                (int) $student['fee_records'].' fee '.((int) $student['fee_records'] === 1 ? 'record' : 'records')
                .($student['total_paid'] === null ? '' : ' totalling '.round((float) $student['total_paid'], 2)),
            );

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Dataset', 'id' => $fees];
            }
        }

        /* Signals raised against this student's enrolment number. */
        $signals = $this->signalNodesForStudent($builder, $tenant, $ref, $limit, 0);

        foreach ($signals as $node) {
            $builder->edge($selfKey, $node['key'], 'raised_signal');
        }

        return array_merge($out, $signals);
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandStandard(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $nodes = $this->studentNodes($builder, $tenant, $limit, 0, null, $id);

        foreach ($nodes as $node) {
            $builder->edge($selfKey, $node['key'], 'in_standard');
        }

        $total = (int) DB::table('hpbrain_students')
            ->where('tenant_id', $tenant)
            ->where(function ($w) use ($id): void {
                $w->where('academic_standard', $id)->orWhere('standard', $id);
            })
            ->count();

        $builder->truncate('Student', count($nodes), $total, 'Showing '.count($nodes).' of '.$total.' students recorded in this standard.');

        if ($total > count($nodes)) {
            $key = $builder->group($this->groupId('student', 'Standard', $id), 'Student', ($total - count($nodes)).' more students', $total - count($nodes), null, ['offset' => count($nodes)]);
            $builder->edge($selfKey, $key, 'contains');
        }

        return $nodes;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandSubject(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $academic = $this->datasets->academic($tenant);

        if ($academic === null) {
            return [];
        }

        $key = $this->datasetNode($builder, $tenant, $academic);
        $builder->edge($key, $selfKey, 'covers');

        // Students examined in this subject. A semi-join over the projection
        // rather than a DISTINCT over the record table, for the reason
        // AcademicRecordRepository::structure() spells out: subject_ref is not
        // in the composite indexes, and COUNT(DISTINCT subject_ref) turns an
        // index read into a scan of every row the tenant holds.
        $rows = DB::table('hpbrain_students')
            ->where('tenant_id', $tenant)
            ->whereExists(function ($sub) use ($tenant, $academic, $id): void {
                $sub->selectRaw('1')
                    ->from('hpbrain_operational_records as r')
                    ->whereColumn('r.subject_ref', 'hpbrain_students.student_ref')
                    ->where('r.tenant_id', $tenant)
                    ->where('r.dataset', $academic)
                    ->where('r.category', $id);
            })
            ->orderBy('student_name')
            ->limit($limit)
            ->get(['id', 'student_ref', 'student_name', 'standard', 'academic_standard', 'avg_percentage', 'academic_records', 'fee_records', 'total_paid', 'in_academic', 'in_fees']);

        $out = [];

        foreach ($rows as $row) {
            $node = $this->studentNodeFromRow($builder, (array) $row);

            if ($node !== null) {
                $builder->edge($selfKey, $node['key'], 'has_result');
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandDataset(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $out = [];

        /*
          DIMENSIONS ARE DRAWN ONLY FOR THE ACADEMIC DATASET, and that
          restriction is the whole point of this block rather than a limitation
          of it.

          `category` and `status` on hpbrain_operational_records are generic
          columns whose MEANING is set by the import that filled them. For a
          school's results export they hold the subject examined and the standard
          it was recorded against, which is what AcademicRecordRepository::
          structure() reads them as. For the telecommunications tenant's
          complaint export the same two columns hold a fault type and a ticket
          state — so emitting them unconditionally labelled a fault called
          "No Connectivity in Broadband" as a Subject and a ticket state of
          "closed" as a Standard. Both nodes were then unresolvable, because
          ensureNode() correctly looks a Subject up in the academic dataset and
          found nothing.

          There is no honest node label for "whatever dimension this particular
          import happened to use", so no node is emitted for one. A non-academic
          dataset shows the signals it generated and nothing else, which is
          fewer connections and every one of them true.
        */
        $dimensions = $this->datasets->academic($tenant) === $id
            ? [['category', 'Subject'], ['status', 'Standard']]
            : [];

        foreach ($dimensions as [$column, $label]) {
            $rows = DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenant)
                ->where('dataset', $id)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->selectRaw($column.' AS value, COUNT(*) AS records')
                ->groupBy($column)
                ->orderByDesc('records')
                ->limit((int) ceil($limit / 2))
                ->get();

            foreach ($rows as $row) {
                $key = $builder->node(
                    $label,
                    (string) $row->value,
                    (string) $row->value,
                    $label,
                    ['dataset' => $id],
                    true,
                    (int) $row->records,
                );
                $builder->edge($selfKey, $key, 'covers', (int) $row->records.' rows');

                if ($key !== null) {
                    $out[] = ['key' => $key, 'label' => $label, 'id' => (string) $row->value];
                }
            }
        }

        $signals = $this->signalNodesForDataset($builder, $tenant, $id, $limit, 0);

        foreach ($signals as $node) {
            $builder->edge($selfKey, $node['key'], 'generated');
        }

        $total = (int) DB::table('hpbrain_signals')
            ->where('tenant_id', $tenant)
            ->whereIn('source', $this->datasetSignalSources($id))
            ->count();
        $builder->truncate('Signal', count($signals), $total, 'This dataset produced '.$total.' signals; '.count($signals).' are drawn.');

        if ($total > count($signals)) {
            $key = $builder->group($this->groupId('signal', 'Dataset', $id), 'Signal', ($total - count($signals)).' more signals', $total - count($signals), null, ['offset' => count($signals)]);
            $builder->edge($selfKey, $key, 'contains');
        }

        return array_merge($out, $signals);
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandSignal(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $out = [];

        /* Evidence — the rows that support this signal. */
        $evidence = $this->evidenceNodes($builder, $tenant, $id, $limit, 0);

        foreach ($evidence as $node) {
            $builder->edge($selfKey, $node['key'], 'supported_by');
        }

        $evidenceTotal = (int) DB::table('hpbrain_evidence')->where('tenant_id', $tenant)->where('signal_id', $id)->count();
        $builder->truncate('Evidence', count($evidence), $evidenceTotal, $evidenceTotal.' pieces of evidence support this signal; '.count($evidence).' are drawn.');

        if ($evidenceTotal > count($evidence)) {
            $key = $builder->group($this->groupId('evidence', 'Signal', $id), 'Evidence', ($evidenceTotal - count($evidence)).' more', $evidenceTotal - count($evidence), null, ['offset' => count($evidence)]);
            $builder->edge($selfKey, $key, 'contains');
        }

        $out = array_merge($out, $evidence);

        /* Cases opened from it. */
        foreach (DB::table('hpbrain_cases')->where('tenant_id', $tenant)->where('signal_id', $id)->limit($limit)->get() as $row) {
            $key = $builder->node('Case', (string) $row->id, (string) $row->title, 'Case · '.$row->status, ['status' => $row->status, 'createdDate' => $row->created_date], true, null, 'deliberation');
            $builder->edge($selfKey, $key, 'opened_case');

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Case', 'id' => (string) $row->id];
            }
        }

        /* Recommendations this signal reasoned its way to. The join runs
           through hpbrain_reasoning_steps, and the step's own description is
           carried onto the edge — that sentence is the explanation of WHY the
           recommendation follows from the signal, and it already exists. */
        foreach ($this->recommendationsFromReasoning($tenant, 'signal_id', $id, $limit) as $row) {
            $key = $this->recommendationNode($builder, (array) $row);
            $builder->edge($selfKey, $key, 'led_to', $row->reasoning === null ? null : (string) $row->reasoning);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Recommendation', 'id' => (string) $row->id];
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandCase(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $out = [];
        $case = DB::table('hpbrain_cases')->where('tenant_id', $tenant)->where('id', $id)->first();

        if ($case === null) {
            return [];
        }

        if ($case->signal_id !== null) {
            $key = $this->ensureNode($builder, $tenant, 'Signal', (string) $case->signal_id);
            $builder->edge($key, $selfKey, 'opened_case');

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Signal', 'id' => (string) $case->signal_id];
            }
        }

        foreach (DB::table('hpbrain_hypotheses')->where('tenant_id', $tenant)->where('case_id', $id)->limit($limit)->get() as $row) {
            $key = $builder->node(
                'Hypothesis',
                (string) $row->id,
                (string) $row->statement,
                'Hypothesis · '.$row->status,
                ['status' => $row->status, 'confidence' => $row->confidence, 'rootCauseFamily' => $row->root_cause_family],
                true,
                null,
                'deliberation',
            );
            $builder->edge($selfKey, $key, 'has_hypothesis');

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Hypothesis', 'id' => (string) $row->id];
            }
        }

        foreach ($this->recommendationsFromReasoning($tenant, 'case_id', $id, $limit) as $row) {
            $key = $this->recommendationNode($builder, (array) $row);
            $builder->edge($selfKey, $key, 'led_to', $row->reasoning === null ? null : (string) $row->reasoning);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Recommendation', 'id' => (string) $row->id];
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandRecommendation(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $out = [];

        /* Back to what produced it. */
        $origin = DB::table('hpbrain_recommendations as r')
            ->leftJoin('hpbrain_reasoning_steps as s', function ($join) use ($tenant): void {
                $join->on('s.id', '=', 'r.reasoning_step_id')->where('s.tenant_id', '=', $tenant);
            })
            ->where('r.tenant_id', $tenant)
            ->where('r.id', $id)
            ->first(['s.signal_id', 's.case_id', 's.description as reasoning']);

        if ($origin !== null && $origin->signal_id !== null) {
            $key = $this->ensureNode($builder, $tenant, 'Signal', (string) $origin->signal_id);
            $builder->edge($key, $selfKey, 'led_to', $origin->reasoning === null ? null : (string) $origin->reasoning);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Signal', 'id' => (string) $origin->signal_id];
            }
        }

        if ($origin !== null && $origin->case_id !== null) {
            $key = $this->ensureNode($builder, $tenant, 'Case', (string) $origin->case_id);
            $builder->edge($key, $selfKey, 'led_to', $origin->reasoning === null ? null : (string) $origin->reasoning);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Case', 'id' => (string) $origin->case_id];
            }
        }

        /* Forward to what was decided. */
        foreach (DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->where('recommendation_id', $id)->limit($limit)->get() as $row) {
            $key = $builder->node(
                'Decision',
                (string) $row->id,
                $this->truncateText((string) ($row->rationale ?: 'Decision'), 90),
                'Decision · '.$row->status,
                ['status' => $row->status, 'executorType' => $row->executor_type, 'decidedBy' => $row->decided_by, 'createdDate' => $row->created_date],
                true,
                null,
                'decisionintel',
            );
            $builder->edge($selfKey, $key, 'decided_by');

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Decision', 'id' => (string) $row->id];
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandCapability(GraphBuilder $builder, string $tenant, string $selfKey, string $id, int $limit): array
    {
        $out = [];

        $rows = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenant)
            ->where('capability_id', $id)
            ->limit($limit)
            ->get(['target_type', 'target_id', 'status']);

        foreach ($rows as $row) {
            $label = (string) $row->target_type === 'Person' ? 'Person' : 'Department';
            $key = $this->ensureNode($builder, $tenant, $label, (string) $row->target_id);
            $builder->edge($key, $selfKey, 'has_capability', 'Assignment status: '.$row->status);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => $label, 'id' => (string) $row->target_id];
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandEvidence(GraphBuilder $builder, string $tenant, string $selfKey, string $id): array
    {
        $signalId = DB::table('hpbrain_evidence')->where('tenant_id', $tenant)->where('id', $id)->value('signal_id');

        if ($signalId === null) {
            return [];
        }

        $key = $this->ensureNode($builder, $tenant, 'Signal', (string) $signalId);
        $builder->edge($key, $selfKey, 'supported_by');

        return $key === null ? [] : [['key' => $key, 'label' => 'Signal', 'id' => (string) $signalId]];
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandDecision(GraphBuilder $builder, string $tenant, string $selfKey, string $id): array
    {
        $recommendationId = DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->where('id', $id)->value('recommendation_id');

        if ($recommendationId === null) {
            return [];
        }

        $key = $this->ensureNode($builder, $tenant, 'Recommendation', (string) $recommendationId);
        $builder->edge($key, $selfKey, 'decided_by');

        return $key === null ? [] : [['key' => $key, 'label' => 'Recommendation', 'id' => (string) $recommendationId]];
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function expandHypothesis(GraphBuilder $builder, string $tenant, string $selfKey, string $id): array
    {
        $caseId = DB::table('hpbrain_hypotheses')->where('tenant_id', $tenant)->where('id', $id)->value('case_id');

        if ($caseId === null) {
            return [];
        }

        $key = $this->ensureNode($builder, $tenant, 'Case', (string) $caseId);
        $builder->edge($key, $selfKey, 'has_hypothesis');

        return $key === null ? [] : [['key' => $key, 'label' => 'Case', 'id' => (string) $caseId]];
    }

    /* ════════════════════════════════════════════════════════════ detail ══ */

    /**
     * What the right-hand panel shows: this row's own fields, its counts, and
     * the connections it has.
     *
     * Every field is a stored column or an aggregate over rows the entity is
     * genuinely attached to. There is no placeholder and no derived score.
     * A section with nothing behind it is absent, not zero.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $tenant, string $label, string $id): ?array
    {
        if (! GraphVocabulary::isKnownLabel($label)) {
            return null;
        }

        return $this->remember($tenant, 'detail:'.$label.':'.$id, fn (): ?array => $this->buildDetail($tenant, $label, $id));
    }

    /** @return array<string, mixed>|null */
    private function buildDetail(string $tenant, string $label, string $id): ?array
    {

        $builder = new GraphBuilder(4);
        $key = $this->ensureNode($builder, $tenant, $label, $id);

        if ($key === null) {
            return null;
        }

        $node = $builder->result()['nodes'][0] ?? null;

        if ($node === null) {
            return null;
        }

        return [
            'node'        => $node,
            'facts'       => $this->facts($tenant, $label, $id),
            'connections' => $this->connectionCounts($tenant, $label, $id),
            'tenantId'    => $tenant,
        ];
    }

    /**
     * Named fields for the panel, in reading order.
     *
     * @return array<int, array{label: string, value: string|int|float|null, hint?: string}>
     */
    private function facts(string $tenant, string $label, string $id): array
    {
        $facts = [];
        $push = function (string $name, mixed $value, ?string $hint = null) use (&$facts): void {
            if ($value === null || $value === '') {
                return;
            }

            $facts[] = ['label' => $name, 'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : $value, 'hint' => $hint];
        };

        switch ($label) {
            case 'Organization':
                $organization = $this->organizationRow($tenant);
                $summary = $this->summary($tenant);
                $structure = $this->structure->forTenant($tenant);

                $push('Name', $organization['name'] ?? null);
                $push('Tenant ID', $tenant);
                $push('Organization code', $organization['orgCode'] ?? null);
                $push('Industry', $organization['industry'] ?? null);
                $push('Legal name', $organization['legalName'] ?? null);
                $push(ucfirst($this->vocabulary->words($tenant, 'OrganizationUnit')), $summary['departments'],
                    $structure['source'] === OrganizationStructureService::SOURCE_ACADEMIC
                        ? 'Teaching sections derived from the standards this school\'s students are recorded in — its source system records no units.'
                        : 'Units this organization\'s source system records.');
                $push(ucfirst($this->vocabulary->words($tenant, 'Person')), $summary['people'], 'Active staff on the mapped person table. Never students.');
                $push('Students', $summary['students'], 'One row per enrolment number in the student projection.');
                $push('Imported records', $summary['records']);
                $push('Signals', $summary['signals']);
                $push('Evidence', $summary['evidence']);
                $push('Recommendations', $summary['recommendations']);
                $push('Capabilities', $summary['capabilities']);
                break;

            case 'Department':
                $department = $this->department($tenant, $id);

                if ($department === null) {
                    break;
                }

                $push('Name', $department['name']);
                $push('Status', ucfirst((string) $department['status']));
                $push('Source', $department['source'] === OrganizationStructureService::SOURCE_ACADEMIC
                    ? 'Derived teaching section'
                    : 'Unit recorded by the source system');
                $push($department['memberType'] === 'students' ? 'Students' : 'Staff', $department['members']);
                $push('Standards', $department['standards'] ?? null);
                $push('Academic records', $department['academicRecords'] ?? null);
                $push('Fee records', $department['feeRecords'] ?? null);
                $push('Fees collected', $department['feesCollected'] ?? null);
                $push('Average percentage', $department['averagePercentage'] ?? null, 'The average of the examined children in this section, not of its grades.');
                break;

            case 'Person':
                $person = $this->person($tenant, $id);

                if ($person === null) {
                    break;
                }

                $push('Name', $person['name']);
                $push('Employee reference', $person['externalRef']);
                $push('Role', $person['role']);
                $push('Email', $person['email']);
                $push('Phone', $person['phone']);
                $push($this->vocabulary->label($tenant, 'OrganizationUnit'), $person['departmentName']);
                $push('Joined', $person['joinedDate']);
                break;

            case 'Student':
                $student = $this->student($tenant, $id);

                if ($student === null) {
                    break;
                }

                $push('Name', $student['student_name']);
                $push('Enrolment number', $student['student_ref']);
                $push('Standard', $student['academic_standard'] ?: $student['standard']);
                $push('Division', $student['division']);
                $push('Batch', $student['batch']);
                $push('Quota', $student['student_quota']);
                $push('Academic records', $student['academic_records']);
                $push('Subjects', $student['subjects_count']);
                $push('Average percentage', $student['avg_percentage']);
                $push('Academic years', $this->joinRange($student['first_academic_year'] ?? null, $student['last_academic_year'] ?? null));
                $push('Fee records', $student['fee_records']);
                $push('Total paid', $student['total_paid']);
                $push('Receipt dates', $this->joinRange($student['first_receipt_date'] ?? null, $student['last_receipt_date'] ?? null));
                break;

            case 'Signal':
                $row = DB::table('hpbrain_signals')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    break;
                }

                $metadata = $this->decodeJson($row->metadata);
                $push('Classification', $row->classification);
                $push('Severity', $row->severity);
                $push('Priority', $row->priority);
                $push('Status', $row->status);
                $push('Confidence', $row->confidence === null ? null : (float) $row->confidence);
                $push('Source', $row->source);
                $push('Title', $metadata['title'] ?? null);
                $push('Owner', $metadata['owner'] ?? null);
                $push('External reference', $metadata['externalRef'] ?? null);
                $push('Raised', $row->created_date);
                $push('Imported from', $metadata['provenance']['sourceRef'] ?? null, 'The file this signal was derived from.');
                break;

            case 'Evidence':
                $row = DB::table('hpbrain_evidence')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    break;
                }

                $content = $this->decodeJson($row->content);
                $provenance = $this->decodeJson($row->provenance);
                $push('Type', $row->evidence_type);
                $push('Observation', $content['text'] ?? null);
                $push('Observed at', $content['observedAt'] ?? $row->observed_date);
                $push('Confidence', $row->confidence === null ? null : (float) $row->confidence);
                $push('Status', $row->status);
                $push('Source', $row->source);
                $push('Sync type', $provenance['syncType'] ?? null);
                $push('Row number', $provenance['rowNumber'] ?? null, 'The row of the imported file this evidence came from.');
                $push('Hash', $row->hash);
                break;

            case 'Case':
                $row = DB::table('hpbrain_cases')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    break;
                }

                $push('Title', $row->title);
                $push('Description', $row->description);
                $push('Status', $row->status);
                $push('Opened', $row->created_date);
                break;

            case 'Hypothesis':
                $row = DB::table('hpbrain_hypotheses')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    break;
                }

                $push('Statement', $row->statement);
                $push('Root cause family', $row->root_cause_family);
                $push('Status', $row->status);
                $push('Confidence', $row->confidence === null ? null : (float) $row->confidence);
                $push('Proposed by', $row->proposed_by);
                break;

            case 'Recommendation':
                $row = DB::table('hpbrain_recommendations')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    break;
                }

                $push('Title', $row->title);
                $push('Description', $row->description);
                $push('Category', $row->category);
                $push('Priority', $row->priority);
                $push('Urgency', $row->urgency);
                $push('Status', $row->status);
                $push('Confidence', $row->confidence === null ? null : (float) $row->confidence);
                $push('Impact', $row->impact);
                $push('Risk', $row->risk);
                break;

            case 'Decision':
                $row = DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    break;
                }

                $push('Rationale', $row->rationale);
                $push('Status', $row->status);
                $push('Executor type', $row->executor_type);
                $push('Decided by', $row->decided_by);
                $push('Confidence', $row->confidence === null ? null : (float) $row->confidence);
                $push('Approved by', $row->approved_by);
                $push('Decided', $row->created_date);
                break;

            case 'Capability':
                $row = DB::table('hpbrain_capabilities')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    break;
                }

                $push('Name', $row->name);
                $push('Description', $row->description);
                $push('Code', $row->capability_code);
                $push('Category', $row->category);
                $push('Type', $row->capability_type);
                $push('Criticality', $row->criticality);
                $push('Difficulty', $row->difficulty);
                $push('Status', $row->status);
                break;

            case 'Dataset':
                $row = DB::table('hpbrain_data_sources')->where('tenant_id', $tenant)->where('source_key', $id)->first();
                $records = (int) DB::table('hpbrain_operational_records')->where('tenant_id', $tenant)->where('dataset', $id)->count();

                $push('Dataset key', $id);
                $push('Display name', $row->display_name ?? null);
                $push('Source type', $row->source_type ?? null);
                $push('Role', $this->datasetRole($tenant, $id));
                $push('Imported records', $records);
                $push('Last synced', $row->last_synced_at ?? null);
                break;

            case 'Standard':
                $students = (int) DB::table('hpbrain_students')
                    ->where('tenant_id', $tenant)
                    ->where(function ($w) use ($id): void {
                        $w->where('academic_standard', $id)->orWhere('standard', $id);
                    })
                    ->count();

                $push('Standard', $id);
                $push('Students', $students, 'Counted once per student from their latest recorded year.');
                break;

            case 'Subject':
                $academic = $this->datasets->academic($tenant);

                if ($academic === null) {
                    break;
                }

                $row = DB::table('hpbrain_operational_records')
                    ->where('tenant_id', $tenant)
                    ->where('dataset', $academic)
                    ->where('category', $id)
                    ->selectRaw('COUNT(*) AS records, AVG(metric_value) AS mean_score')
                    ->first();

                $push('Subject', $id);
                $push('Assessments recorded', (int) ($row->records ?? 0));
                $push('Mean mark', $row->mean_score === null ? null : round((float) $row->mean_score, 2));
                $push('Dataset', $academic);
                break;
        }

        return $facts;
    }

    /**
     * How many of each kind of thing this node connects to, so the panel can
     * offer expansion without loading anything.
     *
     * @return array<int, array{label: string, relationship: string, count: int, provenance: string}>
     */
    private function connectionCounts(string $tenant, string $label, string $id): array
    {
        $out = [];
        $add = function (string $nodeLabel, string $type, int $count) use (&$out): void {
            if ($count <= 0) {
                return;
            }

            [$relLabel, , $provenance] = GraphVocabulary::relationship($type);
            $out[] = ['label' => $nodeLabel, 'relationship' => $relLabel, 'count' => $count, 'provenance' => $provenance];
        };

        switch ($label) {
            case 'Organization':
                $summary = $this->summary($tenant);
                $add('Department', 'has_department', (int) $summary['departments']);
                $add('Person', 'employs', (int) $summary['people']);
                $add('Student', 'enrolls', (int) $summary['students']);
                $add('Signal', 'contains', (int) $summary['signals']);
                $add('Evidence', 'contains', (int) $summary['evidence']);
                $add('Recommendation', 'contains', (int) $summary['recommendations']);
                $add('Capability', 'holds_capability', (int) $summary['capabilities']);
                break;

            case 'Department':
                $department = $this->department($tenant, $id);

                if ($department === null) {
                    break;
                }

                $add($department['memberType'] === 'students' ? 'Student' : 'Person',
                    $department['memberType'] === 'students' ? 'enrolled_in' : 'works_in',
                    (int) $department['members']);
                $add('Capability', 'has_capability', (int) DB::table('hpbrain_capability_assignments')
                    ->where('tenant_id', $tenant)->where('target_type', 'Department')->where('target_id', $id)->count());
                break;

            case 'Person':
                $add('Capability', 'has_capability', (int) DB::table('hpbrain_capability_assignments')
                    ->where('tenant_id', $tenant)->where('target_type', 'Person')->where('target_id', $id)->count());
                break;

            case 'Student':
                $student = $this->student($tenant, $id);

                if ($student === null) {
                    break;
                }

                $add('Subject', 'has_result', (int) ($student['subjects_count'] ?? 0));
                $add('Signal', 'raised_signal', $this->signalCountForStudent($tenant, (string) $student['student_ref']));
                break;

            case 'Signal':
                $add('Evidence', 'supported_by', (int) DB::table('hpbrain_evidence')->where('tenant_id', $tenant)->where('signal_id', $id)->count());
                $add('Case', 'opened_case', (int) DB::table('hpbrain_cases')->where('tenant_id', $tenant)->where('signal_id', $id)->count());
                $add('Recommendation', 'led_to', count($this->recommendationsFromReasoning($tenant, 'signal_id', $id, 200)));
                break;

            case 'Case':
                $add('Hypothesis', 'has_hypothesis', (int) DB::table('hpbrain_hypotheses')->where('tenant_id', $tenant)->where('case_id', $id)->count());
                $add('Recommendation', 'led_to', count($this->recommendationsFromReasoning($tenant, 'case_id', $id, 200)));
                break;

            case 'Recommendation':
                $add('Decision', 'decided_by', (int) DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->where('recommendation_id', $id)->count());
                break;

            case 'Capability':
                $add('Person', 'has_capability', (int) DB::table('hpbrain_capability_assignments')
                    ->where('tenant_id', $tenant)->where('capability_id', $id)->where('target_type', 'Person')->count());
                $add('Department', 'has_capability', (int) DB::table('hpbrain_capability_assignments')
                    ->where('tenant_id', $tenant)->where('capability_id', $id)->where('target_type', 'Department')->count());
                break;

            case 'Dataset':
                $add('Signal', 'generated', (int) DB::table('hpbrain_signals')
                    ->where('tenant_id', $tenant)
                    ->whereIn('source', $this->datasetSignalSources($id))
                    ->count());
                break;
        }

        return $out;
    }

    /* ════════════════════════════════════════════════════════════ search ══ */

    /**
     * Entity search across every label the graph can draw.
     *
     * The envelope is the one the existing endpoint already publishes —
     * `{query, count, results}` with each result carrying `labels` — because
     * GraphExplorer and GlobalSearch are both written against it.
     *
     * @param  array<int, string>  $labels  restrict to these, empty for all
     * @return array<string, mixed>
     */
    public function search(string $tenant, string $term, array $labels = []): array
    {
        $term = trim($term);

        if ($term === '') {
            return ['query' => '', 'count' => 0, 'results' => []];
        }

        $wanted = fn (string $label): bool => $labels === [] || in_array($label, $labels, true);
        $like = '%'.addcslashes($term, '%_\\').'%';
        // Insertion order is the result order, so the organization and its
        // departments come before ten thousand fee signals. Keys only here; the
        // nodes themselves are read out of the builder once at the end.
        $order = [];
        $builder = new GraphBuilder(400);

        $emit = function (?string $key) use (&$order): void {
            if ($key !== null) {
                $order[$key] = true;
            }
        };

        /* Organization — one row, matched on its own name. */
        if ($wanted('Organization')) {
            $organization = $this->organizationRow($tenant);

            if ($organization !== [] && stripos((string) ($organization['name'] ?? ''), $term) !== false) {
                $emit($builder->node('Organization', $tenant, (string) $organization['name'], $organization['industry'] ?? null, [], true, null, 'home'));
            }
        }

        /* Departments — from the structure service, so search and graph agree
           about what a department is. */
        if ($wanted('Department')) {
            $matched = 0;

            foreach ($this->structure->forTenant($tenant)['departments'] as $department) {
                if ($matched >= self::SEARCH_LIMIT) {
                    break;
                }

                if (stripos((string) $department['name'], $term) === false) {
                    continue;
                }

                $matched++;
                $emit($this->departmentNode($builder, $tenant, $department));
            }
        }

        if ($wanted('Person')) {
            foreach ($this->personRows($tenant, $like, self::SEARCH_LIMIT) as $row) {
                $emit($this->personNode($builder, $tenant, $row));
            }
        }

        if ($wanted('Student') && $this->hasStudents($tenant)) {
            $rows = DB::table('hpbrain_students')
                ->where('tenant_id', $tenant)
                ->where(function ($w) use ($term, $like): void {
                    $w->where('student_ref', 'like', addcslashes($term, '%_\\').'%')
                        ->orWhere('student_name', 'like', $like)
                        ->orWhere('unique_id', 'like', addcslashes($term, '%_\\').'%');
                })
                ->orderBy('student_name')
                ->limit(self::SEARCH_LIMIT)
                ->get(['id', 'student_ref', 'student_name', 'standard', 'academic_standard', 'avg_percentage', 'academic_records', 'fee_records', 'total_paid', 'in_academic', 'in_fees', 'subjects_count', 'division']);

            foreach ($rows as $row) {
                $node = $this->studentNodeFromRow($builder, (array) $row);
                $emit($node['key'] ?? null);
            }
        }

        /*
          The loop tables, each searched on the column that carries its text.

          `default` says whether the label is searched when the caller names no
          labels at all. Evidence and Decision are off by default and searchable
          on request, for a reason about ANSWER QUALITY rather than cost: the
          only indexed text on hpbrain_evidence is `source`, which holds the
          dataset key and is therefore identical across every one of a tenant's
          10,430 rows. Searching it matches either all of them or none, and when
          it matches it fills the result list with twelve indistinguishable rows
          and pushes the department, the student and the signal the user was
          looking for off the end of it. Decision's `rationale` is prose that
          restates its recommendation, so it duplicates a better result.

          Each is skipped outright when the tenant has no rows in it — the count
          is already in hand from the memoised union, and on this deployment a
          query that cannot match anything still costs a round trip.
        */
        $loop = [
            'Signal'         => ['hpbrain_signals', 'classification', true],
            'Case'           => ['hpbrain_cases', 'title', true],
            'Hypothesis'     => ['hpbrain_hypotheses', 'statement', true],
            'Recommendation' => ['hpbrain_recommendations', 'title', true],
            'Capability'     => ['hpbrain_capabilities', 'name', true],
            'Evidence'       => ['hpbrain_evidence', 'source', false],
            'Decision'       => ['hpbrain_decisions', 'rationale', false],
        ];

        $counts = $this->loopCounts($tenant);

        foreach ($loop as $label => [$table, $column, $byDefault]) {
            $asked = in_array($label, $labels, true);

            if (! $wanted($label) || (! $byDefault && ! $asked)) {
                continue;
            }

            if (($counts[$table] ?? 0) === 0) {
                continue;
            }

            $ids = DB::table($table)
                ->where('tenant_id', $tenant)
                ->where($column, 'like', $like)
                ->limit(self::SEARCH_LIMIT)
                ->pluck('id');

            foreach ($ids as $rowId) {
                $emit($this->ensureNode($builder, $tenant, $label, (string) $rowId));
            }
        }

        if ($wanted('Dataset')) {
            foreach ($this->datasetRows($tenant) as $dataset) {
                if (stripos((string) $dataset['name'], $term) === false && stripos((string) $dataset['key'], $term) === false) {
                    continue;
                }

                $emit($this->datasetNode($builder, $tenant, (string) $dataset['key']));
            }
        }

        $byKey = [];

        foreach ($builder->result()['nodes'] as $node) {
            $byKey[$node['key']] = $node;
        }

        $out = [];

        foreach (array_keys($order) as $key) {
            if (! isset($byKey[$key])) {
                continue;
            }

            $node = $byKey[$key];

            // The graph node shape already carries labels, title and family.
            // `properties.id` and `properties.name` are filled in so the two
            // existing consumers — which read labels[0] and properties.id —
            // keep working against this response unchanged.
            $node['properties'] = ($node['properties'] ?? []) + ['id' => $node['id'], 'name' => $node['title']];
            $out[] = $node;
        }

        return [
            'query'   => $term,
            'count'   => count($out),
            'results' => $out,
        ];
    }

    /* ═══════════════════════════════════════════════════════════ summary ══ */

    /**
     * The metric strip above the graph. Every figure is a service call or a
     * COUNT over this tenant's own rows; none is computed here twice.
     *
     * @return array<string, int|string|null>
     */
    public function summary(string $tenant): array
    {
        return $this->remember($tenant, 'summary', fn (): array => $this->buildSummary($tenant));
    }

    /** @return array<string, int|string|null> */
    private function buildSummary(string $tenant): array
    {
        $foundation = $this->foundation->forTenant($tenant);
        $loop = $this->loopCounts($tenant);
        $structure = $this->structure->forTenant($tenant);

        return [
            'departments'      => (int) $structure['active'],
            'departmentSource' => (string) $structure['source'],
            'memberType'       => (string) $structure['memberType'],
            'people'           => (int) $foundation['people']['total'],
            'students'         => (int) $foundation['students']['total'],
            'records'          => (int) $foundation['records']['total'],
            'datasets'         => count($this->datasetRows($tenant)),
            'signals'          => $loop['hpbrain_signals'] ?? 0,
            'evidence'         => $loop['hpbrain_evidence'] ?? 0,
            'cases'            => $loop['hpbrain_cases'] ?? 0,
            'recommendations'  => $loop['hpbrain_recommendations'] ?? 0,
            'decisions'        => $loop['hpbrain_decisions'] ?? 0,
            'capabilities'     => $loop['hpbrain_capabilities'] ?? 0,
        ];
    }

    /**
     * Which branches exist for this organization at all.
     *
     * The screen reads this to decide which filter chips to OFFER. A chip for a
     * population the organization does not have is a promise the graph cannot
     * keep, and clicking it would produce an empty canvas with no explanation.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, bool>
     */
    private function availability(string $tenant, array $summary): array
    {
        return [
            'departments'     => (int) $summary['departments'] > 0,
            'people'          => (int) $summary['people'] > 0,
            'students'        => (int) $summary['students'] > 0,
            'academic'        => $this->datasets->academic($tenant) !== null,
            'fees'            => $this->datasets->fees($tenant) !== null,
            'datasets'        => (int) $summary['datasets'] > 0,
            'signals'         => (int) $summary['signals'] > 0,
            'evidence'        => (int) $summary['evidence'] > 0,
            'cases'           => (int) $summary['cases'] > 0,
            'recommendations' => (int) $summary['recommendations'] > 0,
            'decisions'       => (int) $summary['decisions'] > 0,
            'capabilities'    => (int) $summary['capabilities'] > 0,
        ];
    }

    /**
     * Labels this organization can actually produce, for the search filter.
     *
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function activeLabels(string $tenant, array $summary): array
    {
        $labels = ['Organization'];

        $when = [
            'Department'     => (int) $summary['departments'] > 0,
            'Person'         => (int) $summary['people'] > 0,
            'Student'        => (int) $summary['students'] > 0,
            'Standard'       => (int) $summary['students'] > 0,
            'Subject'        => $this->datasets->academic($tenant) !== null,
            'Dataset'        => (int) $summary['datasets'] > 0,
            'Signal'         => (int) $summary['signals'] > 0,
            'Evidence'       => (int) $summary['evidence'] > 0,
            'Case'           => (int) $summary['cases'] > 0,
            'Recommendation' => (int) $summary['recommendations'] > 0,
            'Decision'       => (int) $summary['decisions'] > 0,
            'Capability'     => (int) $summary['capabilities'] > 0,
        ];

        foreach ($when as $label => $present) {
            if ($present) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /* ══════════════════════════════════════════════════════ node factories ══ */

    /**
     * Put a node for one real row into the builder, whatever label it carries.
     *
     * Returns null when the row does not exist FOR THIS TENANT — which is also
     * what makes a hand-edited node id harmless.
     */
    private function ensureNode(GraphBuilder $builder, string $tenant, string $label, string $id): ?string
    {
        $key = GraphBuilder::key($label, $id);

        if ($builder->has($key)) {
            return $key;
        }

        switch ($label) {
            case 'Organization':
                if ($id !== $tenant) {
                    return null;
                }

                $organization = $this->organizationRow($tenant);

                return $builder->node('Organization', $tenant, (string) ($organization['name'] ?? 'Organization '.$tenant), $organization['industry'] ?? null, [
                    'tenantId'  => $tenant,
                    'orgCode'   => $organization['orgCode'] ?? null,
                    'industry'  => $organization['industry'] ?? null,
                    'legalName' => $organization['legalName'] ?? null,
                ], true, null, 'home');

            case 'Department':
                $department = $this->department($tenant, $id);

                return $department === null ? null : $this->departmentNode($builder, $tenant, $department);

            case 'Person':
                $person = $this->person($tenant, $id);

                return $person === null ? null : $builder->node(
                    'Person',
                    $id,
                    (string) ($person['name'] ?: 'Person '.$id),
                    $person['role'] ?? $person['departmentName'] ?? null,
                    [
                        'externalRef'    => $person['externalRef'],
                        'email'          => $person['email'],
                        'departmentId'   => $person['departmentId'],
                        'departmentName' => $person['departmentName'],
                        'role'           => $person['role'],
                    ],
                    true,
                    null,
                    'people',
                );

            case 'Student':
                $student = $this->student($tenant, $id);

                if ($student === null) {
                    return null;
                }

                $node = $this->studentNodeFromRow($builder, $student);

                return $node['key'] ?? null;

            case 'Signal':
                $row = DB::table('hpbrain_signals')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    return null;
                }

                $metadata = $this->decodeJson($row->metadata);

                return $builder->node(
                    'Signal',
                    $id,
                    (string) ($metadata['title'] ?? $row->classification ?? 'Signal'),
                    'Signal · '.$row->classification,
                    [
                        'classification' => $row->classification,
                        'severity'       => $row->severity,
                        'priority'       => $row->priority,
                        'status'         => $row->status,
                        'confidence'     => $row->confidence === null ? null : (float) $row->confidence,
                        'source'         => $row->source,
                        'externalRef'    => $metadata['externalRef'] ?? null,
                        'createdDate'    => $row->created_date,
                    ],
                    true,
                    null,
                    'signals',
                );

            case 'Evidence':
                $row = DB::table('hpbrain_evidence')->where('tenant_id', $tenant)->where('id', $id)->first();

                if ($row === null) {
                    return null;
                }

                $content = $this->decodeJson($row->content);

                return $builder->node(
                    'Evidence',
                    $id,
                    $this->truncateText((string) ($content['text'] ?? $row->evidence_type ?? 'Evidence'), 70),
                    'Evidence · '.$row->evidence_type,
                    [
                        'evidenceType' => $row->evidence_type,
                        'confidence'   => $row->confidence === null ? null : (float) $row->confidence,
                        'status'       => $row->status,
                        'source'       => $row->source,
                        'observedAt'   => $content['observedAt'] ?? $row->observed_date,
                    ],
                    true,
                    null,
                    'evidence',
                );

            case 'Case':
                $row = DB::table('hpbrain_cases')->where('tenant_id', $tenant)->where('id', $id)->first();

                return $row === null ? null : $builder->node('Case', $id, (string) $row->title, 'Case · '.$row->status, [
                    'status'      => $row->status,
                    'description' => $row->description,
                    'createdDate' => $row->created_date,
                ], true, null, 'deliberation');

            case 'Hypothesis':
                $row = DB::table('hpbrain_hypotheses')->where('tenant_id', $tenant)->where('id', $id)->first();

                return $row === null ? null : $builder->node('Hypothesis', $id, $this->truncateText((string) $row->statement, 80), 'Hypothesis · '.$row->status, [
                    'status'     => $row->status,
                    'confidence' => $row->confidence === null ? null : (float) $row->confidence,
                ], true, null, 'deliberation');

            case 'Recommendation':
                $row = DB::table('hpbrain_recommendations')->where('tenant_id', $tenant)->where('id', $id)->first();

                return $row === null ? null : $this->recommendationNode($builder, (array) $row);

            case 'Decision':
                $row = DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->where('id', $id)->first();

                return $row === null ? null : $builder->node('Decision', $id, $this->truncateText((string) ($row->rationale ?: 'Decision'), 90), 'Decision · '.$row->status, [
                    'status'       => $row->status,
                    'executorType' => $row->executor_type,
                    'decidedBy'    => $row->decided_by,
                ], true, null, 'decisionintel');

            case 'Capability':
                $row = DB::table('hpbrain_capabilities')->where('tenant_id', $tenant)->where('id', $id)->first();

                return $row === null ? null : $builder->node('Capability', $id, (string) $row->name, 'Capability · '.($row->category ?: 'general'), [
                    'category'    => $row->category,
                    'criticality' => $row->criticality,
                    'status'      => $row->status,
                    'code'        => $row->capability_code,
                ], true, null, 'capabilities');

            case 'Dataset':
                return $this->datasetNode($builder, $tenant, $id);

            case 'Standard':
                $exists = DB::table('hpbrain_students')
                    ->where('tenant_id', $tenant)
                    ->where(function ($w) use ($id): void {
                        $w->where('academic_standard', $id)->orWhere('standard', $id);
                    })
                    ->exists();

                return $exists ? $builder->node('Standard', $id, $id, 'Standard') : null;

            case 'Subject':
                $academic = $this->datasets->academic($tenant);

                if ($academic === null) {
                    return null;
                }

                $exists = DB::table('hpbrain_operational_records')
                    ->where('tenant_id', $tenant)->where('dataset', $academic)->where('category', $id)->exists();

                return $exists ? $builder->node('Subject', $id, $id, 'Subject', ['dataset' => $academic]) : null;
        }

        return null;
    }

    /** @param array<string, mixed> $department */
    private function departmentNode(GraphBuilder $builder, string $tenant, array $department): ?string
    {
        $members = (int) ($department['members'] ?? 0);
        $memberType = (string) ($department['memberType'] ?? 'staff');

        return $builder->node(
            'Department',
            (string) $department['id'],
            (string) $department['name'],
            $members > 0 ? $members.' '.($memberType === 'students' ? 'students' : 'staff') : null,
            [
                'status'            => $department['status'] ?? null,
                'source'            => $department['source'] ?? null,
                'memberType'        => $memberType,
                'members'           => $members,
                'standards'         => $department['standards'] ?? null,
                'academicRecords'   => $department['academicRecords'] ?? null,
                'feeRecords'        => $department['feeRecords'] ?? null,
                'averagePercentage' => $department['averagePercentage'] ?? null,
            ],
            true,
            $members,
            'departments',
        );
    }

    /** @param array<string, mixed> $person */
    private function personNode(GraphBuilder $builder, string $tenant, array $person): ?string
    {
        return $builder->node(
            'Person',
            (string) $person['id'],
            (string) ($person['name'] ?: 'Person '.$person['id']),
            $person['role'] ?? $person['departmentName'] ?? null,
            [
                'externalRef'    => $person['externalRef'],
                'email'          => $person['email'],
                'departmentId'   => $person['departmentId'],
                'departmentName' => $person['departmentName'],
                'role'           => $person['role'],
            ],
            true,
            null,
            'people',
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{key: string, label: string, id: string}|null
     */
    private function studentNodeFromRow(GraphBuilder $builder, array $row): ?array
    {
        $standard = $row['academic_standard'] ?: ($row['standard'] ?? null);

        $key = $builder->node(
            'Student',
            (string) $row['id'],
            (string) ($row['student_name'] ?: $row['student_ref']),
            $standard === null || $standard === '' ? 'Student' : 'Standard '.$standard,
            [
                'studentRef'       => $row['student_ref'],
                'standard'         => $standard,
                'division'         => $row['division'] ?? null,
                'averagePercentage' => $row['avg_percentage'] ?? null,
                'academicRecords'  => $row['academic_records'] ?? null,
                'feeRecords'       => $row['fee_records'] ?? null,
                'totalPaid'        => $row['total_paid'] ?? null,
                'inAcademic'       => (bool) ($row['in_academic'] ?? false),
                'inFees'           => (bool) ($row['in_fees'] ?? false),
            ],
            true,
            null,
            'people',
        );

        return $key === null ? null : ['key' => $key, 'label' => 'Student', 'id' => (string) $row['id']];
    }

    /** @param array<string, mixed> $row */
    private function recommendationNode(GraphBuilder $builder, array $row): ?string
    {
        return $builder->node(
            'Recommendation',
            (string) $row['id'],
            (string) $row['title'],
            'Recommendation · '.($row['priority'] ?? 'medium'),
            [
                'category'    => $row['category'] ?? null,
                'priority'    => $row['priority'] ?? null,
                'status'      => $row['status'] ?? null,
                'confidence'  => isset($row['confidence']) ? (float) $row['confidence'] : null,
                'description' => $row['description'] ?? null,
            ],
            true,
            null,
            'workspace',
        );
    }

    private function datasetNode(GraphBuilder $builder, string $tenant, string $key): ?string
    {
        foreach ($this->datasetRows($tenant) as $dataset) {
            if ((string) $dataset['key'] !== $key) {
                continue;
            }

            return $builder->node(
                'Dataset',
                $key,
                (string) $dataset['name'],
                $dataset['role'] === null ? 'Imported dataset' : ucfirst((string) $dataset['role']).' dataset',
                ['records' => $dataset['records'], 'role' => $dataset['role'], 'sourceType' => $dataset['sourceType']],
                true,
                (int) $dataset['records'],
                'ingestion',
            );
        }

        return null;
    }

    /* ══════════════════════════════════════════════════════ row factories ══ */

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function staffNodes(GraphBuilder $builder, string $tenant, int $limit, int $offset, ?string $unitId = null): array
    {
        $out = [];

        foreach ($this->personRows($tenant, null, $limit, $offset, $unitId) as $row) {
            $key = $this->personNode($builder, $tenant, $row);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Person', 'id' => (string) $row['id']];
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function studentNodes(
        GraphBuilder $builder,
        string $tenant,
        int $limit,
        int $offset,
        ?string $sectionKey = null,
        ?string $standard = null,
    ): array {
        if (! $this->hasStudents($tenant)) {
            return [];
        }

        $query = DB::table('hpbrain_students')->where('tenant_id', $tenant);

        if ($sectionKey !== null) {
            $predicate = $this->sections->gradePredicate($sectionKey);

            if ($predicate === null) {
                return [];
            }

            $query->whereRaw($predicate['sql'], $predicate['bindings']);
        }

        if ($standard !== null) {
            $query->where(function ($w) use ($standard): void {
                $w->where('academic_standard', $standard)->orWhere('standard', $standard);
            });
        }

        $rows = $query
            ->orderBy('student_name')
            ->orderBy('student_ref')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->get(['id', 'student_ref', 'student_name', 'standard', 'academic_standard', 'division', 'avg_percentage', 'academic_records', 'fee_records', 'total_paid', 'in_academic', 'in_fees']);

        $out = [];

        foreach ($rows as $row) {
            $node = $this->studentNodeFromRow($builder, (array) $row);

            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function loopNodes(GraphBuilder $builder, string $tenant, string $label, int $limit, int $offset): array
    {
        $table = [
            'Signal'         => 'hpbrain_signals',
            'Evidence'       => 'hpbrain_evidence',
            'Case'           => 'hpbrain_cases',
            'Recommendation' => 'hpbrain_recommendations',
            'Decision'       => 'hpbrain_decisions',
            'Capability'     => 'hpbrain_capabilities',
        ][$label] ?? null;

        if ($table === null) {
            return [];
        }

        $query = DB::table($table)->where('tenant_id', $tenant);

        // Severity first for signals, so a sample of 10,400 shows the ones worth
        // looking at rather than whichever the storage engine hands back first.
        if ($label === 'Signal') {
            $query->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")->orderByDesc('created_date');
        } elseif ($label === 'Recommendation') {
            $query->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")->orderByDesc('created_date');
        } elseif (in_array($label, ['Evidence', 'Case', 'Decision'], true)) {
            $query->orderByDesc('created_date');
        } else {
            $query->orderBy('name');
        }

        try {
            $ids = $query->offset(max(0, $offset))->limit($limit)->pluck('id');
        } catch (Throwable) {
            // FIELD() is MySQL-only; the test connection is SQLite. Ordering is
            // a nicety, the rows are the point.
            $ids = DB::table($table)->where('tenant_id', $tenant)->offset(max(0, $offset))->limit($limit)->pluck('id');
        }

        $out = [];

        foreach ($ids as $rowId) {
            $key = $this->ensureNode($builder, $tenant, $label, (string) $rowId);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => $label, 'id' => (string) $rowId];
            }
        }

        return $out;
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function evidenceNodes(GraphBuilder $builder, string $tenant, string $signalId, int $limit, int $offset): array
    {
        $ids = DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenant)
            ->where('signal_id', $signalId)
            ->orderByDesc('created_date')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->pluck('id');

        $out = [];

        foreach ($ids as $rowId) {
            $key = $this->ensureNode($builder, $tenant, 'Evidence', (string) $rowId);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Evidence', 'id' => (string) $rowId];
            }
        }

        return $out;
    }

    /**
     * The two source spellings a signal can carry for one dataset.
     *
     * The ingestion path writes the DATASET KEY itself — Lions' fee signals
     * carry `lions-fees-data`. The rule engine writes `import.<dataset>`, which
     * is not a guess: every rule in App\Domain\Signals\OperationalSignalRules
     * declares its source that way, which is why the telecommunications tenant's
     * work-order signals say `import.work_order`.
     *
     * Matching only the first meant that tenant's five datasets appeared to have
     * generated nothing at all, while 336 signals derived from them sat in the
     * intelligence branch with no path back to the data they came from — which
     * is precisely the question this screen exists to answer.
     *
     * @return array<int, string>
     */
    private function datasetSignalSources(string $dataset): array
    {
        return [$dataset, 'import.'.$dataset];
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function signalNodesForDataset(GraphBuilder $builder, string $tenant, string $dataset, int $limit, int $offset): array
    {
        $ids = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenant)
            ->whereIn('source', $this->datasetSignalSources($dataset))
            ->orderByDesc('created_date')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->pluck('id');

        $out = [];

        foreach ($ids as $rowId) {
            $key = $this->ensureNode($builder, $tenant, 'Signal', (string) $rowId);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Signal', 'id' => (string) $rowId];
            }
        }

        return $out;
    }

    /**
     * Signals raised against one student's enrolment number.
     *
     * The match is `metadata.externalRef = student_ref`, which is the reference
     * the ingestion pipeline wrote from the source file — the same identifier
     * hpbrain_students is keyed on. No index covers a JSON path, so this is a
     * scan of the tenant's signals and is skipped outright above the ceiling
     * rather than run and hoped for.
     *
     * @return array<int, array{key: string, label: string, id: string}>
     */
    private function signalNodesForStudent(GraphBuilder $builder, string $tenant, string $studentRef, int $limit, int $offset): array
    {
        $query = $this->studentSignalQuery($tenant, $studentRef);

        if ($query === null) {
            return [];
        }

        $ids = $query->orderByDesc('created_date')->offset(max(0, $offset))->limit($limit)->pluck('id');
        $out = [];

        foreach ($ids as $rowId) {
            $key = $this->ensureNode($builder, $tenant, 'Signal', (string) $rowId);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Signal', 'id' => (string) $rowId];
            }
        }

        return $out;
    }

    private function signalCountForStudent(string $tenant, string $studentRef): int
    {
        $query = $this->studentSignalQuery($tenant, $studentRef);

        return $query === null ? 0 : (int) $query->count();
    }

    /** Null when the tenant holds more signals than this is willing to scan. */
    private function studentSignalQuery(string $tenant, string $studentRef): ?Builder
    {
        if ($studentRef === '') {
            return null;
        }

        $total = $this->loopCounts($tenant)['hpbrain_signals'] ?? 0;

        if ($total === 0 || $total > self::SIGNAL_SCAN_CEILING) {
            return null;
        }

        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(metadata, '$.externalRef')"
            : "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.externalRef'))";

        return DB::table('hpbrain_signals')
            ->where('tenant_id', $tenant)
            ->whereRaw($expression.' = ?', [$studentRef]);
    }

    /** @return array<int, array{key: string, label: string, id: string}> */
    private function capabilityNodesFor(GraphBuilder $builder, string $tenant, string $targetType, string $targetId, int $limit): array
    {
        $ids = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenant)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->limit($limit)
            ->pluck('capability_id');

        $out = [];

        foreach ($ids as $capabilityId) {
            $key = $this->ensureNode($builder, $tenant, 'Capability', (string) $capabilityId);

            if ($key !== null) {
                $out[] = ['key' => $key, 'label' => 'Capability', 'id' => (string) $capabilityId];
            }
        }

        return $out;
    }

    /**
     * Recommendations reachable from a signal or a case through the reasoning
     * step that produced them, carrying that step's own description.
     *
     * @return array<int, object>
     */
    private function recommendationsFromReasoning(string $tenant, string $column, string $id, int $limit): array
    {
        return DB::table('hpbrain_recommendations as r')
            ->join('hpbrain_reasoning_steps as s', function ($join) use ($tenant): void {
                $join->on('s.id', '=', 'r.reasoning_step_id')->where('s.tenant_id', '=', $tenant);
            })
            ->where('r.tenant_id', $tenant)
            ->where('s.'.$column, $id)
            ->limit($limit)
            ->get(['r.id', 'r.title', 'r.category', 'r.priority', 'r.status', 'r.confidence', 'r.description', 's.description as reasoning'])
            ->all();
    }

    /* ═══════════════════════════════════════════════════════════ sources ══ */

    /** @return array<string, mixed> */
    private function organizationRow(string $tenant): array
    {
        try {
            $rows = $this->organizations->list($tenant);
        } catch (Throwable) {
            return [];
        }

        $row = $rows[0] ?? null;

        if ($row === null) {
            return [];
        }

        return [
            'name'        => $row['name'] ?? null,
            'orgCode'     => $row['org_code'] ?? $row['orgCode'] ?? null,
            'industry'    => $row['industry'] ?? null,
            'legalName'   => $row['legal_name'] ?? $row['legalName'] ?? null,
            'status'      => 'active',
            'sourceTable' => $this->safeSource($tenant, 'Organization')?->table,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function personRows(string $tenant, ?string $like, int $limit, int $offset = 0, ?string $unitId = null): array
    {
        $person = $this->safeSource($tenant, 'Person');

        if ($person === null) {
            return [];
        }

        $columns = $person->columns(['id', 'firstName', 'lastName', 'email', 'phone', 'externalRef', 'unit', 'profile', 'joinedDate']);

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant);

        if ($person->has('status')) {
            $query->where($person->field('status'), 1);
        }

        if ($person->has('deletedAt')) {
            $query->whereNull($person->field('deletedAt'));
        }

        if ($unitId !== null && $person->has('unit')) {
            $query->where($person->field('unit'), $unitId);
        }

        if ($like !== null) {
            $searchable = $person->columns(['firstName', 'lastName', 'email', 'externalRef']);

            $query->where(function ($w) use ($searchable, $like): void {
                foreach ($searchable as $column) {
                    $w->orWhere($column, 'like', $like);
                }
            });
        }

        $rows = $query
            ->orderBy($person->primaryKey)
            ->offset(max(0, $offset))
            ->limit($limit)
            ->get(array_values($columns));

        $unitNames = $this->unitNames($tenant);
        $roleNames = $this->profileNames($tenant);

        return $rows->map(function ($row) use ($columns, $unitNames, $roleNames): array {
            $value = fn (string $field) => isset($columns[$field]) ? ($row->{$columns[$field]} ?? null) : null;

            $unitId = $value('unit');
            $profileId = $value('profile');
            $name = trim((string) $value('firstName').' '.(string) $value('lastName'));

            return [
                'id'             => (string) $value('id'),
                'name'           => $name,
                'externalRef'    => $this->blankToNull($value('externalRef')),
                'email'          => $this->blankToNull($value('email')),
                'phone'          => $this->blankToNull($value('phone')),
                'joinedDate'     => $this->blankToNull($value('joinedDate')),
                'departmentId'   => $unitId === null || (string) $unitId === '' || (string) $unitId === '0' ? null : (string) $unitId,
                'departmentName' => $unitNames[(string) $unitId] ?? null,
                'role'           => $roleNames[(string) $profileId] ?? null,
            ];
        })->all();
    }

    /** @return array<string, mixed>|null */
    private function person(string $tenant, string $id): ?array
    {
        $person = $this->safeSource($tenant, 'Person');

        if ($person === null) {
            return null;
        }

        // Fetched directly rather than paged to.
        $columns = $person->columns(['id', 'firstName', 'lastName', 'email', 'phone', 'externalRef', 'unit', 'profile', 'joinedDate']);

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->where($person->primaryKey, $id);

        if ($person->has('deletedAt')) {
            $query->whereNull($person->field('deletedAt'));
        }

        $row = $query->first(array_values($columns));

        if ($row === null) {
            return null;
        }

        $unitNames = $this->unitNames($tenant);
        $roleNames = $this->profileNames($tenant);
        $value = fn (string $field) => isset($columns[$field]) ? ($row->{$columns[$field]} ?? null) : null;
        $unitId = $value('unit');
        $profileId = $value('profile');

        return [
            'id'             => (string) $value('id'),
            'name'           => trim((string) $value('firstName').' '.(string) $value('lastName')),
            'externalRef'    => $this->blankToNull($value('externalRef')),
            'email'          => $this->blankToNull($value('email')),
            'phone'          => $this->blankToNull($value('phone')),
            'joinedDate'     => $this->blankToNull($value('joinedDate')),
            'departmentId'   => $unitId === null || (string) $unitId === '' || (string) $unitId === '0' ? null : (string) $unitId,
            'departmentName' => $unitNames[(string) $unitId] ?? null,
            'role'           => $roleNames[(string) $profileId] ?? null,
        ];
    }

    /** @var array<string, array<string, string>> */
    private array $unitNameCache = [];

    /** @return array<string, string> */
    private function unitNames(string $tenant): array
    {
        if (isset($this->unitNameCache[$tenant])) {
            return $this->unitNameCache[$tenant];
        }

        $out = [];

        foreach ($this->structure->forTenant($tenant)['departments'] as $department) {
            $out[(string) $department['id']] = (string) $department['name'];
        }

        return $this->unitNameCache[$tenant] = $out;
    }

    /** @var array<string, array<string, string>> */
    private array $profileNameCache = [];

    /** @return array<string, string> */
    private function profileNames(string $tenant): array
    {
        if (isset($this->profileNameCache[$tenant])) {
            return $this->profileNameCache[$tenant];
        }

        $profile = $this->safeSource($tenant, 'PersonProfile');

        if ($profile === null || ! $profile->has('name')) {
            return $this->profileNameCache[$tenant] = [];
        }

        $out = [];

        try {
            foreach (DB::table($profile->table)->limit(200)->get([$profile->primaryKey, $profile->field('name')]) as $row) {
                $out[(string) $row->{$profile->primaryKey}] = (string) $row->{$profile->field('name')};
            }
        } catch (Throwable) {
            return $this->profileNameCache[$tenant] = [];
        }

        return $this->profileNameCache[$tenant] = $out;
    }

    /** @return array<string, mixed>|null */
    private function department(string $tenant, string $id): ?array
    {
        foreach ($this->structure->forTenant($tenant)['departments'] as $department) {
            if ((string) $department['id'] === $id) {
                return $department;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function student(string $tenant, string $id): ?array
    {
        if (! $this->hasStudents($tenant)) {
            return null;
        }

        $row = DB::table('hpbrain_students')->where('tenant_id', $tenant)->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /** @var array<string, bool> */
    private array $hasStudentsCache = [];

    private function hasStudents(string $tenant): bool
    {
        return $this->hasStudentsCache[$tenant] ??= (
            Schema::hasTable('hpbrain_students')
            && DB::table('hpbrain_students')->where('tenant_id', $tenant)->exists()
        );
    }

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $datasetCache = [];

    /**
     * The imported datasets this tenant holds, from the record table itself so
     * a dataset with rows but no data-source row is still visible.
     *
     * @return array<int, array<string, mixed>>
     */
    private function datasetRows(string $tenant): array
    {
        if (isset($this->datasetCache[$tenant])) {
            return $this->datasetCache[$tenant];
        }

        $counts = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenant)
            ->whereNotNull('dataset')
            ->selectRaw('dataset, COUNT(*) AS records')
            ->groupBy('dataset')
            ->orderByDesc('records')
            ->limit(24)
            ->get();

        $sources = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenant)
            ->get(['source_key', 'display_name', 'source_type', 'last_synced_at'])
            ->keyBy('source_key');

        $out = [];

        foreach ($counts as $row) {
            $key = (string) $row->dataset;
            $source = $sources[$key] ?? null;

            $out[] = [
                'key'        => $key,
                'name'       => (string) ($source->display_name ?? $key),
                'records'    => (int) $row->records,
                'role'       => $this->datasetRole($tenant, $key),
                'sourceType' => $source->source_type ?? null,
                'lastSynced' => $source->last_synced_at ?? null,
            ];
        }

        return $this->datasetCache[$tenant] = $out;
    }

    private function datasetRole(string $tenant, string $key): ?string
    {
        if ($this->datasets->academic($tenant) === $key) {
            return 'academic';
        }

        if ($this->datasets->fees($tenant) === $key) {
            return 'fees';
        }

        return null;
    }

    /**
     * Loop-table counts in one round trip.
     *
     * Seven COUNTs against a remote database is seven round trips; on this
     * deployment that is the dominant cost of the whole response. One UNION ALL
     * is one. Memoised per tenant for the life of the request — bound scoped(),
     * so that is the life of one HTTP call.
     *
     * @return array<string, int>
     */
    private function loopCounts(string $tenant): array
    {
        if (isset($this->loopCountCache[$tenant])) {
            return $this->loopCountCache[$tenant];
        }

        $tables = [
            'hpbrain_signals', 'hpbrain_evidence', 'hpbrain_cases',
            'hpbrain_recommendations', 'hpbrain_decisions', 'hpbrain_capabilities',
        ];

        $parts = [];
        $bindings = [];

        foreach ($tables as $table) {
            $parts[] = 'SELECT ? AS src, COUNT(*) AS n FROM `'.$table.'` WHERE tenant_id = ?';
            $bindings[] = $table;
            $bindings[] = $tenant;
        }

        $out = array_fill_keys($tables, 0);

        try {
            foreach (DB::select(implode(' UNION ALL ', $parts), $bindings) as $row) {
                $out[(string) $row->src] = (int) $row->n;
            }
        } catch (Throwable) {
            // Any engine that will not take the union gets one query per table.
            // Slower, identical output — a graph is not worth failing over a
            // SQL dialect.
            foreach ($tables as $table) {
                try {
                    $out[$table] = (int) DB::table($table)->where('tenant_id', $tenant)->count();
                } catch (Throwable) {
                    $out[$table] = 0;
                }
            }
        }

        return $this->loopCountCache[$tenant] = $out;
    }

    /* ═════════════════════════════════════════════════════════════ utils ══ */

    /**
     * Resolve an entity without throwing.
     *
     * EntityResolver fails closed and that is correct for the screens whose
     * whole purpose is one entity. Here a missing Person mapping means the
     * people branch does not appear, which is the same answer as "this
     * organization has no staff" and strictly better than a 500 that takes the
     * entire graph down with it.
     */
    private function safeSource(string $tenant, string $entity): ?ResolvedSource
    {
        try {
            return $this->resolver->has($tenant, $entity) ? $this->resolver->resolve($tenant, $entity) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int, string> $include */
    private function wants(array $include, string $branch): bool
    {
        return $include === [] || in_array($branch, $include, true);
    }

    private function groupId(string $member, string $scopeLabel, string $scopeId): string
    {
        return $member.'@'.$scopeLabel.':'.$scopeId;
    }

    /** @return array{0: ?string, 1: string, 2: string} */
    private function parseGroupId(string $groupId): array
    {
        if (! str_contains($groupId, '@')) {
            return [null, '', ''];
        }

        [$member, $scope] = explode('@', $groupId, 2);
        $scopeLabel = $scope;
        $scopeId = '';

        if (str_contains($scope, ':')) {
            [$scopeLabel, $scopeId] = explode(':', $scope, 2);
        }

        return [strtolower($member), $scopeLabel, $scopeId];
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function truncateText(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1).'…';
    }

    private function joinRange(mixed $from, mixed $to): ?string
    {
        $from = $this->blankToNull($from);
        $to = $this->blankToNull($to);

        if ($from === null && $to === null) {
            return null;
        }

        if ($from === null || $to === null || (string) $from === (string) $to) {
            return (string) ($from ?? $to);
        }

        return $from.' — '.$to;
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
