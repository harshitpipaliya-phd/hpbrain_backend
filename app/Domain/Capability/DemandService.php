<?php

declare(strict_types=1);

namespace App\Domain\Capability;

use App\Domain\Kasba\AssessmentModelResolver;
use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Capability DEMAND, and the deficit between it and supply.
 *
 * The Brain could measure what people have and never what the organization
 * needs, so every capability figure was half an answer: a coverage number with
 * nothing to be short OF.
 *
 *   demand  = SUM over positions of (headcount in that position x its required level)
 *   supply  = SUM over assessed people of their mean latest proficiency
 *   deficit = supply - demand        (negative means short)
 *
 * THE RULE THAT MATTERS MOST HERE: a unit with no assessments returns NULL
 * deficit, never a negative number. "We are 40 short" and "we have never
 * measured" are different claims, and the second one rendered as the first is
 * the single most damaging thing this system could do — it would send someone to
 * fix a shortfall that may not exist, and it would look authoritative doing it.
 * Null propagates all the way to the API and, in Phase 6, to a hatched cell
 * rather than a low one.
 *
 * Demand is computed from POSITIONS, not from assessments, which is why it can
 * be known while supply is not: an organization knows what it needs the moment
 * it defines a role, and knows what it has only after it measures.
 */
final class DemandService
{
    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly AssessmentModelResolver $models,
    ) {
    }

    /**
     * Demand, supply and deficit per capability for a tenant.
     *
     * @return array<int, array{
     *     capabilityId: string, demand: float|null, supply: float|null,
     *     deficit: float|null, headcount: int, assessedCount: int,
     *     requiredLevel: float|null, coverage: float|null
     * }>
     */
    public function perCapability(string $tenantId): array
    {
        $headcountByPosition = $this->headcountByPosition($tenantId);
        $requirements = $this->requirements($tenantId);
        $supply = $this->supplyByCapability($tenantId);

        $out = [];

        foreach ($this->capabilityIds($tenantId, $requirements, $supply) as $capabilityId) {
            $demand = null;
            $headcount = 0;
            $requiredLevel = null;

            if (isset($requirements[$capabilityId])) {
                $demand = 0.0;

                foreach ($requirements[$capabilityId] as $positionId => $level) {
                    $inPosition = $headcountByPosition[$positionId] ?? 0;
                    $headcount += $inPosition;
                    $demand += $inPosition * $level;

                    // Reported for a single-position capability, where one
                    // number is meaningful; across several it would be an
                    // average masquerading as a requirement.
                    $requiredLevel = count($requirements[$capabilityId]) === 1 ? $level : null;
                }
            }

            $supplyRow = $supply[$capabilityId] ?? null;
            $supplyTotal = $supplyRow['total'] ?? null;
            $assessedCount = $supplyRow['count'] ?? 0;

            $out[] = [
                'capabilityId'  => (string) $capabilityId,
                'demand'        => $demand,
                'supply'        => $supplyTotal,
                // NULL unless BOTH sides are known. A deficit needs a demand to
                // fall short of and a supply to fall short with.
                'deficit'       => ($demand === null || $supplyTotal === null)
                    ? null
                    : round($supplyTotal - $demand, 4),
                'headcount'     => $headcount,
                'assessedCount' => $assessedCount,
                'requiredLevel' => $requiredLevel,
                // Share of the people who need this capability who have been
                // measured against it. Null when nobody needs it — a coverage of
                // "none of nobody" is not zero coverage.
                'coverage'      => $headcount === 0
                    ? null
                    : round(min(1.0, $assessedCount / $headcount), 4),
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['capabilityId'], $b['capabilityId']));

        return $out;
    }

    /**
     * Keyed by capability id, for joining onto a heatmap row.
     *
     * @return array<string, array<string, mixed>>
     */
    public function keyedByCapability(string $tenantId): array
    {
        $out = [];

        foreach ($this->perCapability($tenantId) as $row) {
            $out[$row['capabilityId']] = $row;
        }

        return $out;
    }

    /**
     * How many active people hold each position.
     *
     * @return array<string, int>
     */
    private function headcountByPosition(string $tenantId): array
    {
        $person = $this->resolver->resolve($tenantId, 'Person');

        if (! $person->has('position')) {
            // No position column means demand cannot be computed at all, which
            // is a genuine "unknown" rather than a demand of zero.
            return [];
        }

        $positionColumn = $person->field('position');

        $rows = DB::table($person->table)
            ->where($person->tenantKey, $tenantId)
            ->where($person->field('status'), 1)
            ->whereNull('deleted_at')
            ->whereNotNull($positionColumn)
            ->select($positionColumn, DB::raw('COUNT(*) as headcount'))
            ->groupBy($positionColumn)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->{$positionColumn}] = (int) $row->headcount;
        }

        return $out;
    }

    /**
     * required_level per capability per position.
     *
     * @return array<string, array<string, float>> capability => position => level
     */
    private function requirements(string $tenantId): array
    {
        $out = [];

        foreach (
            DB::table('hpbrain_job_role_capability_requirements')
                ->where('tenant_id', $tenantId)->get() as $row
        ) {
            $out[(string) $row->capability_id][(string) $row->job_role_id] = (float) $row->required_level;
        }

        return $out;
    }

    /**
     * Supply: the sum of each assessed person's mean LATEST proficiency.
     *
     * "Latest" is per assignment — a person reassessed three times contributes
     * once, at their most recent level. Dimensions come from the tenant's
     * assessment model, so a four-dimension tenant averages four.
     *
     * An assignment with no assessed dimension contributes NOTHING rather than a
     * zero: it has not been measured, and averaging it in as zero would drag the
     * organization's supply down by the act of creating an assignment.
     *
     * @return array<string, array{total: float, count: int}>
     */
    private function supplyByCapability(string $tenantId): array
    {
        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenantId)
            ->get(['id', 'capability_id']);

        if ($assignments->isEmpty()) {
            return [];
        }

        $levelColumns = $this->models->forTenant($tenantId)->levelColumns();

        $latest = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenantId)
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->orderByDesc('assessed_date')
            ->get()
            ->groupBy('assignment_id')
            ->map(fn ($rows) => $rows->first());

        $out = [];

        foreach ($assignments as $assignment) {
            $proficiency = $latest->get($assignment->id);

            if ($proficiency === null) {
                continue;
            }

            $levels = [];

            foreach ($levelColumns as $column) {
                $value = $proficiency->{$column} ?? null;

                if ($value !== null) {
                    $levels[] = (float) $value;
                }
            }

            if ($levels === []) {
                continue;
            }

            $capabilityId = (string) $assignment->capability_id;
            $out[$capabilityId] ??= ['total' => 0.0, 'count' => 0];
            $out[$capabilityId]['total'] += array_sum($levels) / count($levels);
            $out[$capabilityId]['count']++;
        }

        foreach ($out as $capabilityId => $row) {
            $out[$capabilityId]['total'] = round($row['total'], 4);
        }

        return $out;
    }

    /**
     * Every capability that either has a requirement or has been assessed.
     *
     * @param  array<string, mixed>  $requirements
     * @param  array<string, mixed>  $supply
     * @return array<int, string>
     */
    private function capabilityIds(string $tenantId, array $requirements, array $supply): array
    {
        $ids = array_unique(array_merge(array_keys($requirements), array_keys($supply)));
        sort($ids);

        return $ids;
    }
}
