<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Everything the installation actually knows about ONE person, in one read.
 *
 * WHY THIS EXISTS. The person screen used to render a handful of loop-table
 * counters — capability assignments, decisions, ESO executions, learnings — and
 * for every tenant onboarded so far those four tables are empty, so the page was
 * five dashes and three "nothing recorded" panels. Meanwhile the rows that DO
 * describe a person (their ERP master row, their class-section unit, and the
 * operational records their reference appears in — 12 fee invoices per student
 * for the school tenant) were never read at all. The screen was not wrong about
 * the loop tables; it was looking only at them.
 *
 * THE RULES THIS CLASS IS BUILT TO KEEP.
 *
 * 1. NOTHING IS INVENTED. Every value returned is a column, a JSON field of a
 *    stored payload, or a sum of those. There is no default, no placeholder and
 *    no derived "score" that is not arithmetic over rows this person is
 *    genuinely attached to. A section with no rows returns null — never an empty
 *    shape that renders as zero — so the UI can say "not recorded" rather than
 *    "none", which are different facts supporting opposite decisions.
 *
 * 2. EVERY READ IS TENANT SCOPED, AND THE PERSON IS RESOLVED FIRST. The person
 *    row is fetched under the caller's tenant before anything else runs; a miss
 *    returns null and nothing further is queried. No later query re-derives the
 *    tenant from the person row.
 *
 * 3. ATTACHMENT IS BY STORED IDENTIFIER, AND IT IS DECLARED. Operational records
 *    carry no person foreign key — they carry the reference the source system
 *    used. Three columns can name a person: subject_ref (the record is ABOUT
 *    them), owner_name and supervisor_name (they handled it). Each is matched
 *    exactly, never by prefix or fuzzy comparison, and the response reports
 *    which rule matched how many rows so a reader can see why a record is on
 *    this page. Nothing is attached by inference.
 *
 * 4. TABLE NAMES COME FROM EntityResolver, NEVER FROM HERE. A school keeps
 *    people in tbluser; the next industry will not. Unmapped optional fields are
 *    skipped rather than defaulted.
 *
 * COST. A person can be attached to thousands of operational records — one field
 * engineer in the telecommunications tenant owns 5,418 — so counts and date
 * spans are aggregated in SQL and only a bounded window of rows is pulled into
 * PHP for display. The monetary summary needs payload JSON that SQL cannot sum
 * portably, so it reads rows; it declares how many it covered when the cap bites
 * rather than silently reporting a partial total as a whole one.
 */
final class PersonProfileService
{
    /** The five KASBA dimensions, in the order the UI renders them. */
    public const KASBA = ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'];

    /** Detail rows pulled into PHP for the activity table. */
    private const RECORD_WINDOW = 100;

    /**
     * Rows the monetary summary will read. A student carries 12–16 fee records,
     * so this is two orders of magnitude of headroom; past it the summary says
     * how much it covered instead of pretending to be complete.
     */
    private const FEE_WINDOW = 2000;

    /** Timeline events returned. Older ones are counted, not sent. */
    private const TIMELINE_LIMIT = 60;

    /** Universal person fields this service reads beyond the listing set. */
    private const PERSON_FIELDS = [
        'id', 'externalRef', 'firstName', 'lastName', 'email', 'phone', 'gender',
        'unit', 'profile', 'position', 'joinedDate', 'status',
    ];

    /**
     * Schema::hasTable() answers from information_schema, which is a round trip
     * per call — eight of them cost about 750 ms against the remote development
     * database, more than the reads they were guarding. Memoised per instance;
     * the service is resolved per request, so a table created mid-request is not
     * a case that arises.
     *
     * @var array<string, bool>
     */
    private array $tables = [];

    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    /**
     * @return array<string, mixed>|null null when the tenant has no such person
     */
    public function build(string $tenantId, string $personId): ?array
    {
        $source = $this->resolver->resolve($tenantId, 'Person');

        $row = DB::table($source->table)
            ->where($source->primaryKey, $personId)
            ->where($source->tenantKey, $tenantId)
            ->whereNull('deleted_at')
            ->where($source->field('status'), 1)
            ->first();

        if (! $row) {
            return null;
        }

        $person = (array) $row;
        // The id as the source stores it, not as the URL spelled it: '007' and
        // '7' can both reach a row whose key is 7, and every downstream match
        // has to use the one the database will compare against.
        $pid = (string) $person[$source->primaryKey];

        $identity = $this->identity($tenantId, $person, $source);
        $links = $this->linkRules($tenantId, $identity);
        $window = $this->recordWindow($tenantId, $links);
        $activity = $this->activity($links, $window);
        $fee = $this->feeProfile($tenantId, $links, $window);
        $intelligence = $this->intelligence($tenantId, $pid, $person, $source);
        $audit = $this->audit($tenantId, $pid);

        return [
            'person'       => $identity,
            'organization' => $this->organization($tenantId),
            // WHAT KIND OF ORGANIZATION THIS IS, told by what it has imported.
            //
            // A person page has to decide whether a panel is missing because
            // this person has no such record, or because the organization has
            // no such data at all — an "Academic record: none" panel on a
            // telecoms engineer is noise, while the same panel on a school's
            // student is a finding. Nothing else can answer that: the person's
            // own linkage lists only the datasets that matched THEM.
            'datasets'     => $this->tenantDatasets($tenantId),
            'linkage'      => $links,
            'academic'     => $fee['academic'] ?? null,
            'contacts'     => $this->contacts($tenantId, $pid, $fee['contact'] ?? null),
            'finance'      => $fee['finance'] ?? null,
            'activity'     => $activity,
            'intelligence' => $intelligence,
            'timeline'     => $this->timeline($identity, $activity, $intelligence, $audit),
            'audit'        => $audit,
        ];
    }

