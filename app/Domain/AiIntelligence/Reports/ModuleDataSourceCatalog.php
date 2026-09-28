<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Reports;

use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\Organization\DepartmentVisibilityScope;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * The read-only data sources each HP Brain area's AI Stack can draw on.
 *
 * HP Brain's port of G2G's ModuleDataSourceCatalog. One catalogue serves four tabs,
 * as it does there:
 *
 *   Templates      a report layout binds to one source and is filled from its rows
 *   Knowledge Base lists the sources an area's AI reads, each checkable live
 *   Guardrails     shows them as the area's read tools
 *   Automations    a tool agent is allowed a subset of them as its tools
 *
 * Every source reads the SAME tables the area's own screen reads (the repositories /
 * controllers named on each definition), and is hard-filtered to the caller's tenant
 * from AiIntelligenceScope — the tenant is never an argument, and no argument can
 * widen it. Every one is read-only: a source that could write would not be listed.
 *
 * ERP-backed areas (departments, people) go through EntityResolver exactly as
 * DepartmentController / PersonController do, so a tenant mapped to a different ERP
 * table reads its own. People sources return roster fields only — name, department,
 * role, position and record completeness — never contact details.
 *
 * A source whose table is not on this deployment (or whose entity is not mapped for
 * this tenant) reports `available: false` with a reason instead of failing.
 *
 * ROWS COME BACK FLAT: `['rows' => [...scalar-only rows...], 'total' => n,
 * 'truncated' => bool]` — the shape ReportLayoutRenderer and the AI Stack screens read.
 */
final class ModuleDataSourceCatalog
{
    /** Hard ceiling on rows, whatever `limit` asks for. A report is not an export. */
    public const MAX_ROWS = 500;

    public const DEFAULT_LIMIT = 100;

    /** Statuses the signal screens treat as closed (TenantFacts / Workspace / Analytics). */
    private const CLOSED_SIGNAL_STATUSES = ['resolved', 'closed', 'dismissed'];

