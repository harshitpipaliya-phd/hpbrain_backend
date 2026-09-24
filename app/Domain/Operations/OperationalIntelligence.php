<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Intelligence\SqlDialect;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * EVERY DERIVED OPERATIONAL FACT ABOUT AN ORGANIZATION, IN A DOZEN AGGREGATES.
 *
 * WHAT PROBLEM THIS SOLVES. `hpbrain_operational_records` is by a wide margin the
 * largest and richest thing this product holds — a quarter of a million rows for a
 * single connected organization — and almost nothing was being read out of it. The
 * screens showed the ROW COUNT and stopped there, so an organization with 225,103
 * records of field work, complaints, sales calls and job orders looked, on every
 * intelligence surface, exactly like an organization with none: a column of zeroes
 * and "No data". The data was never missing. It was never interrogated.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NOTHING HERE IS INVENTED, AND THE DISTINCTION IS THE WHOLE DESIGN.
 *
 * Every figure below is a COUNT, SUM, MIN, MAX or a ratio of two of those, taken
 * over rows the organization's own source systems wrote. No record is created, no
 * value is imputed, no gap is filled with a plausible number. Where a measure
 * cannot be computed — because the source does not carry the field it needs — the
 * measure is published as null with `support` false and a sentence saying which
 * field was absent. A caller may render "Not measurable from connected sources".
 * It may never render 0.
 *
 * That rule is not decoration. A completion rate of 0% and a completion rate that
 * could not be computed look identical on a dashboard and mean opposite things,
 * and only one of them is a finding about the organization.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY IT IS INDUSTRY-AGNOSTIC, GIVEN THAT IT READS LIKE TELECOM ON A TELECOM.
 *
 * There is no branch anywhere in this class on tenant, industry or dataset name.
 * The engine measures the SHAPE of what was imported — does this dataset carry a
 * timestamp, a status, an owner, a subject, a geography — and derives the measures
 * that shape supports. What makes the output read as telecom for a fibre operator
 * and as academic for a school is that the LABELS come from the organization's own
 * data: its dataset names, its status words, its category values, its department
 * register, its zones. A school connecting the same engine gets fee and attendance
 * datasets described in its own vocabulary, from the identical code path.
 *
 * The one piece of shared vocabulary is StatusVocabulary, which maps a status word
 * to one of the four states every workflow has. See that class for why that is a
 * property of workflows rather than of industries, and for what happens to a word
 * it does not recognise.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PERFORMANCE: A FIXED NUMBER OF AGGREGATES FOR AN ORGANIZATION OF ANY SIZE.
 *
 * The obvious implementation — read the records and reduce them in PHP — is
 * unusable here. 225,103 rows each carrying a JSON payload is roughly 600 MB of
 * decoded PHP, and the school tenant is larger again. So NOT ONE ROW OF
 * OPERATIONAL DATA ENTERS PHP. Every measure is a GROUP BY whose result set is
 * bounded by the number of datasets, departments, statuses or months — hundreds of
 * rows, never hundreds of thousands.
 *
 * All but one ride tenant-leading composite indexes (`tenant_id, dataset,
 * <axis>`), so they are index-only scans over ~20-byte entries rather than over
 * rows that carry an inline JSON payload — see fieldSupportPerDataset() for the
 * measurement that forced that restructure. The exception is turnaround, which
 * needs two timestamps no single index carries; it is the reason the result is
 * cached rather than recomputed per request.
 *
 * CACHED AGAINST A FINGERPRINT OF THE DATA, NEVER A CLOCK — the same rule
 * IntelligenceEngine documents at length. `dataVersion()` hashes the row counts
 * and high-water marks of everything read below, so an import invalidates the
 * entry immediately and a quiet week costs nothing. Single-flight, with the
 * previous good answer served (and flagged) to readers who arrive while a
 * recomputation is in progress, because a stale figure that says it is stale beats
 * a spinner and beats a dozen concurrent copies of the same scan.
 */
final class OperationalIntelligence
{
    private const TABLE = 'hpbrain_operational_records';

    /** Bounds memory only. The fingerprint, not this, decides correctness. */
    private const TTL_SECONDS = 21600;

    private const LOCK_SECONDS = 900;

    private const WAIT_SECONDS = 90;

    private const LAST_GOOD_TTL_SECONDS = 604800;

    /** How many months of history the trend series carries. */
    private const TREND_MONTHS = 18;

    /** How many rows each "top N" list carries. */
    private const TOP_N = 8;

    /**
     * A dataset below this many rows is reported but excluded from organization
     * rates.
     *
     * Not a magic threshold on the finding — a threshold on PUBLISHING A RATE. A
     * completion percentage over six rows is arithmetic, not evidence, and putting
     * it beside one computed over fifteen thousand invites a reader to compare
     * them. Small datasets still appear in the roster with their counts.
     */
    private const RATE_FLOOR = 30;

    /** @var array<int, string>|null Every index on the table, read once per request. */
    private ?array $indexNames = null;