    // ---------------------------------------------------------------- identity

    /**
     * The person's own master row, plus the two rows that name them: their
     * organizational unit and their profile.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private function identity(string $tenantId, array $person, ResolvedSource $source): array
    {
        $value = function (string $field) use ($person, $source) {
            if (! $source->has($field)) {
                return null;
            }

            $raw = $person[$source->field($field)] ?? null;

            return $this->blankToNull($raw);
        };

        $first = (string) ($value('firstName') ?? '');
        $last = (string) ($value('lastName') ?? '');
        $display = trim($first.' '.$last);

        $unitId = $value('unit');
        $unit = $this->unit($tenantId, $unitId);

        return [
            'id'             => (string) $person[$source->primaryKey],
            'externalRef'    => $this->stringOrNull($value('externalRef')),
            'firstName'      => $first !== '' ? $first : null,
            'lastName'       => $last !== '' ? $last : null,
            'displayName'    => $display !== '' ? $display : null,
            'email'          => $this->stringOrNull($value('email')),
            'phone'          => $this->stringOrNull($value('phone')),
            'gender'         => $this->stringOrNull($value('gender')),
            'role'           => $this->profileName($tenantId, $value('profile')),
            'jobTitle'       => $this->positionTitle($tenantId, $value('position')),
            'departmentId'   => $unitId === null ? null : (string) $unitId,
            'departmentName' => $unit,
            'joinedDate'     => $this->stringOrNull($value('joinedDate')),
            'status'         => 'active',
            'createdDate'    => $this->stringOrNull($person['created_at'] ?? null),
            'updatedDate'    => $this->stringOrNull($person['updated_at'] ?? null),
            // Which universal fields this tenant's source actually maps. An
            // absent field is a fact about the ERP, and the screen renders it as
            // "not held in this system" rather than as an empty value.
            'mappedFields'   => array_values(array_keys($source->columns(self::PERSON_FIELDS))),
        ];
    }

    /** The person's organizational unit — class-section for a school. */
    private function unit(string $tenantId, mixed $unitId): ?string
    {
        if ($unitId === null || (string) $unitId === '' || (string) $unitId === '0') {
            return null;
        }

        try {
            $unit = $this->resolver->resolve($tenantId, 'OrganizationUnit');
        } catch (Throwable) {
            return null;
        }

        if (! $unit->has('name')) {
            return null;
        }

        $query = DB::table($unit->table)
            ->where($unit->primaryKey, $unitId)
            ->where($unit->tenantKey, $tenantId);

        if ($unit->has('deletedAt')) {
            $query->whereNull($unit->field('deletedAt'));
        }

        return $this->stringOrNull($query->value($unit->field('name')));
    }

    /** The person's profile — 'Student', 'Employee', 'Admin' — as the ERP names it. */
    private function profileName(string $tenantId, mixed $profileId): ?string
    {
        if ($profileId === null || (string) $profileId === '') {
            return null;
        }

        try {
            $profile = $this->resolver->resolve($tenantId, 'PersonProfile');
        } catch (Throwable) {
            return null;
        }

        if (! $profile->has('name')) {
            return null;
        }

        return $this->stringOrNull(
            DB::table($profile->table)
                ->where($profile->primaryKey, $profileId)
                ->where($profile->tenantKey, $tenantId)
                ->value($profile->field('name'))
        );
    }

    private function positionTitle(string $tenantId, mixed $positionId): ?string
    {
        if ($positionId === null || (string) $positionId === '') {
            return null;
        }

        try {
            $position = $this->resolver->resolve($tenantId, 'Position');
        } catch (Throwable) {
            return null;
        }

        if (! $position->has('title')) {
            return null;
        }

        $query = DB::table($position->table)
            ->where($position->primaryKey, $positionId)
            ->where($position->tenantKey, $tenantId);

        if ($position->has('deletedAt')) {
            $query->whereNull($position->field('deletedAt'));
        }

        return $this->stringOrNull($query->value($position->field('title')));
    }

    /** @return array<string, mixed>|null */
    private function organization(string $tenantId): ?array
    {
        try {
            $org = $this->resolver->resolve($tenantId, 'Organization');
        } catch (Throwable) {
            return null;
        }

        $columns = $org->columns(['id', 'name', 'code', 'industry']);

        $query = DB::table($org->table)->where($org->tenantKey, $tenantId);

        if ($org->has('deletedAt')) {
            $query->whereNull($org->field('deletedAt'));
        }

        $row = $query->first(array_values(array_unique($columns)));

        if (! $row) {
            return null;
        }

        $row = (array) $row;

        return [
            'id'       => (string) ($row[$columns['id'] ?? ''] ?? $tenantId),
            'name'     => isset($columns['name']) ? $this->stringOrNull($row[$columns['name']] ?? null) : null,
            'code'     => isset($columns['code']) ? $this->stringOrNull($row[$columns['code']] ?? null) : null,
            'industry' => isset($columns['industry']) ? $this->stringOrNull($row[$columns['industry']] ?? null) : null,
        ];
    }

    // ----------------------------------------------------------------- linkage

