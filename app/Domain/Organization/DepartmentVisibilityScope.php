<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Universal\ResolvedSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which rows of the mapped OrganizationUnit table are actually this
 * organization's departments.
 *
 * WHY THIS IS NOT JUST "deleted_at IS NULL". The ERP's hrms_departments holds
 * more than one organization's worth of thing: rows flagged `is_calculated = 1`
 * are template scaffolding the ERP generates, and a tenant that has been
 * re-onboarded carries an older cohort of manual rows alongside its current
 * one. Neither is a department anybody works in, and DepartmentController has
 * excluded both from its list since that behaviour was introduced.
 *
 * WHY IT LIVES HERE NOW. The exclusion existed ONLY in the list endpoint. Every
 * count — the Organization overview, the Intelligence Workspace, the home
 * metrics tile — counted the raw table instead, so Fiber Valley's overview said
 * 24 departments while its Departments screen listed 5. That is the whole
 * "the numbers disagree between screens" defect: not two different numbers for
 * one question, but one question asked with the filter and once without it.
 *
 * The filter is the definition, so the definition is now in one class and both
 * the list and the counts apply it. A screen cannot opt out by forgetting.
 *
 * NO-OP ON SOURCES THAT ARE NOT hrms_departments, deliberately. The rule is a
 * fact about this ERP's table, not a universal one, and applying `is_calculated`
 * to a source system that has no such column would be a fallback — the one thing
 * the resolver contract forbids.
 */
final class DepartmentVisibilityScope
{
    /** @var array<string, ?string> memoised per tenant: cohort start, or null */
    private array $cohortStart = [];

    public function apply(Builder $query, ResolvedSource $unit, string $tenantId): void
    {
        if ($this->baseTable($unit->table) !== 'hrms_departments') {
            return;
        }

        if (Schema::hasColumn($unit->table, 'is_calculated')) {
            $query->where(fn (Builder $w) => $w->where('is_calculated', 0)->orWhereNull('is_calculated'));
        }

        $cohortStart = $this->cohortStart($unit, $tenantId);

        if ($cohortStart !== null) {
            $query->where('created_at', '>=', $cohortStart);
        }
    }

    /**
     * The ids of every visible department, for callers that must filter a
     * DIFFERENT table by them — people, for instance, which are joined to units
     * by a foreign key rather than living in the unit table.
     *
     * @return array<int, string>
     */
    public function visibleIds(ResolvedSource $unit, string $tenantId): array
    {
        $query = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId);

        if ($unit->has('deletedAt')) {
            $query->whereNull($unit->field('deletedAt'));
        }

        $this->apply($query, $unit, $tenantId);

        return $query->pluck($unit->primaryKey)->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Where the current cohort of manually-created units begins, when there is
     * an older one to exclude. Null means "no cohort split applies" — either the
     * columns are absent, or this tenant has only ever had one cohort.
     *
     * Memoised: three EXISTS/MIN probes per call, and the list endpoint, the
     * count service and the twin endpoint all ask within the same request.
     */
    private function cohortStart(ResolvedSource $unit, string $tenantId): ?string
    {
        $key = $unit->table.'|'.$tenantId;

        if (array_key_exists($key, $this->cohortStart)) {
            return $this->cohortStart[$key];
        }

        return $this->cohortStart[$key] = $this->computeCohortStart($unit, $tenantId);
    }

    private function computeCohortStart(ResolvedSource $unit, string $tenantId): ?string
    {
        if (! $unit->has('deletedAt')) {
            return null;
        }

        foreach (['is_calculated', 'created_by', 'created_at', $unit->field('deletedAt')] as $column) {
            if (! Schema::hasColumn($unit->table, $column)) {
                return null;
            }
        }

        $currentCohortStart = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->whereNull($unit->field('deletedAt'))
            ->where(fn (Builder $w) => $w->where('is_calculated', 0)->orWhereNull('is_calculated'))
            ->whereNull('created_by')
            ->whereNotNull('created_at')
            ->min('created_at');

        if ($currentCohortStart === null) {
            return null;
        }

        $hasTemplateRows = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->whereNull($unit->field('deletedAt'))
            ->where('is_calculated', 1)
            ->exists();

        $hasOlderManualRows = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->whereNull($unit->field('deletedAt'))
            ->where(fn (Builder $w) => $w->where('is_calculated', 0)->orWhereNull('is_calculated'))
            ->whereNotNull('created_by')
            ->where('created_at', '<', $currentCohortStart)
            ->exists();

        return $hasTemplateRows && $hasOlderManualRows ? (string) $currentCohortStart : null;
    }

    private function baseTable(string $table): string
    {
        $dot = strrpos($table, '.');

        return $dot === false ? $table : substr($table, $dot + 1);
    }
}