    /**
     * Everything, for one organization.
     *
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId, bool $fresh = false): array
    {
        $version = $this->dataVersion($tenantId);
        $key = 'brain:ops:v1:'.$tenantId.':'.$version;
        $store = Cache::store('file');

        if ($fresh) {
            $store->forget($key);
        }

        $hit = $store->get($key);

        if (is_array($hit)) {
            return $hit;
        }

        $lock = $store->lock('brain:ops:lock:'.$tenantId, self::LOCK_SECONDS);

        if ($lock->get()) {
            try {
                return $this->computeOrLastGood($tenantId, $version, $key, $store);
            } finally {
                $lock->release();
            }
        }

        $lastGood = $store->get('brain:ops:last:'.$tenantId);

        if (is_array($lastGood)) {
            $lastGood['stale'] = [
                'isStale' => true,
                'servedVersion' => $lastGood['dataVersion'] ?? null,
                'requestedVersion' => $version,
                'reason' => 'A recomputation is in flight; this is the previous completed answer for this organization.',
            ];

            return $lastGood;
        }

        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $hit = $store->get($key);

            return is_array($hit) ? $hit : $this->computeOrLastGood($tenantId, $version, $key, $store);
        }

        try {
            $hit = $store->get($key);

            return is_array($hit) ? $hit : $this->computeOrLastGood($tenantId, $version, $key, $store);
        } finally {
            $lock->release();
        }
    }

    /**
     * The fingerprint of everything this class reads.
     *
     * Row count and high-water update mark, which together change on any insert,
     * update or delete, and ride `(tenant_id, updated_date)` as an index-only
     * scan. The count of department-attributed rows is a SEPARATE query on a
     * SEPARATE index, and the separation is the whole point: see below.
     */
    public function dataVersion(string $tenantId): string
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 'no-table';
        }

        $row = DB::table(DB::raw($this->from('idx_oprec_tenant_updated')))
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS n, MAX(updated_date) AS high')
            ->first();

        return substr(hash('sha256', ($row->n ?? 0).'|'.($row->high ?? '').'|'.$this->labelledFingerprint($tenantId)), 0, 16);
    }

    /**
     * The count of department-attributed rows, as a fingerprint component.
     *
     * WHY THIS IS IN THE FINGERPRINT. Department attribution is backfilled onto
     * `department_label` after the records already exist, and that write does not
     * necessarily move the row's `updated_date`. A fingerprint made only from
     * count and high-water update time therefore keeps serving the pre-backfill
     * aggregate: no departments, no per-unit work, every department card
     * unscored.
     *
     * WHY IT IS NOT A THIRD COLUMN ON THE QUERY ABOVE. It was, and it cost this
     * screen minutes. One query can only ride one index: adding
     * `COUNT(department_label)` beside `MAX(updated_date)` leaves the optimizer
     * no index carrying both, so it abandons the index-only scan and reads all
     * 700k+ rows off the clustered index, each dragging its inline JSON payload
     * with it. EXPLAIN goes from `type: ref, Using index` to `type: ALL,
     * key: NULL`. Two index-covered queries beat one covered by nothing.
     *
     * WHY THE INDEX IS CHECKED AND NOT ASSUMED. Without
     * `(tenant_id, department_label, ...)` this count is that same full scan, and
     * the fingerprint is the one query here that runs on EVERY request, cache
     * hit included. Where the index has not been created the component is
     * dropped rather than paid for: that installation cannot serve the
     * department aggregate either, so there is no backfill for it to miss.
     */
    private function labelledFingerprint(string $tenantId): string
    {
        if (! Schema::hasColumn(self::TABLE, 'department_label')) {
            return 'no-department-label';
        }

        if (! SqlDialect::isSqlite() && ! $this->hasIndex('idx_oprec_tenant_department_status')) {
            return 'no-department-index';
        }

        $row = DB::table(DB::raw($this->from('idx_oprec_tenant_department_status')))
            ->where('tenant_id', $tenantId)
            ->whereNotNull('department_label')
            ->selectRaw('COUNT(*) AS labelled')
            ->first();

        return (string) ($row->labelled ?? 0);
    }

    /**
     * Compute, or fall back to the last answer that completed.
     *
     * WHY A FAILED COMPUTE MUST NOT REACH THE SCREEN. This aggregate is one
     * expensive pass over the record store, and when it fails — a statement
     * killed, a connection dropped mid-scan, a timeout — the exception used to
     * propagate to the Departments screen, which caught it and rendered an empty
     * metrics payload. Every unit then reported "nothing about this unit can be
     * measured", which is a claim ABOUT THE ORGANIZATION'S DATA made on the
     * strength of a query that never ran. Units that had scored 70% and 79%
     * minutes earlier read as unmeasurable.
     *
     * That is the one failure this class must not have. It publishes findings
     * about what an organization does and does not record, so "I could not
     * compute" and "there is nothing there" have to stay distinguishable. A
     * stale answer, labelled stale, is honest. A blank one is a false finding.
     *
     * So: serve the last completed answer where there is one, marked stale with
     * the reason. Where there is none, return the unavailable payload — which
     * says the aggregate could not be produced — rather than one that looks like
     * a successful measurement of nothing.
     *
     * @return array<string, mixed>
     */
    private function computeOrLastGood(string $tenantId, string $version, string $key, Repository $store): array
    {
        try {
            return $this->computeAndStore($tenantId, $version, $key, $store);
        } catch (Throwable $e) {
            $lastGood = $store->get('brain:ops:last:'.$tenantId);

            if (is_array($lastGood)) {
                $lastGood['stale'] = [
                    'isStale' => true,
                    'servedVersion' => $lastGood['dataVersion'] ?? null,
                    'requestedVersion' => $version,
                    'reason' => 'The recomputation for this organization did not complete, so this is the previous completed answer.',
                ];

                return $lastGood;
            }

            return $this->empty(
                $tenantId,
                $version,
                'Derived operational intelligence could not be computed on this request, and no previous result is held. This says nothing about what the organization records — the aggregation itself did not complete.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function computeAndStore(string $tenantId, string $version, string $key, Repository $store): array
    {
        $hit = $store->get($key);

        if (is_array($hit)) {
            return $hit;
        }

        $computed = $this->compute($tenantId, $version);

        $store->put($key, $computed, self::TTL_SECONDS);
        $store->put('brain:ops:last:'.$tenantId, $computed, self::LAST_GOOD_TTL_SECONDS);

        return $computed;
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(string $tenantId, string $version): array
    {
        $startedAt = microtime(true);

        if (! Schema::hasTable(self::TABLE)) {
            return $this->empty($tenantId, $version, 'This installation has no operational record store.');
        }

        $hasDepartmentColumn = Schema::hasColumn(self::TABLE, 'department_label');

        $fields = $this->fieldSupportPerDataset($tenantId);

        if ($fields === []) {
            return $this->empty($tenantId, $version, 'No operational records have been ingested for this organization.');
        }

        $statusRows = $this->statusPerDatasetDepartment($tenantId, $hasDepartmentColumn);
        $monthRows = $this->monthPerDataset($tenantId);
        $departmentMonthRows = $hasDepartmentColumn ? $this->monthPerDepartment($tenantId) : [];
        $categoryRows = $this->valuePerDataset($tenantId, 'category');
        $zoneRows = $this->valuePerDataset($tenantId, 'zone');
        $turnaround = $this->turnaround($tenantId, $hasDepartmentColumn);
        $recurrence = $this->recurrence($tenantId);

        /*
          FIELD COVERAGE FOR THE GROUPED AXES COMES FROM THE GROUPS THEMSELVES.

          `status`, `category`, `zone` and `department_label` each already have a
          GROUP BY above whose non-null buckets sum to exactly COUNT(column). A
          separate COUNT probe for each would be four more index scans producing
          numbers this code is already holding. `closed_at` has no index at all,
          so its coverage comes from the turnaround query, which had to read both
          timestamps regardless.
        */
        $this->fillGroupedCoverage($fields, $statusRows, $categoryRows, $zoneRows, $turnaround['perDataset']);

        $datasets = $this->buildDatasets($fields, $statusRows, $monthRows, $categoryRows, $zoneRows, $turnaround['perDataset'], $recurrence);
        $departments = $this->buildDepartments($statusRows, $departmentMonthRows, $turnaround['perDepartment']);
        $support = $this->buildSupport($fields, $datasets, $departments, $hasDepartmentColumn);
        $totals = $this->buildTotals($fields, $datasets, $departments, $categoryRows, $zoneRows);
        $execution = $this->buildExecution($datasets);
        $service = $this->buildService($datasets, $recurrence);
        $responsiveness = $this->buildResponsiveness($datasets);
        $trend = $this->buildTrend($monthRows);
        $rankings = $this->buildRankings($datasets, $departments, $categoryRows, $zoneRows);

        return [
            'tenantId' => $tenantId,
            'dataVersion' => $version,
            'computedAt' => gmdate('c'),
            'computeMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'available' => true,
            'reason' => null,
            'support' => $support,
            'totals' => $totals,
            'execution' => $execution,
            'service' => $service,
            'responsiveness' => $responsiveness,
            'trend' => $trend,
            'datasets' => array_values($datasets),
            'departments' => array_values($departments),
            'rankings' => $rankings,
            'derivation' => [
                'method' => 'Every figure is a COUNT, SUM, MIN, MAX or a ratio of two of those, computed by SQL aggregation over this organization\'s own imported rows.',
                'fabrication' => 'No record, transaction, person or measurement was created. A measure the source data cannot support is published as null with the reason, never as zero.',
                'llm' => 'No language model contributed to any figure, ranking, classification or conclusion in this response.',
                'scope' => 'Every query is filtered to tenant_id = '.$tenantId.'. No aggregate crosses organizations.',
                'liveness' => 'Cached against a fingerprint of the source rows rather than a clock, so an import invalidates it immediately.',
            ],
        ];
    }

    /* ───────────────────────── the eight queries ───────────────────────── */

    /**
     * WHICH FIELDS EACH DATASET ACTUALLY CARRIES — the probe everything else
     * depends on.
     *
     * A conditional count per column rather than a sample of rows, because "does
     * this dataset have closing timestamps" is a question about the whole
     * population: a dataset where 4% of rows carry `closed_at` cannot support a
     * turnaround figure, and no sample of ten rows would reveal that.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ONE PROBE PER INDEX, NOT ONE QUERY WITH FOURTEEN COUNTS. MEASURED.
     *
     * The obvious version of this is a single GROUP BY with a COUNT(col) per
     * column, and it is a trap. No index carries all fourteen columns, so the
     * optimiser walks the CLUSTERED index — and the clustered index is where the
     * `payload` longtext lives. InnoDB keeps a payload of this size inline, so
     * counting a 64-character status column means dragging roughly half a
     * gigabyte of JSON through the buffer pool to look at a field beside it.
     *
     * Observed on the live database, one tenant, 225,103 rows:
     *
     *   single GROUP BY, fourteen COUNT(col) ......... 460s and still running
     *   the same facts from index-covered probes ........... seconds, in total
     *
     * Each probe below names the tenant-leading composite that already covers
     * its column, so the scan is over ~20-byte index entries instead of over the
     * rows. FORCE INDEX is used rather than left to the optimiser, because the
     * optimiser's own estimate is what chose the clustered scan.
     *
     * TWO PROBES WERE REMOVED RATHER THAN MADE SLOW. `closed_at` and `quantity`
     * have no index, so probing them would reintroduce exactly the scan this
     * restructure removes. Closure support is taken from the turnaround query,
     * which reads both timestamps anyway and reports how many rows it measured;
     * `quantity` appeared in the published field map and fed no measure at all,
     * so it is simply gone.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldSupportPerDataset(string $tenantId): array
    {
        $out = [];

        // The population and the timeline, off (tenant_id, dataset, occurred_at).
        foreach ($this->probe(
            $tenantId,
            'idx_oprec_tenant_dataset_occurred',
            'dataset, COUNT(*) AS records, COUNT(occurred_at) AS f_occurred, MIN(occurred_at) AS earliest, MAX(occurred_at) AS latest',
        ) as $row) {
            $out[(string) $row->dataset] = (array) $row;
        }

        if ($out === []) {
            return [];
        }

        // Defaults first, so every dataset carries the same shape whether or not
        // a given probe returned a row for it.
        foreach ($out as $dataset => $row) {
            $out[$dataset] += [
                'f_status' => 0, 'f_category' => 0, 'f_zone' => 0, 'f_department' => 0,
                'f_closed' => 0, 'f_sub_category' => 0, 'f_supervisor' => 0, 'f_area' => 0,
                'f_metric' => 0, 'f_owner' => 0, 'f_subject' => 0,
                'distinct_owners' => 0, 'distinct_subjects' => 0,
            ];
        }

        // Each remaining single-column probe rides the composite covering it.
        $probes = [
            'f_sub_category' => ['idx_oprec_tenant_dataset_sub_category', 'COUNT(sub_category)'],
            'f_supervisor'   => ['idx_oprec_tenant_dataset_supervisor_name', 'COUNT(supervisor_name)'],
            'f_area'         => ['idx_oprec_tenant_dataset_area', 'COUNT(area)'],
            'f_metric'       => ['idx_oprec_tenant_dataset_metric_value', 'COUNT(metric_value)'],
        ];

        foreach ($probes as $key => [$index, $expression]) {
            foreach ($this->probe($tenantId, $index, 'dataset, '.$expression.' AS value') as $row) {
                $dataset = (string) $row->dataset;

                if (isset($out[$dataset])) {
                    $out[$dataset][$key] = (int) $row->value;
                }
            }
        }

        // Actors and subjects need coverage AND a distinct count; one index
        // serves both, and a COUNT(DISTINCT) over an index it leads is cheap.
        foreach ($this->probe(
            $tenantId,
            'idx_oprec_tenant_dataset_owner',
            'dataset, COUNT(owner_name) AS n, COUNT(DISTINCT owner_name) AS d',
        ) as $row) {
            $dataset = (string) $row->dataset;

            if (isset($out[$dataset])) {
                $out[$dataset]['f_owner'] = (int) $row->n;
                $out[$dataset]['distinct_owners'] = (int) $row->d;
            }
        }

        foreach ($this->probe(
            $tenantId,
            'idx_oprec_tenant_dataset_subject',
            'dataset, COUNT(subject_ref) AS n, COUNT(DISTINCT subject_ref) AS d',
        ) as $row) {
            $dataset = (string) $row->dataset;

            if (isset($out[$dataset])) {
                $out[$dataset]['f_subject'] = (int) $row->n;
                $out[$dataset]['distinct_subjects'] = (int) $row->d;
            }
        }

        /*
          THE TENANT'S FRESHNESS, ONCE, NOT PER DATASET. `updated_date` is
          covered by (tenant_id, updated_date) with no dataset in it, so a
          per-dataset high-water mark would be another clustered scan for a
          figure the screen shows once.
        */
        $ingested = DB::table(self::TABLE)->where('tenant_id', $tenantId)->max('updated_date');

        foreach ($out as $dataset => $row) {
            $out[$dataset]['ingested'] = $ingested;
        }

        return $out;
    }

    /**
     * One index-covered aggregate over the tenant's slice, grouped by dataset.
     *
     * FORCE INDEX is applied only on MySQL/MariaDB and only when the index is
     * actually present: SQLite, which the test suite runs on, neither needs nor
     * understands the hint, and naming an index that does not exist is a fatal
     * error rather than a hint the engine ignores.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function probe(string $tenantId, string $index, string $select): \Illuminate\Support\Collection
    {
        return DB::table(DB::raw($this->from($index)))
            ->where('tenant_id', $tenantId)
            ->selectRaw($select)
            ->groupBy('dataset')
            ->get();
    }

    /**
     * The status ledger, split by dataset and by owning unit in one pass.
     *
     * COVERED BY (tenant_id, department_label, status, dataset) WHERE THAT INDEX
     * EXISTS. Grouping on three columns one index carries in order is an
     * index-only scan; the same GROUP BY against (tenant_id, dataset,
     * department_label) would have to fetch `status` from the row and would be
     * back on the clustered index, which is the expensive path this class avoids
     * everywhere else. Where the department column has not been added yet, the
     * two-column form rides (tenant_id, dataset, status) instead and departments
     * are reported as unsupported.
     *
     * @return array<int, array<string, mixed>>
     */
    private function statusPerDatasetDepartment(string $tenantId, bool $hasDepartment): array
    {
        if (! $hasDepartment) {
            return $this->probeRaw(
                $tenantId,
                'idx_oprec_tenant_dataset_status',
                'dataset, status, COUNT(*) AS records',
                ['dataset', 'status'],
            );
        }

        return $this->probeRaw(
            $tenantId,
            'idx_oprec_tenant_department_status',
            'dataset, department_label, status, COUNT(*) AS records',
            ['department_label', 'status', 'dataset'],
        );
    }

    /**
     * An index-covered GROUP BY over arbitrary columns.
     *
     * @param  array<int, string>  $groupBy
     * @return array<int, array<string, mixed>>
     */
    private function probeRaw(string $tenantId, string $index, string $select, array $groupBy): array
    {
        return DB::table(DB::raw($this->from($index)))
            ->where('tenant_id', $tenantId)
            ->selectRaw($select)
            ->groupBy($groupBy)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** The FROM clause, with the index hint only where it is valid and present. */
    private function from(string $index): string
    {
        return SqlDialect::isSqlite() || ! $this->hasIndex($index)
            ? self::TABLE
            : self::TABLE.' FORCE INDEX ('.$index.')';
    }

    /**
     * Whether an index exists, so a hint cannot turn a missing index into a
     * fatal query.
     *
     * The department indexes arrive with a migration a given installation may
     * not have run yet.
     *
     * READ AS ONE LIST, NOT ONE PROBE PER NAME. `dataVersion` asks about two
     * indexes and runs on every request, cache hit included; against a database
     * on the far side of a network each probe is another round trip spent
     * deciding how to ask the real question. The table's index names are one
     * small query, and there is no case where knowing one of them is worth a
     * round trip but knowing all of them is not. Memoised per instance, which
     * is per request.
     */
    private function hasIndex(string $name): bool
    {
        if (SqlDialect::isSqlite()) {
            return false;
        }

        if ($this->indexNames === null) {
            $this->indexNames = array_map(
                static fn ($row) => (string) $row->INDEX_NAME,
                DB::select(
                    'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [self::TABLE],
                ),
            );
        }

        return in_array($name, $this->indexNames, true);
    }

    /**
     * Volume per calendar month per dataset. Rides `(tenant_id, dataset, occurred_at)`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function monthPerDataset(string $tenantId): array
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('occurred_at')
            ->selectRaw('dataset, '.SqlDialect::yearMonth('occurred_at').' AS period, COUNT(*) AS records')
            ->groupBy('dataset', 'period')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Volume per calendar month per owning unit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function monthPerDepartment(string $tenantId): array
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('occurred_at')
            ->whereNotNull('department_label')
            ->selectRaw('department_label, '.SqlDialect::yearMonth('occurred_at').' AS period, COUNT(*) AS records')
            ->groupBy('department_label', 'period')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Distribution of one classifier column, per dataset. Index-covered.
     *
     * @return array<int, array<string, mixed>>
     */
    private function valuePerDataset(string $tenantId, string $column): array
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereNotNull($column)
            ->selectRaw('dataset, '.$column.' AS value, COUNT(*) AS records')
            ->groupBy('dataset', $column)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * How long closed work took, per dataset and per owning unit.
     *
     * `closed_at > occurred_at` IS A CORRECTNESS FILTER, NOT A TIDINESS ONE. Many
     * source exports set the closing timestamp equal to the creation timestamp for
     * records that were never worked — a row created and closed in the same
     * millisecond is a bookkeeping artefact, not a two-millisecond resolution. And
     * a `closed_at` BEFORE `occurred_at` is a source data error which, averaged in,
     * silently pulls the mean negative. Both are excluded, and the count that
     * survived is published beside the average so a reader can see how much of the
     * dataset the figure speaks for.
     *
     * @return array{perDataset: array<string, array<string, mixed>>, perDepartment: array<string, array<string, mixed>>}
     */
    private function turnaround(string $tenantId, bool $hasDepartment): array
    {
        $seconds = SqlDialect::secondsBetween('occurred_at', 'closed_at');

        $base = fn () => DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('occurred_at')
            ->whereNotNull('closed_at')
            ->whereRaw('closed_at > occurred_at');

        $perDataset = [];

        foreach ($base()
            ->selectRaw('dataset, COUNT(*) AS measured, AVG('.$seconds.') AS avg_seconds, MIN('.$seconds.') AS min_seconds, MAX('.$seconds.') AS max_seconds')
            ->selectRaw('SUM(CASE WHEN '.$seconds.' <= 86400 THEN 1 ELSE 0 END) AS within_day')
            ->groupBy('dataset')
            ->get() as $row) {
            $perDataset[(string) $row->dataset] = (array) $row;
        }

        $perDepartment = [];

        if ($hasDepartment) {
            foreach ($base()
                ->whereNotNull('department_label')
                ->selectRaw('department_label, COUNT(*) AS measured, AVG('.$seconds.') AS avg_seconds')
                ->selectRaw('SUM(CASE WHEN '.$seconds.' <= 86400 THEN 1 ELSE 0 END) AS within_day')
                ->groupBy('department_label')
                ->get() as $row) {
                $perDepartment[(string) $row->department_label] = (array) $row;
            }
        }

        return ['perDataset' => $perDataset, 'perDepartment' => $perDepartment];
    }

    /**
     * How often the same subject appears more than once in a dataset.
     *
     * THE ONE MEASURE THAT NEEDS A SUBQUERY, and it is worth it. For a complaint
     * dataset this is the repeat-complaint rate — the single most diagnostic
     * service figure a support organization has, because a first-time fault and
     * the fourth visit to the same connection are entirely different findings. For
     * a job dataset it is rework. The engine does not name it either of those
     * things: it reports "subjects appearing more than once", and the dataset's own
     * label tells the reader what a subject is.
     *
     * AGGREGATED INSIDE THE DATABASE. The inner query produces one row per subject
     * — 251,987 of them on the largest organization — and the outer collapses that
     * to one row per dataset before anything crosses the wire.
     *
     * @return array<string, array<string, mixed>>
     */
    private function recurrence(string $tenantId): array
    {
        $inner = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('subject_ref')
            ->selectRaw('dataset, subject_ref, COUNT(*) AS appearances')
            ->groupBy('dataset', 'subject_ref');

        $rows = DB::query()
            ->fromSub($inner, 's')
            ->selectRaw('dataset, COUNT(*) AS subjects, SUM(CASE WHEN appearances > 1 THEN 1 ELSE 0 END) AS repeated, MAX(appearances) AS worst, SUM(appearances) AS records')
            ->groupBy('dataset')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->dataset] = (array) $row;
        }

        return $out;
    }

    /* ───────────────────────── derivation in PHP ───────────────────────── */

    /**
     * Sum the grouped result sets back into per-dataset field coverage.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<int, array<string, mixed>>  $statusRows
     * @param  array<int, array<string, mixed>>  $categoryRows
     * @param  array<int, array<string, mixed>>  $zoneRows
     * @param  array<string, array<string, mixed>>  $turnaround
     */
    private function fillGroupedCoverage(array &$fields, array $statusRows, array $categoryRows, array $zoneRows, array $turnaround): void
    {
        foreach ($statusRows as $row) {
            $dataset = (string) $row['dataset'];

            if (! isset($fields[$dataset])) {
                continue;
            }

            $count = (int) $row['records'];

            // A NULL status bucket is rows with no status, which is exactly what
            // COUNT(status) excludes.
            if (($row['status'] ?? null) !== null && (string) $row['status'] !== '') {
                $fields[$dataset]['f_status'] += $count;
            }

            if (array_key_exists('department_label', $row) && $row['department_label'] !== null && (string) $row['department_label'] !== '') {
                $fields[$dataset]['f_department'] += $count;
            }
        }

        foreach ($categoryRows as $row) {
            $dataset = (string) $row['dataset'];

            if (isset($fields[$dataset])) {
                $fields[$dataset]['f_category'] += (int) $row['records'];
            }
        }

        foreach ($zoneRows as $row) {
            $dataset = (string) $row['dataset'];

            if (isset($fields[$dataset])) {
                $fields[$dataset]['f_zone'] += (int) $row['records'];
            }
        }

        foreach ($turnaround as $dataset => $row) {
            if (isset($fields[$dataset])) {
                $fields[$dataset]['f_closed'] = (int) $row['measured'];
            }
        }
    }

    /**
     * One entry per dataset, carrying only measures its own fields support.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<int, array<string, mixed>>  $statusRows
     * @param  array<int, array<string, mixed>>  $monthRows
     * @param  array<int, array<string, mixed>>  $categoryRows
     * @param  array<int, array<string, mixed>>  $zoneRows
     * @param  array<string, array<string, mixed>>  $turnaround
     * @param  array<string, array<string, mixed>>  $recurrence
     * @return array<string, array<string, mixed>>
     */
    private function buildDatasets(array $fields, array $statusRows, array $monthRows, array $categoryRows, array $zoneRows, array $turnaround, array $recurrence): array
    {
        $statesByDataset = [];
        $rawStatusByDataset = [];

        foreach ($statusRows as $row) {
            $dataset = (string) $row['dataset'];
            $count = (int) $row['records'];
            $state = StatusVocabulary::classify($row['status'] ?? null);

            $statesByDataset[$dataset][$state] = ($statesByDataset[$dataset][$state] ?? 0) + $count;

            $label = (string) ($row['status'] ?? '');

            if ($label !== '') {
                $rawStatusByDataset[$dataset][$label] = ($rawStatusByDataset[$dataset][$label] ?? 0) + $count;
            }
        }

        $monthsByDataset = [];

        foreach ($monthRows as $row) {
            $monthsByDataset[(string) $row['dataset']][(string) $row['period']] = (int) $row['records'];
        }

        $categoriesByDataset = $this->groupValues($categoryRows);
        $zonesByDataset = $this->groupValues($zoneRows);

        $out = [];

        foreach ($fields as $dataset => $f) {
            $records = (int) $f['records'];
            $states = $statesByDataset[$dataset] ?? [];
            $classified = array_sum($states) - ($states[StatusVocabulary::UNKNOWN] ?? 0);

            $completed = (int) ($states[StatusVocabulary::COMPLETED] ?? 0);
            $cancelled = (int) ($states[StatusVocabulary::CANCELLED] ?? 0);
            $progress = (int) ($states[StatusVocabulary::PROGRESS] ?? 0);
            $open = (int) ($states[StatusVocabulary::OPEN] ?? 0);

            $months = $monthsByDataset[$dataset] ?? [];
            ksort($months);

            $categories = $categoriesByDataset[$dataset] ?? [];
            $zones = $zonesByDataset[$dataset] ?? [];
            $turn = $turnaround[$dataset] ?? null;
            $rec = $recurrence[$dataset] ?? null;

            /*
              RATES ARE PUBLISHED ONLY WHERE THEY MEAN SOMETHING.

              Three independent gates, and each failure is reported with its own
              reason rather than collapsing to a single "no data":

                - the dataset carries no status column at all
                - it carries one, but no word in it was recognisable
                - it is smaller than the floor a published percentage deserves
            */
            /*
              AND A FOURTH GATE: A WORKFLOW HAS TO HAVE FINISHED SOMETHING.

              A dataset where not one classified record has reached a terminal
              state is almost never a workflow — it is a master table carrying an
              entity status. A customer register whose rows say "Registered" or
              "Pending" resolves cleanly against the vocabulary and would
              contribute tens of thousands of records at 0% complete, dragging
              the organization's headline completion rate down by an arbitrary
              amount that describes nothing about how the organization delivers.

              "Completion cannot be measured here" is the correct answer for such
              a dataset, and it is the same answer this engine gives everywhere
              else it cannot measure something. The cost is that a genuine
              workflow with a total backlog and no closure yet is also excluded —
              which is the right way round: that dataset has no completion to
              report either, and its volume still appears everywhere else.
            */
            $terminal = $completed + $cancelled;

            $rateSupport = $classified > 0 && $records >= self::RATE_FLOOR && $terminal > 0;

            $out[$dataset] = [
                'dataset' => $dataset,
                'label' => $this->humanise($dataset),
                'records' => $records,
                'share' => null, // filled by buildTotals once the denominator is known
                'earliest' => $this->date($f['earliest'] ?? null),
                'latest' => $this->date($f['latest'] ?? null),
                'lastIngestedAt' => $this->date($f['ingested'] ?? null),
                'spanDays' => $this->spanDays($f['earliest'] ?? null, $f['latest'] ?? null),
                'fields' => [
                    'timeline' => (int) $f['f_occurred'] > 0,
                    'closure' => (int) $f['f_closed'] > 0,
                    'status' => (int) $f['f_status'] > 0,
                    'category' => (int) $f['f_category'] > 0,
                    'subCategory' => (int) $f['f_sub_category'] > 0,
                    'owner' => (int) $f['f_owner'] > 0,
                    'supervisor' => (int) $f['f_supervisor'] > 0,
                    'geography' => (int) $f['f_zone'] > 0 || (int) $f['f_area'] > 0,
                    'subject' => (int) $f['f_subject'] > 0,
                    'measure' => (int) $f['f_metric'] > 0,
                    'department' => (int) $f['f_department'] > 0,
                ],
                'execution' => [
                    'supported' => $rateSupport,
                    'reason' => $rateSupport ? null : $this->executionReason($f, (int) $classified, $records, $terminal),
                    'completed' => $completed,
                    'inProgress' => $progress,
                    'open' => $open,
                    'cancelled' => $cancelled,
                    'classified' => (int) $classified,
                    'unclassified' => (int) ($states[StatusVocabulary::UNKNOWN] ?? 0),
                    'classifiedShare' => $records > 0 ? round($classified / $records, 4) : null,
                    'completionRate' => $rateSupport ? $this->rate($completed, $classified) : null,
                    'openRate' => $rateSupport ? $this->rate($open + $progress, $classified) : null,
                    'cancellationRate' => $rateSupport ? $this->rate($cancelled, $classified) : null,
                    'backlog' => $rateSupport ? $open + $progress : null,
                    'statuses' => $this->topOf($rawStatusByDataset[$dataset] ?? [], self::TOP_N, $records),
                ],
                'turnaround' => $turn === null ? [
                    'supported' => false,
                    'reason' => (int) $f['f_closed'] === 0
                        ? 'This dataset carries no closing timestamp, so time-to-close cannot be measured.'
                        : 'No record in this dataset closes after it opens, so elapsed time cannot be measured.',
                    'measured' => 0,
                    'averageHours' => null,
                    'withinDayRate' => null,
                ] : [
                    'supported' => true,
                    'reason' => null,
                    'measured' => (int) $turn['measured'],
                    'coverage' => $records > 0 ? round(((int) $turn['measured']) / $records, 4) : null,
                    'averageHours' => round(((float) $turn['avg_seconds']) / 3600, 2),
                    'fastestHours' => round(((float) $turn['min_seconds']) / 3600, 2),
                    'slowestHours' => round(((float) $turn['max_seconds']) / 3600, 2),
                    'withinDayRate' => $this->rate((int) $turn['within_day'], (int) $turn['measured']),
                ],
                'recurrence' => $rec === null || (int) $rec['subjects'] === 0 ? [
                    'supported' => false,
                    'reason' => 'This dataset carries no subject reference, so repeat activity against the same subject cannot be measured.',
                    'subjects' => 0,
                    'repeatRate' => null,
                ] : [
                    'supported' => true,
                    'reason' => null,
                    'subjects' => (int) $rec['subjects'],
                    'repeated' => (int) $rec['repeated'],
                    'repeatRate' => $this->rate((int) $rec['repeated'], (int) $rec['subjects']),
                    'worstSubjectAppearances' => (int) $rec['worst'],
                    'recordsPerSubject' => (int) $rec['subjects'] > 0
                        ? round(((int) $rec['records']) / ((int) $rec['subjects']), 2)
                        : null,
                ],
                'categories' => $this->topOf($categories, self::TOP_N, array_sum($categories)),
                'categoryConcentration' => $this->concentration($categories),
                'zones' => $this->topOf($zones, self::TOP_N, array_sum($zones)),
                'actors' => [
                    'distinct' => (int) ($f['distinct_owners'] ?? 0),
                    'recordsPerActor' => (int) ($f['distinct_owners'] ?? 0) > 0
                        ? round($records / (int) $f['distinct_owners'], 1)
                        : null,
                ],
                'subjects' => [
                    'distinct' => (int) ($f['distinct_subjects'] ?? 0),
                ],
                'trend' => $this->series($months),
                'momentum' => $this->momentum($months),
            ];
        }

        uasort($out, fn ($a, $b) => $b['records'] <=> $a['records']);

        return $out;
    }

    /**
     * Why a dataset's execution rates were suppressed. One sentence, specific.
     *
     * @param  array<string, mixed>  $fields
     */
    private function executionReason(array $fields, int $classified, int $records, int $terminal = 0): string
    {
        if ((int) $fields['f_status'] === 0) {
            return 'This dataset carries no status field, so completion cannot be measured.';
        }

        if ($classified === 0) {
            return 'None of this dataset\'s status values map to a recognised workflow state, so completion is not derived rather than guessed.';
        }

        if ($records < self::RATE_FLOOR) {
            return 'Only '.$records.' records — below the threshold at which a published percentage is meaningful.';
        }

        return 'No record in this dataset has reached a completed or cancelled state, so its status field describes what something IS rather than how work progressed. Completion is not derived from it.';
    }

    /**
     * One entry per owning unit named by the source data.
     *
     * @param  array<int, array<string, mixed>>  $statusRows
     * @param  array<int, array<string, mixed>>  $monthRows
     * @param  array<string, array<string, mixed>>  $turnaround
     * @return array<string, array<string, mixed>>
     */
    private function buildDepartments(array $statusRows, array $monthRows, array $turnaround): array
    {
        $byDepartment = [];

        foreach ($statusRows as $row) {
            if (! array_key_exists('department_label', $row)) {
                return [];
            }

            $label = $row['department_label'];

            // NULL is "the source did not say", and must not become a department.
            if ($label === null || (string) $label === '') {
                continue;
            }

            $label = (string) $label;
            $count = (int) $row['records'];
            $state = StatusVocabulary::classify($row['status'] ?? null);
            $dataset = (string) $row['dataset'];

            $byDepartment[$label] ??= [
                'label' => $label,
                'records' => 0,
                'states' => [],
                'datasets' => [],
            ];

            $byDepartment[$label]['records'] += $count;
            $byDepartment[$label]['states'][$state] = ($byDepartment[$label]['states'][$state] ?? 0) + $count;
            $byDepartment[$label]['datasets'][$dataset] = ($byDepartment[$label]['datasets'][$dataset] ?? 0) + $count;
        }

        $monthsByDepartment = [];

        foreach ($monthRows as $row) {
            $monthsByDepartment[(string) $row['department_label']][(string) $row['period']] = (int) $row['records'];
        }

        $total = array_sum(array_column($byDepartment, 'records'));
        $out = [];

        foreach ($byDepartment as $label => $d) {
            $states = $d['states'];
            $classified = array_sum($states) - ($states[StatusVocabulary::UNKNOWN] ?? 0);
            $completed = (int) ($states[StatusVocabulary::COMPLETED] ?? 0);
            $cancelled = (int) ($states[StatusVocabulary::CANCELLED] ?? 0);
            $backlog = (int) ($states[StatusVocabulary::OPEN] ?? 0) + (int) ($states[StatusVocabulary::PROGRESS] ?? 0);

            $months = $monthsByDepartment[$label] ?? [];
            ksort($months);

            $turn = $turnaround[$label] ?? null;
            $datasets = $d['datasets'];
            arsort($datasets);

            $out[$label] = [
                'label' => $label,
                'records' => $d['records'],
                'share' => $total > 0 ? round($d['records'] / $total, 4) : null,
                'datasets' => count($datasets),
                'primaryDataset' => (string) array_key_first($datasets),
                'datasetBreakdown' => $this->topOf($datasets, self::TOP_N, $d['records']),
                'completed' => $completed,
                'cancelled' => $cancelled,
                'backlog' => $backlog,
                'classified' => (int) $classified,
                'completionRate' => $classified >= self::RATE_FLOOR ? $this->rate($completed, $classified) : null,
                'cancellationRate' => $classified >= self::RATE_FLOOR ? $this->rate($cancelled, $classified) : null,
                'completionSupported' => $classified >= self::RATE_FLOOR,
                'averageTurnaroundHours' => $turn === null ? null : round(((float) $turn['avg_seconds']) / 3600, 2),
                'turnaroundMeasured' => $turn === null ? 0 : (int) $turn['measured'],
                'trend' => $this->series($months),
                'momentum' => $this->momentum($months),
            ];
        }

        uasort($out, fn ($a, $b) => $b['records'] <=> $a['records']);

        $rank = 0;

        foreach ($out as $label => $_) {
            $out[$label]['rank'] = ++$rank;
            $out[$label]['of'] = count($out);
        }

        return $out;
    }

    /**
     * Which families of measure this ORGANIZATION can support at all.
     *
     * The flag a screen reads before deciding whether to render a panel or an
     * explanation. See the class docblock for why a false here must never be
     * rendered as a zero.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, array<string, mixed>>  $datasets
     * @param  array<string, array<string, mixed>>  $departments
     * @return array<string, mixed>
     */
    private function buildSupport(array $fields, array $datasets, array $departments, bool $hasDepartmentColumn): array
    {
        $any = fn (string $key) => array_sum(array_map(fn ($f) => (int) ($f[$key] ?? 0), $fields)) > 0;

        return [
            'records' => $fields !== [],
            'timeline' => $any('f_occurred'),
            'closure' => $any('f_closed'),
            'status' => $any('f_status'),
            'execution' => array_sum(array_map(fn ($d) => $d['execution']['supported'] ? 1 : 0, $datasets)) > 0,
            'category' => $any('f_category'),
            'geography' => $any('f_zone') || $any('f_area'),
            'actor' => $any('f_owner'),
            'subject' => $any('f_subject'),
            'measure' => $any('f_metric'),
            'department' => $departments !== [],
            'recurrence' => array_sum(array_map(fn ($d) => $d['recurrence']['supported'] ? 1 : 0, $datasets)) > 0,
            'turnaround' => array_sum(array_map(fn ($d) => $d['turnaround']['supported'] ? 1 : 0, $datasets)) > 0,
            'reasons' => [
                'department' => $departments !== []
                    ? null
                    : ($hasDepartmentColumn
                        ? 'No imported record names an owning unit, so operational work cannot be attributed to a department.'
                        : 'This installation predates department attribution on operational records.'),
                'closure' => $any('f_closed') ? null : 'No imported record carries a closing timestamp, so turnaround cannot be measured.',
                'measure' => $any('f_metric') ? null : 'No imported record carries a numeric measurement, so value-weighted analysis is not available.',
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, array<string, mixed>>  $datasets
     * @param  array<string, array<string, mixed>>  $departments
     * @param  array<int, array<string, mixed>>  $categoryRows
     * @param  array<int, array<string, mixed>>  $zoneRows
     * @return array<string, mixed>
     */
    private function buildTotals(array $fields, array &$datasets, array $departments, array $categoryRows, array $zoneRows): array
    {
        $records = array_sum(array_map(fn ($f) => (int) $f['records'], $fields));

        foreach ($datasets as $key => $d) {
            $datasets[$key]['share'] = $records > 0 ? round($d['records'] / $records, 4) : null;
        }

        $earliest = null;
        $latest = null;

        foreach ($fields as $f) {
            $e = $this->date($f['earliest'] ?? null);
            $l = $this->date($f['latest'] ?? null);

            if ($e !== null && ($earliest === null || $e < $earliest)) {
                $earliest = $e;
            }

            if ($l !== null && ($latest === null || $l > $latest)) {
                $latest = $l;
            }
        }

        $categories = [];

        foreach ($categoryRows as $row) {
            $categories[(string) $row['value']] = true;
        }

        $zones = [];

        foreach ($zoneRows as $row) {
            $zones[(string) $row['value']] = true;
        }

        return [
            'records' => $records,
            'datasets' => count($datasets),
            'departmentsWithActivity' => count($departments),
            'distinctCategories' => count($categories),
            'distinctZones' => count($zones),
            /*
              NAMED FOR WHAT IT IS. Distinct actors cannot be summed across
              datasets — the same engineer appears in several — and this engine
              deliberately holds no organization-wide DISTINCT over a
              quarter-million-row column, because that is a temp-table sort with
              no index behind it. So the honest figure is the largest count any
              single dataset carries, and the key says so rather than implying an
              organization total the number is not.
            */
            'largestActorPoolInADataset' => max(array_map(fn ($f) => (int) ($f['distinct_owners'] ?? 0), $fields) ?: [0]),
            'earliest' => $earliest,
            'latest' => $latest,
            'spanDays' => $this->spanDays($earliest, $latest),
            'largestDataset' => $datasets === [] ? null : reset($datasets)['label'],
        ];
    }

    /**
     * Organization-wide execution, weighted by the volume each dataset carries.
     *
     * WEIGHTED, NOT AVERAGED ACROSS DATASETS. A mean of per-dataset completion
     * rates lets a 40-row reference table pull the organization's headline figure
     * as hard as a 15,000-row complaint queue. The denominator here is classified
     * RECORDS, so each dataset contributes exactly the work it represents.
     *
     * @param  array<string, array<string, mixed>>  $datasets
     * @return array<string, mixed>
     */
    private function buildExecution(array $datasets): array
    {
        $completed = 0;
        $cancelled = 0;
        $open = 0;
        $progress = 0;
        $classified = 0;
        $contributing = [];

        foreach ($datasets as $d) {
            if (! $d['execution']['supported']) {
                continue;
            }

            $completed += $d['execution']['completed'];
            $cancelled += $d['execution']['cancelled'];
            $open += $d['execution']['open'];
            $progress += $d['execution']['inProgress'];
            $classified += $d['execution']['classified'];
            $contributing[] = $d['label'];
        }

        $supported = $classified > 0;

        return [
            'supported' => $supported,
            'reason' => $supported ? null : 'No dataset in this organization carries a status vocabulary this engine can resolve, so completion is not derived.',
            'completed' => $completed,
            'inProgress' => $progress,
            'open' => $open,
            'cancelled' => $cancelled,
            'backlog' => $open + $progress,
            'classified' => $classified,
            'completionRate' => $supported ? $this->rate($completed, $classified) : null,
            'backlogRate' => $supported ? $this->rate($open + $progress, $classified) : null,
            'cancellationRate' => $supported ? $this->rate($cancelled, $classified) : null,
            'contributingDatasets' => $contributing,
        ];
    }

    /**
     * Repeat activity across every dataset that can express it.
     *
     * @param  array<string, array<string, mixed>>  $datasets
     * @param  array<string, array<string, mixed>>  $recurrence
     * @return array<string, mixed>
     */
    private function buildService(array $datasets, array $recurrence): array
    {
        $subjects = 0;
        $repeated = 0;
        $worst = null;

        foreach ($datasets as $d) {
            if (! $d['recurrence']['supported']) {
                continue;
            }

            $subjects += $d['recurrence']['subjects'];
            $repeated += $d['recurrence']['repeated'];

            if ($worst === null || $d['recurrence']['repeatRate'] > $worst['repeatRate']) {
                $worst = [
                    'dataset' => $d['label'],
                    'repeatRate' => $d['recurrence']['repeatRate'],
                    'subjects' => $d['recurrence']['subjects'],
                    'repeated' => $d['recurrence']['repeated'],
                ];
            }
        }

        return [
            'supported' => $subjects > 0,
            'reason' => $subjects > 0 ? null : 'No dataset carries a subject reference, so repeat activity cannot be measured.',
            'subjects' => $subjects,
            'repeatedSubjects' => $repeated,
            'repeatRate' => $subjects > 0 ? $this->rate($repeated, $subjects) : null,
            'highestRepeatDataset' => $worst,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $datasets
     * @return array<string, mixed>
     */
    private function buildResponsiveness(array $datasets): array
    {
        $measured = 0;
        $withinDayWeighted = 0.0;
        $hoursWeighted = 0.0;
        $rows = [];

        foreach ($datasets as $d) {
            if (! $d['turnaround']['supported']) {
                continue;
            }

            $n = $d['turnaround']['measured'];
            $measured += $n;
            $hoursWeighted += $d['turnaround']['averageHours'] * $n;
            $withinDayWeighted += ($d['turnaround']['withinDayRate'] ?? 0) * $n;

            $rows[] = [
                'dataset' => $d['label'],
                'measured' => $n,
                'averageHours' => $d['turnaround']['averageHours'],
                'withinDayRate' => $d['turnaround']['withinDayRate'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['measured'] <=> $a['measured']);

        return [
            'supported' => $measured > 0,
            'reason' => $measured > 0 ? null : 'No record in this organization closes after it opens, so elapsed time cannot be measured.',
            'measured' => $measured,
            'averageHours' => $measured > 0 ? round($hoursWeighted / $measured, 2) : null,
            'withinDayRate' => $measured > 0 ? round($withinDayWeighted / $measured, 4) : null,
            'byDataset' => array_slice($rows, 0, self::TOP_N),
        ];
    }

    /**
     * The organization's monthly volume, and where it is heading.
     *
     * THE LAST BUCKET IS DROPPED WHEN IT IS THE CURRENT MONTH. A partial month
     * always reads as a collapse in volume, and a trend line that ends in a cliff
     * every single month is a trend line nobody can use.
     *
     * @param  array<int, array<string, mixed>>  $monthRows
     * @return array<string, mixed>
     */
    private function buildTrend(array $monthRows): array
    {
        $totals = [];
        $byDataset = [];

        foreach ($monthRows as $row) {
            $period = (string) $row['period'];
            $count = (int) $row['records'];
            $totals[$period] = ($totals[$period] ?? 0) + $count;
            $byDataset[(string) $row['dataset']][$period] = $count;
        }

        ksort($totals);

        $currentMonth = gmdate('Y-m');
        $complete = $totals;
        unset($complete[$currentMonth]);

        $series = $this->series($totals);
        $seriesByDataset = [];

        foreach ($byDataset as $dataset => $months) {
            ksort($months);
            $seriesByDataset[] = [
                'dataset' => $dataset,
                'label' => $this->humanise($dataset),
                'points' => $this->series($months),
                'momentum' => $this->momentum($months),
            ];
        }

        usort($seriesByDataset, fn ($a, $b) => array_sum(array_column($b['points'], 'records')) <=> array_sum(array_column($a['points'], 'records')));

        return [
            'supported' => $series !== [],
            'reason' => $series !== [] ? null : 'No imported record carries a timestamp, so no trend can be plotted.',
            'points' => $series,
            'byDataset' => array_slice($seriesByDataset, 0, self::TOP_N),
            'momentum' => $this->momentum($totals),
            'busiestMonth' => $complete === [] ? null : [
                'period' => (string) array_search(max($complete), $complete, true),
                'records' => max($complete),
            ],
            'note' => 'The current calendar month is excluded from momentum and from the busiest-month figure, because a partial month always reads as a fall.',
        ];
    }

    /**
     * The concentration findings, which are the ones a reader acts on.
     *
     * @param  array<string, array<string, mixed>>  $datasets
     * @param  array<string, array<string, mixed>>  $departments
     * @param  array<int, array<string, mixed>>  $categoryRows
     * @param  array<int, array<string, mixed>>  $zoneRows
     * @return array<string, mixed>
     */
    private function buildRankings(array $datasets, array $departments, array $categoryRows, array $zoneRows): array
    {
        $categories = [];

        foreach ($categoryRows as $row) {
            $categories[(string) $row['value']] = ($categories[(string) $row['value']] ?? 0) + (int) $row['records'];
        }

        $zones = [];

        foreach ($zoneRows as $row) {
            $zones[(string) $row['value']] = ($zones[(string) $row['value']] ?? 0) + (int) $row['records'];
        }

        $departmentCounts = [];

        foreach ($departments as $d) {
            $departmentCounts[$d['label']] = $d['records'];
        }

        $datasetCounts = [];

        foreach ($datasets as $d) {
            $datasetCounts[$d['label']] = $d['records'];
        }

        return [
            'datasets' => $this->topOf($datasetCounts, self::TOP_N, array_sum($datasetCounts)),
            'departments' => $this->topOf($departmentCounts, self::TOP_N, array_sum($departmentCounts)),
            'categories' => $this->topOf($categories, self::TOP_N, array_sum($categories)),
            'zones' => $this->topOf($zones, self::TOP_N, array_sum($zones)),
            'concentration' => [
                'departments' => $this->concentration($departmentCounts),
                'categories' => $this->concentration($categories),
                'zones' => $this->concentration($zones),
                'datasets' => $this->concentration($datasetCounts),
                'method' => 'Herfindahl-Hirschman index over the share each member holds: 0 is perfectly even, 1 is one member holding everything.',
            ],
        ];
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, int>>
     */
    private function groupValues(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['dataset']][(string) $row['value']] = (int) $row['records'];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array<string, mixed>>
     */
    private function topOf(array $counts, int $limit, int $total): array
    {
        arsort($counts);

        $out = [];

        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $out[] = [
                'name' => $name,
                'records' => $count,
                'share' => $total > 0 ? round($count / $total, 4) : null,
            ];
        }

        return $out;
    }

    /**
     * Herfindahl-Hirschman index — one number for "how concentrated is this".
     *
     * Chosen over "share held by the top member" because it responds to the whole
     * distribution: two organizations can both have a 40% leader and be nothing
     * alike underneath, and a reader deciding whether workload is dangerously
     * concentrated needs the shape, not the leader.
     *
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function concentration(array $counts): array
    {
        $total = array_sum($counts);

        if ($total <= 0 || count($counts) === 0) {
            return ['supported' => false, 'index' => null, 'members' => 0, 'topShare' => null, 'band' => null];
        }

        $index = 0.0;

        foreach ($counts as $count) {
            $index += ($count / $total) ** 2;
        }

        arsort($counts);
        $topShare = reset($counts) / $total;

        return [
            'supported' => true,
            'index' => round($index, 4),
            'members' => count($counts),
            'topShare' => round($topShare, 4),
            'topMember' => (string) array_key_first($counts),
            /*
              BANDS, NOT A BARE NUMBER. An HHI of 0.31 tells a reader nothing
              unless they already know the scale. The cut points are the ones
              competition regulators use for market concentration, which is the
              same arithmetic question, and they are stated here rather than
              hidden so a reader can disagree with them.
            */
            'band' => $index >= 0.25 ? 'highly concentrated' : ($index >= 0.15 ? 'moderately concentrated' : 'evenly distributed'),
        ];
    }

    /**
     * @param  array<string, int>  $months
     * @return array<int, array<string, mixed>>
     */
    private function series(array $months): array
    {
        if ($months === []) {
            return [];
        }

        ksort($months);
        $recent = array_slice($months, -self::TREND_MONTHS, null, true);

        $out = [];

        foreach ($recent as $period => $records) {
            $out[] = ['period' => (string) $period, 'records' => (int) $records];
        }

        return $out;
    }

    /**
     * Direction of travel: the three most recent COMPLETE months against the
     * three before them.
     *
     * Three rather than one, because a single month against a single month is
     * mostly noise on a seasonal business; and complete months only, for the
     * reason given on buildTrend().
     *
     * @param  array<string, int>  $months
     * @return array<string, mixed>
     */
    private function momentum(array $months): array
    {
        ksort($months);
        unset($months[gmdate('Y-m')]);

        if (count($months) < 4) {
            return [
                'supported' => false,
                'reason' => 'Fewer than four complete months of history, which is not enough to state a direction.',
                'change' => null,
                'direction' => null,
            ];
        }

        $values = array_values($months);
        $recent = array_slice($values, -3);
        $prior = array_slice($values, -6, 3);

        $recentMean = array_sum($recent) / count($recent);
        $priorMean = array_sum($prior) / count($prior);

        if ($priorMean <= 0.0) {
            return [
                'supported' => false,
                'reason' => 'The comparison period carries no records, so a percentage change is undefined.',
                'change' => null,
                'direction' => null,
            ];
        }

        $change = ($recentMean - $priorMean) / $priorMean;

        return [
            'supported' => true,
            'reason' => null,
            'change' => round($change, 4),
            'recentMonthlyAverage' => round($recentMean, 1),
            'priorMonthlyAverage' => round($priorMean, 1),
            // A five percent band around flat, so ordinary month-to-month wobble
            // is not reported as a trend.
            'direction' => $change > 0.05 ? 'rising' : ($change < -0.05 ? 'falling' : 'steady'),
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 19);
    }

    private function spanDays(mixed $from, mixed $to): ?int
    {
        $from = $this->date($from);
        $to = $this->date($to);

        if ($from === null || $to === null) {
            return null;
        }

        return (int) max(0, floor((strtotime($to) - strtotime($from)) / 86400));
    }

    /** `sales_call` → `Sales Call`. The dataset's own name, made readable. */
    private function humanise(string $dataset): string
    {
        $spaced = str_replace(['_', '-'], ' ', $dataset);
        $spaced = preg_replace('/\s+/', ' ', $spaced) ?? $spaced;

        // Import profiles emit single-letter segments for acronyms in the source
        // ("c_r_f_form", "i_t_support", "k_r_a"). Rejoin them so the label reads
        // as the organization writes it rather than as the loader spelled it.
        $words = array_filter(explode(' ', trim($spaced)), fn ($w) => $w !== '');
        $out = [];
        $acronym = '';

        foreach ($words as $word) {
            if (mb_strlen($word) === 1) {
                $acronym .= mb_strtoupper($word);

                continue;
            }

            if ($acronym !== '') {
                $out[] = $acronym;
                $acronym = '';
            }

            $out[] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }

        if ($acronym !== '') {
            $out[] = $acronym;
        }

        return implode(' ', $out);
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $tenantId, string $version, string $reason): array
    {
        return [
            'tenantId' => $tenantId,
            'dataVersion' => $version,
            'computedAt' => gmdate('c'),
            'computeMs' => 0,
            'available' => false,
            'reason' => $reason,
            'support' => ['records' => false],
            'totals' => ['records' => 0, 'datasets' => 0, 'departmentsWithActivity' => 0],
            'execution' => ['supported' => false, 'reason' => $reason],
            'service' => ['supported' => false, 'reason' => $reason],
            'responsiveness' => ['supported' => false, 'reason' => $reason],
            'trend' => ['supported' => false, 'reason' => $reason, 'points' => [], 'byDataset' => []],
            'datasets' => [],
            'departments' => [],
            'rankings' => ['datasets' => [], 'departments' => [], 'categories' => [], 'zones' => []],
            'derivation' => ['method' => 'Nothing was derived: the organization has no operational records to derive from.'],
        ];
    }
}