    /**
     * How this person is attached to operational records, and how many rows each
     * rule matched.
     *
     * The rules are published rather than applied silently. A record appearing on
     * a person's page because their NAME matched an owner column is a weaker
     * claim than one whose subject reference is their employee number, and a
     * reader is entitled to see which they are looking at.
     *
     * ONE PASS, NOT FOUR. Per-rule counts plus the union count plus the
     * per-dataset breakdown were five separate statements, and this table's
     * composite indexes are (tenant_id, dataset, subject_ref) and
     * (tenant_id, dataset, owner_name) — with no `dataset` predicate only the
     * tenant prefix is usable, so each of those five was a scan of the tenant's
     * whole record set. Against the remote development database that measured
     * nine seconds for one person. Conditional aggregation answers all five from
     * a single grouped scan; `sum(case when … end)` rather than `sum(col = ?)` so
     * the expression means the same thing on SQLite, which the suite runs on.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    /**
     * Every operational dataset this tenant has imported, with its size.
     *
     * ORGANIZATION-WIDE, DELIBERATELY. It answers "does this organization keep
     * school fee records / work orders / complaints at all", which is a property
     * of the tenant and not of the person being viewed, and it is what lets a
     * screen omit a whole section rather than render an empty one.
     *
     * One grouped scan on the (tenant_id, dataset, …) index prefix, and the
     * result is a handful of rows — datasets are a fixed small vocabulary, not
     * a per-record dimension.
     *
     * @return array<int, array{dataset: string, label: string, records: int}>
     */
    private function tenantDatasets(string $tenantId): array
    {
        if (! $this->hasTable('hpbrain_operational_records')) {
            return [];
        }

        return DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->groupBy('dataset')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([DB::raw('dataset'), DB::raw('COUNT(*) as records')])
            ->map(fn ($d) => [
                'dataset' => (string) $d->dataset,
                'label'   => $this->humanize((string) $d->dataset),
                'records' => (int) $d->records,
            ])
            ->all();
    }

    private function linkRules(string $tenantId, array $identity): array
    {
        if (! $this->hasTable('hpbrain_operational_records')) {
            return ['rules' => [], 'matched' => [], 'records' => 0, 'datasets' => [], 'available' => false];
        }

        $candidates = [];

        if (($identity['externalRef'] ?? null) !== null) {
            $candidates[] = [
                'column' => 'subject_ref',
                'label'  => 'Record subject',
                'value'  => (string) $identity['externalRef'],
                'basis'  => 'The source reference on the record equals this person’s reference in the ERP.',
            ];
        }

        if (($identity['displayName'] ?? null) !== null) {
            foreach ([
                ['owner_name', 'Handled by'],
                ['supervisor_name', 'Supervised by'],
            ] as [$column, $label]) {
                $candidates[] = [
                    'column' => $column,
                    'label'  => $label,
                    'value'  => (string) $identity['displayName'],
                    'basis'  => 'The name recorded in this column equals this person’s full name.',
                ];
            }
        }

        if ($candidates === []) {
            // The source holds neither a reference nor a name for this person, so
            // nothing can be attached to them. No query is worth issuing.
            return ['rules' => [], 'matched' => [], 'records' => 0, 'datasets' => [], 'available' => true];
        }

        $select = ['dataset'];
        $bindings = [];

        foreach ($candidates as $i => $candidate) {
            $select[] = DB::raw("sum(case when `{$candidate['column']}` = ? then 1 else 0 end) as rule_{$i}");
            $bindings[] = $candidate['value'];
        }

        $select[] = DB::raw('count(*) as records');
        $select[] = DB::raw('min(occurred_at) as first_seen');
        $select[] = DB::raw('max(occurred_at) as last_seen');

        $rows = $this->recordQuery($tenantId, $candidates)
            ->select($select)
            // The select's placeholders bind ahead of the where clause's, which
            // the builder cannot know from a raw expression.
            ->addBinding($bindings, 'select')
            ->groupBy('dataset')
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        $rules = [];
        foreach ($candidates as $i => $candidate) {
            $rules[] = $candidate + ['records' => (int) $rows->sum('rule_'.$i)];
        }

        $datasets = $rows->map(fn ($d) => [
            'dataset'   => (string) $d->dataset,
            'label'     => $this->humanize((string) $d->dataset),
            'records'   => (int) $d->records,
            'firstSeen' => $this->stringOrNull($d->first_seen),
            'lastSeen'  => $this->stringOrNull($d->last_seen),
        ])->all();

        return [
            'available' => true,
            'rules'     => $rules,
            'matched'   => array_values(array_filter($rules, static fn ($r) => $r['records'] > 0)),
            'records'   => (int) $rows->sum('records'),
            'datasets'  => $datasets,
        ];
    }

    /**
     * The tenant-scoped record set for this person.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function recordQuery(string $tenantId, array $rules): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId);

        if ($rules === []) {
            // No rule can name this person, so no record does. whereRaw('0=1')
            // rather than returning the unfiltered query: an unscoped builder
            // escaping this method would put the whole tenant's records on one
            // person's page.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($w) use ($rules) {
            foreach ($rules as $rule) {
                $w->orWhere($rule['column'], $rule['value']);
            }
        });
    }

    // ---------------------------------------------------------------- activity

    /**
     * Records attached to this person: the per-dataset rollup linkRules() already
     * counted, plus a bounded window of the most recent rows for display.
     *
     * @param  array<string, mixed>  $links
     * @param  \Illuminate\Support\Collection<int, object>  $window  raw rows, shared with feeProfile()
     * @return array<string, mixed>
     */
    private function activity(array $links, \Illuminate\Support\Collection $window): array
    {
        if (($links['available'] ?? false) !== true || ($links['records'] ?? 0) === 0) {
            return [
                'available' => $links['available'] ?? false,
                'datasets'  => [],
                'records'   => [],
                'total'     => 0,
                'shown'     => 0,
            ];
        }

        $records = $window->map(fn ($r) => $this->recordRow($r, $links['rules']))->values()->all();

        return [
            'available' => true,
            'datasets'  => $links['datasets'],
            'records'   => $records,
            'total'     => (int) $links['records'],
            'shown'     => count($records),
        ];
    }