    /** Statuses the case screens treat as closed (TenantFacts). */
    private const CLOSED_CASE_STATUSES = ['closed', 'resolved', 'archived', 'dismissed'];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly DepartmentVisibilityScope $departments,
    ) {
    }

    /**
     * @return array<int, array{name:string, module:string, label:string, description:string, columns:array<int, array{key:string,label:string}>, arguments:array<int, array{key:string,type:string,description:string,required:bool}>}>
     */
    public function all(): array
    {
        return array_map(fn (array $s) => $this->public($s), $this->definitions());
    }

    /** @return array<int, array<string, mixed>> Sources for one hpbrain_ai_modules key. */
    public function forModule(string $module): array
    {
        return array_values(array_filter($this->all(), fn (array $s) => $s['module'] === $module));
    }

    public function exists(string $name): bool
    {
        return $this->definition($name) !== null;
    }

    /** @return array<string, mixed>|null */
    public function describe(string $name): ?array
    {
        $definition = $this->definition($name);

        return $definition === null ? null : $this->public($definition);
    }

    /** @return array<int, string> The argument keys a source declares. */
    public function argumentKeys(string $name): array
    {
        return array_column($this->describe($name)['arguments'] ?? [], 'key');
    }

    /**
     * Run one source for the caller's tenant.
     *
     * @param  array<string, mixed>  $arguments  Only declared keys are read; anything else is ignored.
     * @return array{rows: array<int, array<string, scalar|null>>, total: int, returned: int, truncated: bool, available: bool, reason: string|null}
     */
    public function run(string $name, AiIntelligenceScope $scope, array $arguments = []): array
    {
        $definition = $this->definition($name);

        if ($definition === null) {
            throw new InvalidArgumentException("There is no data source called {$name}.");
        }

        $arguments = array_intersect_key($arguments, array_flip(array_column($definition['arguments'], 'key')));
        $tenant = $scope->tenantId;

        $reason = $this->unavailable($definition, $tenant);

        if ($reason !== null) {
            return ['rows' => [], 'total' => 0, 'returned' => 0, 'truncated' => false, 'available' => false, 'reason' => $reason];
        }

        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_ROWS, $limit > 0 ? $limit : self::DEFAULT_LIMIT));

        /** @var Builder $query */
        $query = ($definition['query'])($tenant, $arguments);

        // The source's real size, counted in SQL over the same filtered query — not
        // the number of rows this call happened to fetch. `finish` callbacks only
        // reshape rows (never drop them), so the count is the count of the result.
        $total = (int) DB::query()->fromSub((clone $query)->reorder(), 'source_rows')->count();

        $rows = $query->limit($limit)->get()->map(fn ($row) => (array) $row)->all();

        if (isset($definition['finish'])) {
            $rows = ($definition['finish'])($rows, $tenant);
        }

        $rows = array_map(fn (array $row) => $this->flatten($row), $rows);

        return [
            'rows' => $rows,
            // Every matching record, whatever `limit` fetched.
            'total' => max($total, count($rows)),
            // How many of them are in `rows`.
            'returned' => count($rows),
            'truncated' => $total > count($rows),
            'available' => true,
            'reason' => null,
        ];
    }

    /**
     * Whether a source can be read for this tenant right now (its tables exist and
     * its ERP entities are mapped), and why not.
     *
     * @return array{available: bool, reason: string|null}
     */
    public function availability(string $name, string $tenantId): array
    {
        $definition = $this->definition($name);

        if ($definition === null) {
            return ['available' => false, 'reason' => "There is no data source called {$name}."];
        }

        $reason = $this->unavailable($definition, $tenantId);

        return ['available' => $reason === null, 'reason' => $reason];
    }

    /**
     * A source's arguments as a form can render them — the AI Stack profile's shape:
     * {key, type: integer|string|boolean, description, default?, min?, max?, values?}.
     * Keys a source does not declare for an argument are omitted, never null-filled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function argumentSpecs(string $name): array
    {
        $definition = $this->definition($name);

        if ($definition === null) {
            return [];
        }

        $specs = [];

        foreach ($definition['arguments'] as $argument) {
            $spec = [
                'key' => $argument['key'],
                'type' => $argument['type'],
                'description' => $argument['description'],
            ];

            foreach (['default', 'min', 'max', 'values'] as $extra) {
                if (array_key_exists($extra, $argument)) {
                    $spec[$extra] = $argument[$extra];
                }
            }

            $specs[] = $spec;
        }

        return $specs;
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $definition */
    private function public(array $definition): array
    {
        return [
            'name' => $definition['name'],
            'module' => $definition['module'],
            'label' => $definition['label'],
            'description' => $definition['description'],
            'columns' => $definition['columns'],
            // The long-standing {key, type, description, required} shape; the form
            // extras (default / min / max / values) are served by argumentSpecs().
            'arguments' => array_map(fn (array $a) => [
                'key' => $a['key'],
                'type' => $a['type'],
                'description' => $a['description'],
                'required' => $a['required'],
            ], $definition['arguments']),
        ];
    }

    /** @return array<string, mixed>|null */
    private function definition(string $name): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $definition */
    private function unavailable(array $definition, string $tenant): ?string
    {
        foreach ($definition['tables'] ?? [] as $table) {
            if (! $this->hasTable($table)) {
                return "{$table} is not on this deployment, so this source has nothing to read.";
            }
        }

        foreach ($definition['entities'] ?? [] as $entity) {
            try {
                $mapped = $this->resolver->has($tenant, $entity);
            } catch (Throwable) {
                $mapped = false;
            }

            if (! $mapped) {
                return "This organisation's {$entity} records are not mapped to a source table, so this source has nothing to read.";
            }
        }

        return null;
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row @return array<string, scalar|null> */
    private function flatten(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) || $value === null ? $value : json_encode($value);
        }

        return $out;
    }

    /** @param array<string, mixed> $a */
    private function text(array $a, string $key): ?string
    {
        $value = trim((string) (is_scalar($a[$key] ?? null) ? $a[$key] : ''));

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $extra `default`, `min`, `max`, `values` — the form hints argumentSpecs() serves. */
    private static function arg(string $key, string $type, string $description, bool $required = false, array $extra = []): array
    {
        return ['key' => $key, 'type' => $type, 'description' => $description, 'required' => $required]
            + array_intersect_key($extra, array_flip(['default', 'min', 'max', 'values']));
    }

    /** A string argument restricted to the listed values. @param array<int, string> $values */
    private static function choice(string $key, string $description, array $values): array
    {
        return self::arg($key, 'string', $description, false, ['values' => $values]);
    }

    /** @param array<string, string> $columns key => label @return array<int, array{key:string,label:string}> */
    private static function columns(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }

    /** Case-insensitive equality on a text column, portable across MySQL and SQLite. */
    private function whereLower(Builder $query, string $column, string $value): void
    {
        $query->whereRaw('LOWER(' . $column . ') = ?', [mb_strtolower($value)]);
    }

    /** Left-join a tenant-owned table, the tenant pinned in the join itself. */
    private function joinTenant(Builder $query, string $table, string $alias, string $key, string $foreign, string $tenant, string $tenantColumn = 'tenant_id'): void
    {
        $query->leftJoin("{$table} as {$alias}", function ($join) use ($alias, $key, $foreign, $tenant, $tenantColumn) {
            $join->on("{$alias}.{$key}", '=', $foreign)->where("{$alias}.{$tenantColumn}", '=', $tenant);
        });
    }

    /**
     * The sources. Table and column names are HP Brain's, checked against the
     * migrations and the test schema; only columns present in both are selected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $limitArg = self::arg('limit', 'integer', 'Maximum rows to return (1–500, default 100).', false, ['default' => self::DEFAULT_LIMIT, 'min' => 1, 'max' => self::MAX_ROWS]);

        return $this->definitions = [
            // ---------------------------------------------------------------- departments
            [
                'name' => 'departments.roster',
                'module' => 'departments',
                'label' => 'Department roster',
                'description' => 'Every department of the organisation with its parent, status and how many active people it has — the same rows the Departments screen lists.',
                'entities' => ['OrganizationUnit'],
                'columns' => self::columns([
                    'department_id' => 'Department ID',
                    'department' => 'Department',
                    'parent_department' => 'Parent department',
                    'status' => 'Status',
                    'people' => 'Active people',
                ]),
                'arguments' => [
                    self::choice('status', 'active or inactive.', ['active', 'inactive']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $unit = $this->resolver->resolve($tenant, 'OrganizationUnit');

                    $select = [$unit->primaryKey . ' as department_id', $unit->field('name') . ' as department'];
                    $select[] = $unit->has('parent') ? $unit->field('parent') . ' as parent_id' : DB::raw('NULL as parent_id');
                    $select[] = $unit->has('status') ? $unit->field('status') . ' as status_value' : DB::raw('NULL as status_value');

                    $q = DB::table($unit->table)
                        ->where($unit->tenantKey, $tenant)
                        ->select($select)
                        ->orderBy($unit->field('name'));

                    if ($unit->has('deletedAt')) {
                        $q->whereNull($unit->field('deletedAt'));
                    }

                    $this->departments->apply($q, $unit, $tenant);

                    if (($s = $this->text($a, 'status')) !== null && $unit->has('status')) {
                        strtolower($s) === 'active'
                            ? $q->where($unit->field('status'), 1)
                            : $q->where(fn ($w) => $w->where($unit->field('status'), '<>', 1)->orWhereNull($unit->field('status')));
                    }

                    return $q;
                },
                'finish' => function (array $rows, string $tenant): array {
                    $unit = $this->resolver->resolve($tenant, 'OrganizationUnit');
                    $parents = $this->unitNames($unit, $tenant, array_column($rows, 'parent_id'));
                    $people = $this->peopleByUnit($tenant, array_column($rows, 'department_id'));

                    return array_map(fn (array $r) => [
                        'department_id' => (string) $r['department_id'],
                        'department' => $r['department'],
                        'parent_department' => $parents[(string) ($r['parent_id'] ?? '')] ?? null,
                        'status' => $r['status_value'] === null ? null : ((int) $r['status_value'] === 1 ? 'active' : 'inactive'),
                        'people' => $people[(string) $r['department_id']] ?? 0,
                    ], $rows);
                },
            ],

            // ---------------------------------------------------------------- people
            [
                'name' => 'people.directory',
                'module' => 'people',
                'label' => 'People directory',
                'description' => 'Active people with their department, role and position, and how complete each person\'s record is. Roster fields only — no contact details.',
                'entities' => ['Person'],
                'columns' => self::columns([
                    'person_id' => 'Person ID',
                    'name' => 'Name',
                    'department' => 'Department',
                    'role' => 'Role',
                    'position' => 'Position',
                    'record_completeness' => 'Record completeness (%)',
                    'missing' => 'Missing from record',
                ]),
                'arguments' => [
                    self::arg('department_id', 'string', 'Only people in this department.'),
                    self::choice('completeness', 'complete or incomplete.', ['complete', 'incomplete']),
                    $limitArg,
                ],
                'query' => fn (string $tenant, array $a): Builder => $this->peopleQuery($tenant, $a),
                'finish' => fn (array $rows, string $tenant): array => array_map(fn (array $r) => $this->presentPerson($r), $rows),
            ],

            // ---------------------------------------------------------------- capabilities
            [
                'name' => 'capabilities.assignments',
                'module' => 'capabilities',
                'label' => 'Capability assignments',
                'description' => 'Capabilities assigned to people, departments, job roles and the organisation, with the capability\'s code, category and criticality.',
                'tables' => ['hpbrain_capability_assignments', 'hpbrain_capabilities'],
                'columns' => self::columns([
                    'assignment_id' => 'Assignment ID',
                    'capability_code' => 'Capability code',
                    'capability' => 'Capability',
                    'category' => 'Category',
                    'criticality' => 'Criticality',
                    'target_type' => 'Assigned to (type)',
                    'target_id' => 'Assigned to (ID)',
                    'status' => 'Status',
                    'assigned_date' => 'Assigned',
                ]),
                'arguments' => [
                    self::choice('target_type', 'Person, Department, JobRole or Organization.', ['Person', 'Department', 'JobRole', 'Organization']),
                    self::arg('status', 'string', 'Assignment status, e.g. active.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_capability_assignments as x');
                    $this->joinTenant($q, 'hpbrain_capabilities', 'c', 'id', 'x.capability_id', $tenant);

                    $q->where('x.tenant_id', $tenant)
                        ->select([
                            'x.id as assignment_id', 'c.capability_code', 'c.name as capability', 'c.category', 'c.criticality',
                            'x.target_type', 'x.target_id', 'x.status', 'x.assigned_date',
                        ])
                        ->orderByDesc('x.assigned_date');

                    if (($t = $this->text($a, 'target_type')) !== null) {
                        $this->whereLower($q, 'x.target_type', $t);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'x.status', $s);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- signals
            [
                'name' => 'signals.open',
                'module' => 'signals',
                'label' => 'Open signals',
                'description' => 'Signals not yet resolved, closed or dismissed, with source, classification, severity, priority and confidence — newest first.',
                'tables' => ['hpbrain_signals'],
                'columns' => self::columns([
                    'signal_id' => 'Signal ID',
                    'source' => 'Source',
                    'classification' => 'Classification',
                    'severity' => 'Severity',
                    'priority' => 'Priority',
                    'confidence' => 'Confidence',
                    'status' => 'Status',
                    'related_entity_type' => 'Related entity type',
                    'related_entity_id' => 'Related entity ID',
                    'created_date' => 'Raised',
                ]),
                'arguments' => [
                    self::choice('severity', 'low, medium, high or critical.', ['low', 'medium', 'high', 'critical']),
                    self::choice('status', 'new, triaged or investigating.', ['new', 'triaged', 'investigating']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_signals')
                        ->where('tenant_id', $tenant)
                        ->whereRaw('LOWER(COALESCE(status, \'\')) NOT IN (?, ?, ?)', self::CLOSED_SIGNAL_STATUSES)
                        ->select([
                            'id as signal_id', 'source', 'classification', 'severity', 'priority', 'confidence', 'status',
                            'related_entity_type', 'related_entity_id', 'created_date',
                        ])
                        ->orderByDesc('created_date');

                    if (($s = $this->text($a, 'severity')) !== null) {
                        $this->whereLower($q, 'severity', $s);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'status', $s);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- evidence
            [
                'name' => 'evidence.by_signal',
                'module' => 'evidence',
                'label' => 'Evidence by signal',
                'description' => 'Evidence records with the signal each supports, its type, source, confidence and status — newest first.',
                'tables' => ['hpbrain_evidence', 'hpbrain_signals'],
                'columns' => self::columns([
                    'evidence_id' => 'Evidence ID',
                    'signal_id' => 'Signal ID',
                    'signal_classification' => 'Signal classification',
                    'evidence_type' => 'Evidence type',
                    'source' => 'Source',
                    'confidence' => 'Confidence',
                    'status' => 'Status',
                    'observed_date' => 'Observed',
                    'created_date' => 'Recorded',
                ]),
                'arguments' => [
                    self::arg('signal_id', 'string', 'Only evidence attached to this signal.'),
                    self::choice('evidence_type', 'observation, assessment, document, system or testimony.', ['observation', 'assessment', 'document', 'system', 'testimony']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_evidence as e');
                    $this->joinTenant($q, 'hpbrain_signals', 's', 'id', 'e.signal_id', $tenant);

                    $q->where('e.tenant_id', $tenant)
                        ->select([
                            'e.id as evidence_id', 'e.signal_id', 's.classification as signal_classification', 'e.evidence_type',
                            'e.source', 'e.confidence', 'e.status', 'e.observed_date', 'e.created_date',
                        ])
                        ->orderByDesc('e.created_date');

                    if (($s = $this->text($a, 'signal_id')) !== null) {
                        $q->where('e.signal_id', $s);
                    }
                    if (($t = $this->text($a, 'evidence_type')) !== null) {
                        $this->whereLower($q, 'e.evidence_type', $t);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- deliberation
            [
                'name' => 'deliberation.open_cases',
                'module' => 'deliberation',
                'label' => 'Open cases',
                'description' => 'Cases still under deliberation, with the signal that raised them and how many hypotheses and reasoning steps each has.',
                'tables' => ['hpbrain_cases', 'hpbrain_hypotheses', 'hpbrain_reasoning_steps'],
                'columns' => self::columns([
                    'case_id' => 'Case ID',
                    'title' => 'Case',
                    'status' => 'Status',
                    'signal_id' => 'Signal ID',
                    'hypotheses' => 'Hypotheses',
                    'open_hypotheses' => 'Open hypotheses',
                    'reasoning_steps' => 'Reasoning steps',
                    'created_date' => 'Opened',
                    'updated_date' => 'Last updated',
                ]),
                'arguments' => [
                    self::choice('status', 'open, investigating or hypothesized.', ['open', 'investigating', 'hypothesized']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_cases as c')
                        ->where('c.tenant_id', $tenant)
                        ->whereRaw('LOWER(COALESCE(c.status, \'\')) NOT IN (?, ?, ?, ?)', self::CLOSED_CASE_STATUSES)
                        ->select(['c.id as case_id', 'c.title', 'c.status', 'c.signal_id'])
                        ->selectRaw('(SELECT COUNT(*) FROM hpbrain_hypotheses h WHERE h.case_id = c.id AND h.tenant_id = c.tenant_id) as hypotheses')
                        ->selectRaw("(SELECT COUNT(*) FROM hpbrain_hypotheses h WHERE h.case_id = c.id AND h.tenant_id = c.tenant_id AND LOWER(h.status) IN ('proposed', 'supported')) as open_hypotheses")
                        ->selectRaw('(SELECT COUNT(*) FROM hpbrain_reasoning_steps r WHERE r.case_id = c.id AND r.tenant_id = c.tenant_id) as reasoning_steps')
                        ->addSelect(['c.created_date', 'c.updated_date'])
                        ->orderByDesc('c.updated_date');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'c.status', $s);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- intelligence workspace
            [
                'name' => 'workspace.recommendations',
                'module' => 'intelligence_workspace',
                'label' => 'Recommendations',
                'description' => 'The organisation\'s recommendations with category, priority, confidence, impact, cost, risk and where each stands — newest first.',
                'tables' => ['hpbrain_recommendations'],
                'columns' => self::columns([
                    'recommendation_id' => 'Recommendation ID',
                    'title' => 'Recommendation',
                    'category' => 'Category',
                    'priority' => 'Priority',
                    'confidence' => 'Confidence',
                    'impact' => 'Impact',
                    'cost' => 'Cost',
                    'risk' => 'Risk',
                    'status' => 'Status',
                    'created_date' => 'Raised',
                ]),
                'arguments' => [
                    self::choice('status', 'pending, accepted, rejected or deferred.', ['pending', 'accepted', 'rejected', 'deferred']),
                    self::choice('category', 'watch, investigate, intervene or escalate.', ['watch', 'investigate', 'intervene', 'escalate']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    // Tenant rows ONLY — a '*' row is nobody's to decide (see
                    // AiIntelligence\Recommendations\RecommendationRepository).
                    $q = DB::table('hpbrain_recommendations')
                        ->where('tenant_id', $tenant)
                        ->select([
                            'id as recommendation_id', 'title', 'category', 'priority', 'confidence',
                            'impact', 'cost', 'risk', 'status', 'created_date',
                        ])
                        ->orderByDesc('created_date');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->whereRaw('LOWER(TRIM(status)) = ?', [mb_strtolower($s)]);
                    }
                    if (($c = $this->text($a, 'category')) !== null) {
                        $this->whereLower($q, 'category', $c);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- executions
            [
                'name' => 'executions.outcomes',
                'module' => 'executions',
                'label' => 'ESO executions and outcomes',
                'description' => 'ESO executions with the ESO run, status, executor type, dates, and the latest outcome recorded against the decision behind each.',
                'tables' => ['hpbrain_eso_executions', 'hpbrain_eso_definitions', 'hpbrain_outcomes'],
                'columns' => self::columns([
                    'execution_id' => 'Execution ID',
                    'eso' => 'ESO',
                    'status' => 'Status',
                    'executor_type' => 'Executor type',
                    'started_date' => 'Started',
                    'completed_date' => 'Completed',
                    'decision_id' => 'Decision ID',
                    'outcome' => 'Latest outcome',
                ]),
                'arguments' => [
                    self::choice('status', 'running, completed, failed or rolled_back.', ['running', 'completed', 'failed', 'rolled_back']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_eso_executions as x');
                    $this->joinTenant($q, 'hpbrain_eso_definitions', 'd', 'id', 'x.eso_id', $tenant);

                    $q->where('x.tenant_id', $tenant)
                        ->select(['x.id as execution_id', 'd.name as eso', 'x.status', 'x.executor_type', 'x.started_date', 'x.completed_date', 'x.decision_id'])
                        ->selectRaw('(SELECT o.result FROM hpbrain_outcomes o WHERE o.decision_id = x.decision_id AND o.tenant_id = x.tenant_id ORDER BY o.created_date DESC LIMIT 1) as outcome')
                        ->orderByDesc('x.created_date');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'x.status', $s);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- decision analytics
            [
                'name' => 'decisions.outcomes',
                'module' => 'decision_analytics',
                'label' => 'Decisions and outcomes',
                'description' => 'Decisions recorded through the governance gate, with the recommendation decided, executor type, status, confidence and the latest outcome measured.',
                'tables' => ['hpbrain_decisions', 'hpbrain_recommendations', 'hpbrain_outcomes'],
                'columns' => self::columns([
                    'decision_id' => 'Decision ID',
                    'recommendation' => 'Recommendation',
                    'executor_type' => 'Executor type',
                    'status' => 'Status',
                    'confidence' => 'Confidence',
                    'approved_date' => 'Approved',
                    'outcome' => 'Latest outcome',
                    'outcomes_recorded' => 'Outcomes recorded',
                    'created_date' => 'Decided',
                ]),
                'arguments' => [
                    self::choice('status', 'proposed, approved or rejected.', ['proposed', 'approved', 'rejected']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_decisions as d');
                    $this->joinTenant($q, 'hpbrain_recommendations', 'r', 'id', 'd.recommendation_id', $tenant);

                    $q->where('d.tenant_id', $tenant)
                        ->select(['d.id as decision_id', 'r.title as recommendation', 'd.executor_type', 'd.status', 'd.confidence', 'd.approved_date'])
                        ->selectRaw('(SELECT o.result FROM hpbrain_outcomes o WHERE o.decision_id = d.id AND o.tenant_id = d.tenant_id ORDER BY o.created_date DESC LIMIT 1) as outcome')
                        ->selectRaw('(SELECT COUNT(*) FROM hpbrain_outcomes o WHERE o.decision_id = d.id AND o.tenant_id = d.tenant_id) as outcomes_recorded')
                        ->addSelect('d.created_date')
                        ->orderByDesc('d.created_date');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'd.status', $s);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- mental models
            [
                'name' => 'knowledge.mental_models',
                'module' => 'mental_models',
                'label' => 'Mental models',
                'description' => 'The mental models the organisation reasons with — domain, version and status.',
                'tables' => ['hpbrain_mental_models'],
                'columns' => self::columns([
                    'model_id' => 'Model ID',
                    'name' => 'Mental model',
                    'description' => 'Description',
                    'domain' => 'Domain',
                    'version' => 'Version',
                    'status' => 'Status',
                    'created_date' => 'Created',
                ]),
                'arguments' => [
                    self::arg('status', 'string', 'Model status, e.g. active.'),
                    self::arg('domain', 'string', 'Only models in this domain.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_mental_models')
                        ->where('tenant_id', $tenant)
                        ->select(['id as model_id', 'name', 'description', 'domain', 'version', 'status', 'created_date'])
                        ->orderBy('name');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'status', $s);
                    }
                    if (($d = $this->text($a, 'domain')) !== null) {
                        $q->where('domain', $d);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- knowledge graph
            [
                'name' => 'graph.entity_mappings',
                'module' => 'knowledge_graph',
                'label' => 'Entity mappings (knowledge graph)',
                'description' => 'How this organisation\'s source records map onto the knowledge graph\'s universal entities.',
                'tables' => ['hpbrain_entity_mappings'],
                'columns' => self::columns([
                    'source_system' => 'Source system',
                    'source_entity' => 'Source entity',
                    'source_field' => 'Source field',
                    'universal_entity' => 'Universal entity',
                    'universal_field' => 'Universal field',
                    'mapping_type' => 'Mapping type',
                ]),
                'arguments' => [
                    self::arg('universal_entity', 'string', 'Only mappings onto this universal entity.'),
                    self::arg('source_system', 'string', 'Only mappings from this source system.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    // Tenant rows only — HP Brain writes no '*' mappings, and G2G's
                    // '*' / NULL fallback would show one tenant's ERP layout to another.
                    $q = DB::table('hpbrain_entity_mappings')
                        ->where('tenant_id', $tenant)
                        ->where('is_active', 1)
                        ->select(['source_system', 'source_entity', 'source_field', 'universal_entity', 'universal_field', 'mapping_type'])
                        ->orderBy('universal_entity')
                        ->orderBy('universal_field');

                    if (($u = $this->text($a, 'universal_entity')) !== null) {
                        $q->where('universal_entity', $u);
                    }
                    if (($s = $this->text($a, 'source_system')) !== null) {
                        $q->where('source_system', $s);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- kasba
            [
                'name' => 'kasba.profile',
                'module' => 'kasba',
                'label' => 'KASBA proficiency profile',
                'description' => 'Each capability assignment with its latest knowledge, ability, skill, behaviour and attitude levels (blank = not assessed) and evidence confidence.',
                'tables' => ['hpbrain_capability_assignments', 'hpbrain_capabilities', 'hpbrain_capability_proficiency'],
                'columns' => self::columns([
                    'assignment_id' => 'Assignment ID',
                    'capability' => 'Capability',
                    'category' => 'Category',
                    'target_type' => 'Assessed (type)',
                    'target_id' => 'Assessed (ID)',
                    'knowledge_level' => 'Knowledge',
                    'ability_level' => 'Ability',
                    'skill_level' => 'Skill',
                    'behaviour_level' => 'Behaviour',
                    'attitude_level' => 'Attitude',
                    'evidence_confidence' => 'Evidence confidence',
                    'assessed_date' => 'Assessed',
                ]),
                'arguments' => [
                    self::choice('target_type', 'Person, Department, JobRole or Organization.', ['Person', 'Department', 'JobRole', 'Organization']),
                    self::arg('category', 'string', 'Only capabilities in this category.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_capability_assignments as x');
                    $this->joinTenant($q, 'hpbrain_capabilities', 'c', 'id', 'x.capability_id', $tenant);

                    // The latest assessment per assignment (KasbaService reads the same).
                    $q->leftJoin('hpbrain_capability_proficiency as p', function ($join) use ($tenant) {
                        $join->on('p.assignment_id', '=', 'x.id')
                            ->where('p.tenant_id', '=', $tenant)
                            ->whereRaw('p.created_date = (SELECT MAX(p2.created_date) FROM hpbrain_capability_proficiency p2 WHERE p2.assignment_id = x.id AND p2.tenant_id = x.tenant_id)');
                    });

                    $q->where('x.tenant_id', $tenant)
                        ->select([
                            'x.id as assignment_id', 'c.name as capability', 'c.category', 'x.target_type', 'x.target_id',
                            'p.knowledge_level', 'p.ability_level', 'p.skill_level', 'p.behaviour_level', 'p.attitude_level',
                            'p.evidence_confidence', 'p.assessed_date',
                        ])
                        ->orderBy('c.name');

                    if (($t = $this->text($a, 'target_type')) !== null) {
                        $this->whereLower($q, 'x.target_type', $t);
                    }
                    if (($c = $this->text($a, 'category')) !== null) {
                        $this->whereLower($q, 'c.category', $c);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- knowledge library
            [
                'name' => 'knowledge.assets',
                'module' => 'knowledge_library',
                'label' => 'Knowledge assets',
                'description' => 'Knowledge-library assets (archived excluded) with category, confidence, status and how often each has been reused.',
                'tables' => ['hpbrain_knowledge_assets'],
                'columns' => self::columns([
                    'asset_id' => 'Asset ID',
                    'title' => 'Asset',
                    'category' => 'Category',
                    'confidence' => 'Confidence',
                    'reuse_count' => 'Times reused',
                    'status' => 'Status',
                    'department_id' => 'Department ID',
                    'updated_date' => 'Last updated',
                ]),
                'arguments' => [
                    self::arg('category', 'string', 'Only assets in this category.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_knowledge_assets')
                        ->where('tenant_id', $tenant)
                        ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '<>', 'archived'))
                        ->select(['id as asset_id', 'title', 'category', 'confidence', 'reuse_count', 'status', 'department_id', 'updated_date'])
                        ->orderByDesc('updated_date');

                    if (($c = $this->text($a, 'category')) !== null) {
                        $q->where('category', $c);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- organisational memory
            [
                'name' => 'memory.items',
                'module' => 'organisational_memory',
                'label' => 'Organisational memory',
                'description' => 'Learnings the organisation has recorded from its outcomes — the pattern, domain, confidence and whether each is reusable.',
                'tables' => ['hpbrain_learnings'],
                'columns' => self::columns([
                    'learning_id' => 'Learning ID',
                    'pattern' => 'Pattern',
                    'description' => 'Description',
                    'domain' => 'Domain',
                    'confidence' => 'Confidence',
                    'reusable' => 'Reusable',
                    'outcome_id' => 'Outcome ID',
                    'created_date' => 'Recorded',
                ]),
                'arguments' => [
                    self::arg('domain', 'string', 'Only learnings in this domain.'),
                    self::arg('reusable', 'boolean', 'true for reusable learnings only; false or absent for every learning.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_learnings')
                        ->where('tenant_id', $tenant)
                        ->select(['id as learning_id', 'pattern', 'description', 'domain', 'confidence', 'reusable', 'outcome_id', 'created_date'])
                        ->orderByDesc('created_date');

                    if (($d = $this->text($a, 'domain')) !== null) {
                        $q->where('domain', $d);
                    }
                    // A checkbox: ticked narrows to reusable learnings, unticked means no filter.
                    // Reading false as "non-reusable only" silently dropped every reusable
                    // learning from the default report, which always sends the box's value.
                    if (array_key_exists('reusable', $a) && filter_var($a['reusable'], FILTER_VALIDATE_BOOLEAN)) {
                        $q->where('reusable', 1);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- eso catalogue
            [
                'name' => 'eso.catalogue',
                'module' => 'eso',
                'label' => 'ESO catalogue',
                'description' => 'The organisation\'s executable standard operations with code, version, status, owner, objective and trust level.',
                'tables' => ['hpbrain_eso_definitions'],
                'columns' => self::columns([
                    'eso_id' => 'ESO ID',
                    'eso_code' => 'Code',
                    'name' => 'ESO',
                    'version' => 'Version',
                    'status' => 'Status',
                    'owner' => 'Owner',
                    'objective' => 'Objective',
                    'trust_level' => 'Trust level',
                    'provenance' => 'Provenance',
                    'updated_date' => 'Last updated',
                ]),
                'arguments' => [
                    self::choice('status', 'draft, active, published, approved, released, retired, deprecated or superseded.', ['draft', 'active', 'published', 'approved', 'released', 'retired', 'deprecated', 'superseded']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    $q = DB::table('hpbrain_eso_definitions')
                        ->where('tenant_id', $tenant)
                        ->select(['id as eso_id', 'eso_code', 'name', 'version', 'status', 'owner', 'objective', 'trust_level', 'provenance', 'updated_date'])
                        ->orderBy('name');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->whereRaw('LOWER(TRIM(status)) = ?', [mb_strtolower($s)]);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- agents & executors
            [
                'name' => 'agents.runs',
                'module' => 'agents',
                'label' => 'Executors and their runs',
                'description' => 'Registered executors — human, AI, system and external — with trust level, workload, and how many ESO executions each has run, completed and failed.',
                'tables' => ['hpbrain_executors', 'hpbrain_eso_executions'],
                'columns' => self::columns([
                    'executor_id' => 'Executor ID',
                    'name' => 'Executor',
                    'executor_type' => 'Type',
                    'status' => 'Status',
                    'trust_level' => 'Trust level',
                    'current_workload' => 'Current workload',
                    'max_concurrent' => 'Max concurrent',
                    'runs' => 'Runs',
                    'completed_runs' => 'Completed',
                    'failed_runs' => 'Failed',
                    'last_run' => 'Last run',
                ]),
                'arguments' => [
                    self::choice('executor_type', 'human, system, ai or external.', ['human', 'system', 'ai', 'external']),
                    self::arg('status', 'string', 'Executor status, e.g. active.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    // hpbrain_eso_executions.executed_by carries the executor's id.
                    $q = DB::table('hpbrain_executors as e')
                        ->where('e.tenant_id', $tenant)
                        ->select(['e.id as executor_id', 'e.name', 'e.executor_type', 'e.status', 'e.trust_level', 'e.current_workload', 'e.max_concurrent'])
                        ->selectRaw('(SELECT COUNT(*) FROM hpbrain_eso_executions x WHERE x.executed_by = e.id AND x.tenant_id = e.tenant_id) as runs')
                        ->selectRaw("(SELECT COUNT(*) FROM hpbrain_eso_executions x WHERE x.executed_by = e.id AND x.tenant_id = e.tenant_id AND LOWER(x.status) IN ('completed', 'succeeded', 'success')) as completed_runs")
                        ->selectRaw("(SELECT COUNT(*) FROM hpbrain_eso_executions x WHERE x.executed_by = e.id AND x.tenant_id = e.tenant_id AND LOWER(x.status) IN ('failed', 'rolled_back')) as failed_runs")
                        ->selectRaw('(SELECT MAX(x.created_date) FROM hpbrain_eso_executions x WHERE x.executed_by = e.id AND x.tenant_id = e.tenant_id) as last_run')
                        ->orderBy('e.name');

                    if (($t = $this->text($a, 'executor_type')) !== null) {
                        $this->whereLower($q, 'e.executor_type', $t);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'e.status', $s);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- tasks
            [
                'name' => 'tasks.queue',
                'module' => 'tasks',
                'label' => 'Open work queue',
                'description' => 'Imported operational work items not yet closed, with category, status, the owner and department they are attributed to — oldest first.',
                'tables' => ['hpbrain_operational_records'],
                'columns' => self::columns([
                    'record_id' => 'Record ID',
                    'reference' => 'Reference',
                    'dataset' => 'Dataset',
                    'category' => 'Category',
                    'sub_category' => 'Sub-category',
                    'status' => 'Status',
                    'owner' => 'Owner',
                    'department' => 'Department',
                    'occurred_at' => 'Received',
                ]),
                'arguments' => [
                    self::arg('status', 'string', 'Only work items in this status.'),
                    self::arg('category', 'string', 'Only work items in this category.'),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    // HP Brain has no task table of its own: the work attributed to
                    // departments and people is hpbrain_operational_records (the rows
                    // DepartmentVerdict / OperationalIntelligence read). Open = not closed.
                    $q = DB::table('hpbrain_operational_records')
                        ->where('tenant_id', $tenant)
                        ->whereNull('closed_at')
                        ->select([
                            'id as record_id', 'natural_key as reference', 'dataset', 'category', 'sub_category', 'status',
                            'owner_name as owner', 'department_label as department', 'occurred_at',
                        ])
                        ->orderBy('occurred_at');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'status', $s);
                    }
                    if (($c = $this->text($a, 'category')) !== null) {
                        $q->where('category', $c);
                    }

                    return $q;
                },
            ],

            // ---------------------------------------------------------------- policies
            [
                'name' => 'policies.catalogue',
                'module' => 'policies',
                'label' => 'Policy catalogue',
                'description' => 'Executor and governance policies with their scope and status (superseded versions excluded unless asked for).',
                'tables' => ['hpbrain_policies'],
                'columns' => self::columns([
                    'policy_id' => 'Policy ID',
                    'name' => 'Policy',
                    'policy_type' => 'Type',
                    'scope' => 'Scope',
                    'version' => 'Version',
                    'status' => 'Status',
                    'updated_date' => 'Last updated',
                ]),
                'arguments' => [
                    self::choice('status', 'active or superseded. Omit for every current (non-superseded) policy.', ['active', 'superseded']),
                    $limitArg,
                ],
                'query' => function (string $tenant, array $a): Builder {
                    // policy_type / version arrive with a later migration; selected only
                    // where this deployment has them, NULL otherwise.
                    $q = DB::table('hpbrain_policies')
                        ->where('tenant_id', $tenant)
                        ->select(['id as policy_id', 'name'])
                        ->addSelect($this->hasColumn('hpbrain_policies', 'policy_type') ? 'policy_type' : DB::raw('NULL as policy_type'))
                        ->addSelect('scope')
                        ->addSelect($this->hasColumn('hpbrain_policies', 'version') ? 'version' : DB::raw('NULL as version'))
                        ->addSelect(['status', 'updated_date'])
                        ->orderBy('name');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $this->whereLower($q, 'status', $s);
                    } else {
                        $q->where(fn ($w) => $w->whereNull('status')->orWhereRaw('LOWER(status) <> ?', ['superseded']));
                    }

                    return $q;
                },
            ],
        ];
    }

    // ------------------------------------------------------------------ ERP-backed helpers

    /** @param array<string, mixed> $a */
    private function peopleQuery(string $tenant, array $a): Builder
    {
        $person = $this->resolver->resolve($tenant, 'Person');

        $q = DB::table($person->table . ' as p')->where('p.' . $person->tenantKey, $tenant);

        if ($person->has('status')) {
            $q->where('p.' . $person->field('status'), 1);
        }
        if ($person->has('deletedAt')) {
            $q->whereNull('p.' . $person->field('deletedAt'));
        }

        $select = ['p.' . $person->primaryKey . ' as person_id'];

        foreach (['firstName' => 'first_name', 'lastName' => 'last_name'] as $field => $alias) {
            $select[] = $person->has($field) ? 'p.' . $person->field($field) . ' as ' . $alias : DB::raw("NULL as {$alias}");
        }

        // Presence flags only: whether the record HAS these, never their values.
        foreach (['unit' => 'has_unit', 'profile' => 'has_profile', 'position' => 'has_position', 'email' => 'has_email'] as $field => $alias) {
            if ($person->has($field)) {
                $column = 'p.' . $person->field($field);
                $select[] = DB::raw("CASE WHEN {$column} IS NULL OR {$column} = '' OR {$column} = '0' THEN 0 ELSE 1 END as {$alias}");
            } else {
                $select[] = DB::raw("NULL as {$alias}");
            }
        }

        $q->select($select);

        $labels = [
            'unit' => ['OrganizationUnit', 'name', 'department'],
            'profile' => ['PersonProfile', 'name', 'role'],
            'position' => ['Position', 'title', 'position'],
        ];
        $n = 0;

        foreach ($labels as $field => [$entity, $labelField, $alias]) {
            $source = $this->mapped($tenant, $entity);

            if ($source === null || ! $person->has($field) || ! $source->has($labelField)) {
                $q->addSelect(DB::raw("NULL as {$alias}"));

                continue;
            }

            $j = 'l' . (++$n);
            $q->leftJoin($source->table . ' as ' . $j, function ($join) use ($j, $source, $person, $field, $tenant) {
                $join->on($j . '.' . $source->primaryKey, '=', 'p.' . $person->field($field))
                    ->where($j . '.' . $source->tenantKey, '=', $tenant);
            });
            $q->addSelect($j . '.' . $source->field($labelField) . ' as ' . $alias);
        }

        if (($d = $this->text($a, 'department_id')) !== null && $person->has('unit')) {
            $q->where('p.' . $person->field('unit'), $d);
        }

        if (($c = $this->text($a, 'completeness')) !== null) {
            $checks = array_values(array_filter(['unit', 'profile', 'position', 'email'], fn ($f) => $person->has($f)));
            $missing = function ($w, string $column) {
                $w->whereNull($column)->orWhere($column, '')->orWhere($column, '0');
            };

            if (strtolower($c) === 'complete') {
                foreach ($checks as $field) {
                    $column = 'p.' . $person->field($field);
                    $q->whereNotNull($column)->where($column, '<>', '')->where($column, '<>', '0');
                }
            } elseif ($checks !== []) {
                $q->where(function ($w) use ($checks, $person, $missing) {
                    foreach ($checks as $field) {
                        $w->orWhere(fn ($inner) => $missing($inner, 'p.' . $person->field($field)));
                    }
                });
            }
        }

        if ($person->has('lastName')) {
            $q->orderBy('p.' . $person->field('firstName'))->orderBy('p.' . $person->field('lastName'));
        } else {
            $q->orderBy('p.' . $person->primaryKey);
        }

        return $q;
    }

    /** @param array<string, mixed> $r */
    private function presentPerson(array $r): array
    {
        $parts = ['has_unit' => 'department', 'has_profile' => 'role', 'has_position' => 'position', 'has_email' => 'email address'];
        $checked = 0;
        $present = 0;
        $missing = [];

        foreach ($parts as $flag => $label) {
            if ($r[$flag] === null) {
                continue;
            }

            $checked++;

            if ((int) $r[$flag] === 1) {
                $present++;
            } else {
                $missing[] = $label;
            }
        }

        return [
            'person_id' => (string) $r['person_id'],
            'name' => trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? ''))) ?: null,
            'department' => $r['department'] ?? null,
            'role' => $r['role'] ?? null,
            'position' => $r['position'] ?? null,
            'record_completeness' => $checked === 0 ? null : (int) round($present / $checked * 100),
            'missing' => $missing === [] ? null : implode(', ', $missing),
        ];
    }

    private function mapped(string $tenant, string $entity): ?ResolvedSource
    {
        try {
            return $this->resolver->has($tenant, $entity) ? $this->resolver->resolve($tenant, $entity) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int, mixed> $ids @return array<string, string> */
    private function unitNames(ResolvedSource $unit, string $tenant, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($v) => (string) $v, $ids), fn ($v) => $v !== '' && $v !== '0')));

        if ($ids === []) {
            return [];
        }

        return DB::table($unit->table)
            ->where($unit->tenantKey, $tenant)
            ->whereIn($unit->primaryKey, $ids)
            ->pluck($unit->field('name'), $unit->primaryKey)
            ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
            ->all();
    }

    /** Active people per unit, the FoundationCounts definition. @param array<int, mixed> $ids @return array<string, int> */
    private function peopleByUnit(string $tenant, array $ids): array
    {
        $person = $this->mapped($tenant, 'Person');
        $ids = array_values(array_filter(array_map(fn ($v) => (string) $v, $ids), fn ($v) => $v !== ''));

        if ($person === null || ! $person->has('unit') || $ids === []) {
            return [];
        }

        $q = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->whereIn($person->field('unit'), $ids);

        if ($person->has('status')) {
            $q->where($person->field('status'), 1);
        }
        if ($person->has('deletedAt')) {
            $q->whereNull($person->field('deletedAt'));
        }

        return $q->selectRaw($person->field('unit') . ' as unit_id, COUNT(*) as people')
            ->groupBy($person->field('unit'))
            ->pluck('people', 'unit_id')
            ->mapWithKeys(fn ($count, $id) => [(string) $id => (int) $count])
            ->all();
    }
}