    /**
     * The most recent rows attached to this person.
     *
     * @param  array<string, mixed>  $links
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function recordWindow(string $tenantId, array $links): \Illuminate\Support\Collection
    {
        if (($links['available'] ?? false) !== true || ($links['records'] ?? 0) === 0) {
            return collect();
        }

        return $this->recordQuery($tenantId, $links['rules'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_date')
            ->limit(self::RECORD_WINDOW)
            ->get([
                'id', 'dataset', 'natural_key', 'source_file', 'source_row', 'occurred_at', 'closed_at',
                'status', 'category', 'sub_category', 'owner_name', 'supervisor_name', 'subject_ref',
                'zone', 'area', 'metric_value', 'metric_unit', 'quantity', 'payload', 'import_job_id', 'created_date',
            ]);
    }

    /**
     * One operational record as the screen shows it.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function recordRow(object $r, array $rules): array
    {
        $payload = $this->payload($r->payload ?? null);

        $matched = [];
        foreach ($rules as $rule) {
            if ((string) ($r->{$rule['column']} ?? '') === $rule['value']) {
                $matched[] = $rule['label'];
            }
        }

        return [
            'id'          => (string) $r->id,
            'dataset'     => (string) $r->dataset,
            'datasetLabel' => $this->humanize((string) $r->dataset),
            'reference'   => $this->stringOrNull($r->natural_key),
            'occurredAt'  => $this->stringOrNull($r->occurred_at),
            'closedAt'    => $this->stringOrNull($r->closed_at),
            'status'      => $this->stringOrNull($r->status),
            'category'    => $this->stringOrNull($r->category),
            'subCategory' => $this->stringOrNull($r->sub_category),
            'amount'      => $r->metric_value === null ? null : round((float) $r->metric_value, 2),
            'currency'    => $this->stringOrNull($r->metric_unit),
            'quantity'    => $r->quantity === null ? null : round((float) $r->quantity, 2),
            'location'    => $this->stringOrNull($r->area) ?? $this->stringOrNull($r->zone),
            'linkedBy'    => $matched,
            'source'      => [
                'file'        => $this->stringOrNull($r->source_file),
                'row'         => $r->source_row === null ? null : (int) $r->source_row,
                'importJobId' => $this->stringOrNull($r->import_job_id),
                'importedAt'  => $this->stringOrNull($r->created_date),
            ],
            'detail'      => $this->recordDetail($payload),
        ];
    }

    /**
     * Stored payload fields as labelled pairs.
     *
     * Keys are humanized and the source-system cross-reference keys are dropped:
     * 'source_fees_status_id' is a join key in the exporting system and means
     * nothing on a person's page. Empty values are dropped rather than rendered
     * as a dash — a field the export left blank is not information.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{label: string, value: string}>
     */
    private function recordDetail(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $name = (string) $key;

            if (str_starts_with($name, 'source_') || $name === 'row_hash') {
                continue;
            }

            $text = trim((string) ($value ?? ''));

            if ($text === '') {
                continue;
            }

            $out[] = ['label' => $this->humanize($name), 'value' => $text];
        }

        return $out;
    }

    // ------------------------------------------------------------------ money

    /**
     * The school-fee profile: the class-section facts the export carries about
     * this student, their guardian contact, and the arithmetic over their own
     * invoices.
     *
     * ONLY THE school_fee DATASET. Summing 'amount' across datasets that mean
     * different things by it — a work order's quantity, a complaint's duration —
     * would produce a number with no referent.
     *
     * @param  array<string, mixed>  $links
     * @param  \Illuminate\Support\Collection<int, object>  $window  rows already fetched for the activity list
     * @return array{academic: array<string, mixed>|null, contact: array<string, mixed>|null, finance: array<string, mixed>|null}
     */
    private function feeProfile(string $tenantId, array $links, \Illuminate\Support\Collection $window): array
    {
        $none = ['academic' => null, 'contact' => null, 'finance' => null];

        $total = 0;
        foreach ($links['datasets'] ?? [] as $dataset) {
            if ($dataset['dataset'] === 'school_fee') {
                $total = $dataset['records'];
            }
        }

        if ($total === 0) {
            return $none;
        }

        // The activity window is every attached record, newest first. When it
        // already holds all of them — the case for a student, who carries twelve
        // to sixteen invoices — filtering it in PHP saves a second scan of a
        // table with no index this predicate can use.
        $rows = ($links['records'] ?? 0) <= self::RECORD_WINDOW
            ? $window->filter(static fn ($r) => (string) $r->dataset === 'school_fee')->values()
            : $this->recordQuery($tenantId, $links['rules'])
                ->where('dataset', 'school_fee')
                ->orderByDesc('occurred_at')
                ->limit(self::FEE_WINDOW)
                ->get(['natural_key', 'occurred_at', 'closed_at', 'status', 'category', 'sub_category', 'area', 'zone', 'metric_value', 'metric_unit', 'payload']);

        if ($rows->isEmpty()) {
            return $none;
        }

        $billed = 0.0;
        $concession = 0.0;
        $net = 0.0;
        $paid = 0.0;
        $outstanding = 0.0;
        $overdue = 0.0;
        $statusCounts = [];
        $components = [];
        $methods = [];
        $currency = null;
        $lastPayment = null;
        $nextDue = null;
        $invoices = [];

        // The academic facts come from the most recent invoice, which is the
        // first row of a descending sort. A student who changed section mid-year
        // has two answers in the data; the current one is the recent one, and the
        // count of distinct values is reported so a reader can see it changed.
        $latest = $this->payload($rows->first()->payload ?? null);
        $classValues = [];
        $sectionValues = [];

        foreach ($rows as $row) {
            $p = $this->payload($row->payload ?? null);

            $billed += $this->money($p['amount_due'] ?? $p['gross_fee_amount'] ?? null);
            $concession += $this->money($p['concession_amount'] ?? $p['discount_amount'] ?? null);
            $rowNet = $this->money($p['net_amount'] ?? $p['net_fee_amount'] ?? $row->metric_value ?? null);
            $rowPaid = $this->money($p['amount_paid'] ?? null);
            $rowOutstanding = $this->money($p['balance_amount'] ?? $p['outstanding_amount'] ?? null);
            $net += $rowNet;
            $paid += $rowPaid;
            $outstanding += $rowOutstanding;

            $daysOverdue = (int) $this->money($p['days_overdue'] ?? null);
            if ($daysOverdue > 0) {
                $overdue += $rowOutstanding;
            }

            $status = $this->text($p['payment_status'] ?? $row->status ?? null);
            if ($status !== null) {
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }

            $component = $this->text($p['fee_type'] ?? $p['fee_component'] ?? $row->category ?? null);
            if ($component !== null) {
                $components[$component] = ($components[$component] ?? 0) + $rowNet;
            }

            $method = $this->text($p['payment_method'] ?? null);
            if ($method !== null) {
                $methods[$method] = ($methods[$method] ?? 0) + 1;
            }

            $currency ??= $this->text($row->metric_unit ?? null) ?? $this->text($p['currency'] ?? null);

            $paymentDate = $this->text($p['payment_date'] ?? null);
            if ($paymentDate !== null && $rowPaid > 0 && ($lastPayment === null || $paymentDate > $lastPayment['date'])) {
                $lastPayment = ['date' => $paymentDate, 'amount' => round($rowPaid, 2), 'method' => $method];
            }

            $due = $this->text($p['fee_due_date'] ?? null);
            if ($due !== null && $rowOutstanding > 0 && ($nextDue === null || $due < $nextDue)) {
                $nextDue = $due;
            }

            $class = $this->text($p['class_name'] ?? $p['class'] ?? null);
            if ($class !== null) {
                $classValues[$class] = true;
            }
            $section = $this->text($p['section'] ?? null);
            if ($section !== null) {
                $sectionValues[$section] = true;
            }

            if (count($invoices) < self::RECORD_WINDOW) {
                $invoices[] = [
                    'reference'   => $this->text($p['invoice_id'] ?? $p['receipt_no'] ?? $row->natural_key ?? null),
                    'period'      => $this->text($p['fee_period'] ?? null),
                    'component'   => $component,
                    'dueDate'     => $due ?? $this->stringOrNull($row->occurred_at),
                    'net'         => round($rowNet, 2),
                    'paid'        => round($rowPaid, 2),
                    'outstanding' => round($rowOutstanding, 2),
                    'status'      => $status,
                    'daysOverdue' => $daysOverdue > 0 ? $daysOverdue : null,
                    'method'      => $method,
                    'paymentDate' => $paymentDate,
                ];
            }
        }

        arsort($components);
        arsort($methods);

        $academic = array_filter([
            'studentRef'    => $this->text($latest['student_ref'] ?? $latest['student_id'] ?? null),
            'admissionNo'   => $this->text($latest['admission_no'] ?? null),
            'grNo'          => $this->text($latest['gr_no'] ?? null),
            'class'         => $this->text($latest['class_name'] ?? $latest['class'] ?? null),
            'section'       => $this->text($latest['section'] ?? null),
            'academicYear'  => $this->text($latest['academic_year'] ?? null),
            'department'    => $this->text($latest['department'] ?? null),
            'campus'        => $this->text($latest['campus_name'] ?? null),
            'feePlan'       => $this->text($latest['fee_plan'] ?? null),
            'scholarship'   => $this->text($latest['scholarship_type'] ?? null),
            'transport'     => $this->text($latest['transport_enrolled'] ?? null),
            'quota'         => $this->text($latest['Student Quota'] ?? null),
            'attendancePct' => $this->nullableFloat($latest['attendance_pct'] ?? null),
            'examAveragePct' => $this->nullableFloat($latest['exam_average_pct'] ?? null),
            'engagementPct' => $this->nullableFloat($latest['lms_engagement_pct'] ?? null),
            'riskBand'      => $this->text($latest['risk_band'] ?? $latest['risk_level'] ?? null),
        ], static fn ($v) => $v !== null);

        if ($academic !== []) {
            $academic['classesOnRecord'] = count($classValues);
            $academic['sectionsOnRecord'] = count($sectionValues);
        }

        $contact = array_filter([
            'name'  => $this->text($latest['guardian_name'] ?? null),
            'phone' => $this->text($latest['guardian_phone'] ?? null),
            'email' => $this->text($latest['guardian_email'] ?? null),
        ], static fn ($v) => $v !== null);

        return [
            'academic' => $academic === [] ? null : $academic,
            'contact'  => $contact === [] ? null : $contact,
            'finance'  => [
                'currency'     => $currency,
                'records'      => $total,
                'covered'      => $rows->count(),
                // True when the cap bit and the totals below therefore describe
                // the most recent `covered` invoices rather than all of them.
                'partial'      => $rows->count() < $total,
                'billed'       => round($billed, 2),
                'concession'   => round($concession, 2),
                'net'          => round($net, 2),
                'paid'         => round($paid, 2),
                'outstanding'  => round($outstanding, 2),
                'overdue'      => round($overdue, 2),
                'collectedPct' => $net > 0 ? round($paid / $net * 100, 1) : null,
                'statusCounts' => $this->pairs($statusCounts),
                'components'   => array_map(
                    static fn ($name, $value) => ['name' => $name, 'net' => round((float) $value, 2)],
                    array_keys($components),
                    array_values($components)
                ),
                'methods'      => $this->pairs($methods),
                'lastPayment'  => $lastPayment,
                'nextDueDate'  => $nextDue,
                'invoices'     => $invoices,
            ],
        ];
    }

    // ----------------------------------------------------------- intelligence

    /**
     * What the intelligence loop holds about this person.
     *
     * Every block is nullable and every count is real. For the tenants onboarded
     * so far most of these tables are empty, and the honest rendering of that is
     * "no assessment has been recorded", which the UI can only say if this
     * method refuses to substitute zeros.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private function intelligence(string $tenantId, string $pid, array $person, ResolvedSource $source): array
    {
        $capabilities = $this->capabilities($tenantId, $pid, $person, $source);

        $decisions = $this->hasTable('hpbrain_decisions')
            ? DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->where('decided_by', $pid)->get()
            : collect();

        $approved = $decisions->filter(
            static fn ($d) => in_array(strtolower((string) $d->status), ['approved', 'accepted'], true)
        )->count();

        $executions = $this->hasTable('hpbrain_eso_executions')
            ? DB::table('hpbrain_eso_executions')
                ->where('tenant_id', $tenantId)->where('executed_by', $pid)
                ->orderByDesc('created_date')->limit(50)->get()
            : collect();

        $completed = $executions->filter(
            static fn ($e) => in_array(strtolower((string) $e->status), ['completed', 'succeeded', 'success'], true)
        )->count();

        $learnings = $this->hasTable('hpbrain_learnings')
            ? DB::table('hpbrain_learnings')->where('tenant_id', $tenantId)->where('created_by', $pid)->count()
            : 0;

        $signals = $this->signals($tenantId, $pid);

        $overalls = collect($capabilities)->pluck('scores.overall')->filter(static fn ($v) => $v !== null);

        $breakdown = [
            'capabilityScore'  => $overalls->isEmpty() ? null : round(($overalls->avg() / 5) * 100, 1),
            'decisionQuality'  => $decisions->isEmpty() ? null : round($approved / $decisions->count() * 100, 1),
            'executionSuccess' => $executions->isEmpty() ? null : round($completed / $executions->count() * 100, 1),
        ];

        $present = array_values(array_filter($breakdown, static fn ($v) => $v !== null));

        return [
            'capabilities'  => $capabilities,
            'decisions'     => [
                'total'    => $decisions->count(),
                'approved' => $approved,
                'items'    => $decisions->take(20)->map(fn ($d) => [
                    'id'        => (string) $d->id,
                    'status'    => $this->stringOrNull($d->status),
                    'rationale' => $this->stringOrNull($d->rationale),
                    'createdAt' => $this->stringOrNull($d->created_date),
                ])->values()->all(),
            ],
            'executions'    => $executions->map(fn ($e) => [
                'id'            => (string) $e->id,
                'esoId'         => $this->stringOrNull($e->eso_id),
                'status'        => $this->stringOrNull($e->status) ?? 'unknown',
                'completedDate' => $this->stringOrNull($e->completed_date),
                'createdDate'   => $this->stringOrNull($e->created_date),
            ])->values()->all(),
            'executionSuccessCount' => $completed,
            'learnings'     => $learnings,
            'signals'       => $signals['items'],
            'signalCount'   => $signals['total'],
            'evidenceCount' => $signals['evidence'],
            'cases'         => $signals['cases'],
            'score'         => [
                'score'     => $present === [] ? null : round(array_sum($present) / count($present), 1),
                'breakdown' => $breakdown,
            ],
        ];
    }

    /**
     * KASBA proficiency per assigned capability, with the gap against the
     * requirements of the job role this person holds.
     *
     * @param  array<string, mixed>  $person
     * @return array<int, array<string, mixed>>
     */
    private function capabilities(string $tenantId, string $pid, array $person, ResolvedSource $source): array
    {
        if (! $this->hasTable('hpbrain_capability_assignments')) {
            return [];
        }

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenantId)
            ->where('target_type', 'Person')
            ->where('target_id', $pid)
            ->orderBy('assigned_date')
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $proficiency = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenantId)
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->orderByDesc('assessed_date')
            ->get()
            ->groupBy('assignment_id')
            ->map(static fn ($rows) => $rows->first());

        $names = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $assignments->pluck('capability_id')->all())
            ->pluck('name', 'id');

        $positionColumn = $source->has('position') ? $source->field('position') : null;
        $jobRoleId = $positionColumn !== null && $this->blankToNull($person[$positionColumn] ?? null) !== null
            ? (string) $person[$positionColumn]
            : null;

        $requirements = $jobRoleId === null
            ? collect()
            : DB::table('hpbrain_job_role_capability_requirements')
                ->where('tenant_id', $tenantId)->where('job_role_id', $jobRoleId)
                ->get()->keyBy('capability_id');

        return $assignments->map(function ($a) use ($proficiency, $names, $requirements) {
            $p = $proficiency->get($a->id);

            $scores = [];
            $assessed = [];

            foreach (self::KASBA as $dim) {
                $raw = $p->{$dim.'_level'} ?? null;
                $val = $raw === null ? null : (float) $raw;
                $scores[$dim] = $val;
                if ($val !== null) {
                    $assessed[] = $val;
                }
            }

            $scores['overall'] = $assessed === [] ? null : round(array_sum($assessed) / count($assessed), 2);

            $req = $requirements->get($a->capability_id);
            $target = $req ? (float) $req->required_level : null;
            $gaps = [];

            if ($target !== null) {
                foreach (self::KASBA as $dim) {
                    $current = $scores[$dim];
                    if ($current === null || $current < $target) {
                        $gaps[] = [
                            'dimension'    => $dim,
                            'currentLevel' => $current,
                            'targetLevel'  => $target,
                            'gap'          => round($target - ($current ?? 0.0), 2),
                        ];
                    }
                }
            }

            return [
                'capabilityId'    => (string) $a->capability_id,
                'capabilityName'  => (string) ($names[$a->capability_id] ?? $a->capability_id),
                'assignmentId'    => (string) $a->id,
                'capabilityState' => (string) ($p->capability_state ?? 'Unassessed'),
                'scores'          => $scores,
                'gaps'            => $gaps,
                'assessedDate'    => $p->assessed_date ?? null,
            ];
        })->values()->all();

    }

    /**
     * Signals that name this person as their subject, the evidence behind them,
     * and the cases they were escalated into.
     *
     * SUBJECT, NOT SAMPLE. hpbrain_signals.related_entity_id is written only when
     * a rule found exactly one affected row (see SignalSubject); an aggregate
     * about 50 unmanaged departments names no person, and correctly does not
     * appear here.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, evidence: int, cases: array<int, array<string, mixed>>}
     */
    private function signals(string $tenantId, string $pid): array
    {
        $none = ['items' => [], 'total' => 0, 'evidence' => 0, 'cases' => []];

        if (! $this->hasTable('hpbrain_signals')) {
            return $none;
        }

        $signals = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->where('related_entity_type', 'Person')
            ->where('related_entity_id', $pid)
            ->orderByDesc('created_date')
            ->limit(50)
            ->get();

        if ($signals->isEmpty()) {
            return $none;
        }

        $ids = $signals->pluck('id')->all();

        $evidence = $this->hasTable('hpbrain_evidence')
            ? DB::table('hpbrain_evidence')
                ->where('tenant_id', $tenantId)->whereIn('signal_id', $ids)->count()
            : 0;

        $cases = collect();

        if ($this->hasTable('hpbrain_cases')) {
            $caseIds = DB::table('hpbrain_cases')
                ->where('tenant_id', $tenantId)->whereIn('signal_id', $ids)->pluck('id');

            if ($this->hasTable('hpbrain_case_signals')) {
                $caseIds = $caseIds->merge(
                    DB::table('hpbrain_case_signals')
                        ->where('tenant_id', $tenantId)->whereIn('signal_id', $ids)->pluck('case_id')
                );
            }

            $caseIds = $caseIds->filter()->unique()->values();

            if ($caseIds->isNotEmpty()) {
                $cases = DB::table('hpbrain_cases')
                    ->where('tenant_id', $tenantId)->whereIn('id', $caseIds->all())
                    ->orderByDesc('created_date')->limit(25)->get()
                    ->map(fn ($c) => [
                        'id'        => (string) $c->id,
                        'title'     => $this->stringOrNull($c->title),
                        'status'    => $this->stringOrNull($c->status),
                        'createdAt' => $this->stringOrNull($c->created_date),
                    ])->values();
            }
        }

        return [
            'total'    => $signals->count(),
            'evidence' => $evidence,
            'cases'    => $cases->all(),
            'items'    => $signals->map(fn ($s) => [
                'id'             => (string) $s->id,
                'ruleKey'        => $this->stringOrNull($s->rule_key),
                'title'          => $this->signalTitle($s),
                'classification' => $this->stringOrNull($s->classification),
                'severity'       => $this->stringOrNull($s->severity),
                'priority'       => $this->stringOrNull($s->priority),
                'status'         => $this->stringOrNull($s->status),
                'confidence'     => $s->confidence === null ? null : round((float) $s->confidence, 2),
                'source'         => $this->stringOrNull($s->source),
                'createdAt'      => $this->stringOrNull($s->created_date),
            ])->values()->all(),
        ];
    }

    /** A signal's own title if the detector stored one, else its rule, humanized. */
    private function signalTitle(object $signal): ?string
    {
        $metadata = $this->payload($signal->metadata ?? null);

        return $this->text($metadata['title'] ?? null)
            ?? ($this->stringOrNull($signal->rule_key) === null ? null : $this->humanize((string) $signal->rule_key));
    }

    /**
     * Guardians on record for this student, plus the guardian named on the fee
     * export when the dedicated table has no row.
     *
     * hpbrain_guardians is the modelled place for this and is empty for every
     * tenant onboarded so far. The fee export DOES carry a guardian name, phone
     * and email per student, and that is a stored value, not an inference — so it
     * is shown, labelled with where it came from.
     *
     * @param  array<string, mixed>|null  $feeContact
     * @return array<string, mixed>
     */
    private function contacts(string $tenantId, string $pid, ?array $feeContact): array
    {
        $guardians = $this->hasTable('hpbrain_guardians')
            ? DB::table('hpbrain_guardians')
                ->where('tenant_id', $tenantId)->where('student_person_id', $pid)->get()
                ->map(fn ($g) => [
                    'firstName'        => $this->stringOrNull($g->first_name),
                    'lastName'         => $this->stringOrNull($g->last_name),
                    'relationship'     => $this->stringOrNull($g->relationship),
                    'email'            => $this->stringOrNull($g->email),
                    'phone'            => $this->stringOrNull($g->phone),
                    'isPrimaryContact' => (bool) $g->is_primary_contact,
                    'origin'           => 'guardian_register',
                ])->values()->all()
            : [];

        if ($guardians === [] && $feeContact !== null) {
            $guardians[] = [
                'firstName'        => $feeContact['name'] ?? null,
                'lastName'         => null,
                'relationship'     => 'Guardian',
                'email'            => $feeContact['email'] ?? null,
                'phone'            => $feeContact['phone'] ?? null,
                'isPrimaryContact' => true,
                'origin'           => 'fee_record',
            ];
        }

        return ['guardians' => $guardians];
    }

    // --------------------------------------------------------------- timeline

    /**
     * One chronological list, built only from dated rows that already exist.
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $intelligence
     * @param  array<int, array<string, mixed>>  $audit
     * @return array<string, mixed>
     */
    private function timeline(array $identity, array $activity, array $intelligence, array $audit): array
    {
        $events = [];

        /**
         * MONEY IS NOT FORMATTED HERE. An amount baked into `detail` as
         * "INR 16,763.00" reached the screen alongside the same figure rendered
         * as "₹16,763" by the currency formatter every other panel uses, so one
         * timeline row disagreed with the invoice table directly above it. The
         * amount travels as a number and its currency code, and the screen
         * formats it exactly once, in one place.
         */
        $push = function (?string $at, string $kind, string $title, ?string $detail, string $source, ?float $amount = null, ?string $currency = null) use (&$events): void {
            $at = $this->stringOrNull($at);
            if ($at === null) {
                return;
            }
            $events[] = [
                'at'       => $at,
                'kind'     => $kind,
                'title'    => $title,
                'detail'   => $detail,
                'source'   => $source,
                'amount'   => $amount,
                'currency' => $currency,
            ];
        };

        $push(
            $identity['createdDate'] ?? null,
            'record',
            'Person record created',
            $identity['externalRef'] === null ? null : 'Reference '.$identity['externalRef'],
            'Source system'
        );

        if (($identity['updatedDate'] ?? null) !== null && ($identity['updatedDate'] ?? null) !== ($identity['createdDate'] ?? null)) {
            $push($identity['updatedDate'], 'record', 'Person record last updated', null, 'Source system');
        }

        $push($identity['joinedDate'] ?? null, 'record', 'Joining date on record', null, 'Source system');

        foreach ($activity['records'] as $record) {
            $detail = array_values(array_filter(
                [$record['status'], $record['reference']],
                static fn ($v) => $v !== null && $v !== ''
            ));

            $push(
                $record['occurredAt'],
                'operational',
                $record['category'] ?? $record['datasetLabel'],
                $detail === [] ? null : implode(' · ', $detail),
                $record['datasetLabel'].($record['source']['file'] === null ? '' : ' · '.$record['source']['file']),
                $record['amount'],
                $record['currency'],
            );
        }

        foreach ($intelligence['signals'] as $signal) {
            $push(
                $signal['createdAt'],
                'signal',
                $signal['title'] ?? 'Signal detected',
                array_key_exists('severity', $signal) && $signal['severity'] !== null
                    ? ucfirst((string) $signal['severity']).' severity'
                    : null,
                'Signal detection'
            );
        }

        foreach ($intelligence['cases'] as $case) {
            $push($case['createdAt'], 'case', $case['title'] ?? 'Case opened', $case['status'], 'Case management');
        }

        foreach ($intelligence['decisions']['items'] as $decision) {
            $push($decision['createdAt'], 'decision', 'Decision recorded', $decision['status'], 'Decision register');
        }

        foreach ($intelligence['executions'] as $execution) {
            $push($execution['createdDate'], 'execution', 'Execution started', $execution['status'], 'Execution log');
        }

        foreach ($audit as $entry) {
            $push($entry['createdAt'], 'audit', $entry['action'], $entry['actorName'], 'Audit log');
        }

        usort($events, static fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return [
            'events'  => array_slice($events, 0, self::TIMELINE_LIMIT),
            'total'   => count($events),
            // The operational window bounds the timeline too, so a person with
            // 5,000 records has a timeline of the most recent ones. Saying so
            // beats implying the list is their whole history.
            'bounded' => ($activity['total'] ?? 0) > ($activity['shown'] ?? 0),
        ];
    }

    /**
     * Audit entries about this person, or by them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function audit(string $tenantId, string $pid): array
    {
        if (! $this->hasTable('hpbrain_audit_logs')) {
            return [];
        }

        return DB::table('hpbrain_audit_logs')
            ->where('tenant_id', $tenantId)
            ->where(function ($w) use ($pid) {
                $w->where('actor_id', $pid)
                  ->orWhere(fn ($q) => $q->where('entity_type', 'Person')->where('entity_id', $pid));
            })
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn ($a) => [
                'action'     => $this->humanize((string) $a->action),
                'entityType' => $this->stringOrNull($a->entity_type),
                'actorName'  => $this->stringOrNull($a->actor_name),
                'createdAt'  => $this->stringOrNull($a->created_at),
            ])->values()->all();
    }

    // ----------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function payload(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    /** Trimmed string, or null for absent/blank. */
    private function text(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $out = trim((string) $value);

        return $out === '' ? null : $out;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $this->text($value);
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<string, int|float>  $counts
     * @return array<int, array{name: string, count: int}>
     */
    private function pairs(array $counts): array
    {
        $out = [];

        foreach ($counts as $name => $count) {
            $out[] = ['name' => (string) $name, 'count' => (int) $count];
        }

        return $out;
    }

    /**
     * A stored key as a reader's label: 'net_fee_amount' -> 'Net fee amount'.
     *
     * This is the whole of the fix for the debug-looking labels the screen used
     * to print. It is formatting, not translation: the key is still recognisable
     * to someone who knows the export, which is what makes provenance checkable.
     */
    private function humanize(string $value): string
    {
        $spaced = trim(preg_replace('/[\s_\-.]+/', ' ', $value) ?? $value);

        if ($spaced === '') {
            return $value;
        }

        // Already human ('GR NO.', 'Student Name'): leave the author's casing.
        if (preg_match('/[A-Z]/', $value) === 1 && str_contains($value, ' ')) {
            return $spaced;
        }

        return ucfirst(strtolower($spaced));
    }
}
