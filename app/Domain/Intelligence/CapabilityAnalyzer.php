<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Capability\CapabilityState;
use Illuminate\Support\Facades\DB;

/**
 * What the organization can do, and how firmly it knows that.
 *
 * THE STATE MATTERS MORE THAN THE LEVEL, and this class is built around that.
 * Architecture Invariant 6 requires every capability to carry an explicit state —
 * Unknown, Asserted, Inferred, Assessed, Demonstrated/Observed, Mastered — because
 * a self-asserted level 4 and an assessment-backed level 4 are the same number and
 * entirely different claims. So the headline figure here is not mean proficiency;
 * it is how much of the organization's claimed capability has ever been checked.
 *
 * A LEVEL OF NULL IS NEVER READ AS ZERO. An unassessed KASBA dimension means
 * nobody has looked, and averaging it in as 0 would report a competent
 * organization as incompetent. Unassessed dimensions are counted and excluded from
 * every mean, and the count ships with the mean so a reader knows what the average
 * was taken over.
 */
final class CapabilityAnalyzer
{
    /** The five KASBA dimensions, from config so this cannot drift from the assessment model. */
    private function dimensions(): array
    {
        return (array) config('brain.kasba.dimensions', ['knowledge', 'ability', 'skill', 'behaviour', 'attitude']);
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    public function analyse(string $tenantId, array $profile): array
    {
        $capabilities = DB::table('hpbrain_capabilities')->where('tenant_id', $tenantId)->count();

        if ($capabilities === 0) {
            return [
                'measurable'   => false,
                'capabilities' => 0,
                'why'          => 'No capability has been defined for this organization, so there is nothing to assess against.',
                'gaps'         => ['hpbrain_capabilities'],
            ];
        }

        $byCategory    = $this->byCategory($tenantId);
        $assignments   = $this->assignments($tenantId);
        $proficiency   = $this->proficiency($tenantId);
        $unassigned    = $this->unassigned($tenantId);
        $unitCoverage  = $this->unitCoverage($tenantId, $profile);
        $criticalUnassessed = $this->criticalUnassessed($tenantId);

        return [
            'measurable'   => true,
            'capabilities' => $capabilities,
            'byCategory'   => $byCategory,
            'byCriticality' => $this->byCriticality($tenantId),
            'assignments'  => $assignments,
            'proficiency'  => $proficiency,
            'unassigned'   => $unassigned,
            'unitCoverage' => $unitCoverage,
            'criticalUnassessed' => $criticalUnassessed,
            // The one figure this screen is for: of everything the organization
            // claims it can do, how much has been checked rather than asserted.
            'evidencedShare' => $assignments['total'] === 0 ? null : round($proficiency['evidenced'] / $assignments['total'], 4),
            'confidence'   => Confidence::build()
                ->add(
                    'assessmentCoverage', 0.45,
                    $assignments['total'] === 0 ? null : $proficiency['assessed'] / $assignments['total'],
                    $proficiency['assessed'].' of '.$assignments['total'].' capability assignments carry any proficiency record at all',
                )
                ->add(
                    'stateStrength', 0.35,
                    $proficiency['assessed'] === 0 ? null : $proficiency['evidenced'] / $proficiency['assessed'],
                    $proficiency['evidenced'].' of '.$proficiency['assessed'].' assessed assignments have advanced beyond Asserted, which is the point at which a state requires evidence',
                )
                ->add(
                    'dimensionCoverage', 0.20,
                    $proficiency['dimensionCoverage'],
                    'share of the '.count($this->dimensions()).' KASBA dimensions that carry a level where a proficiency record exists',
                )
                ->jsonSerialize(),
            'provenance'   => Provenance::of('capability definitions, assignments and proficiency records for this organization')
                ->from('hpbrain_capabilities', ['tenant_id' => $tenantId], $capabilities)
                ->from('hpbrain_capability_assignments', ['tenant_id' => $tenantId], $assignments['total'])
                ->from('hpbrain_capability_proficiency', ['tenant_id' => $tenantId], $proficiency['assessed']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function byCategory(string $tenantId): array
    {
        return DB::table('hpbrain_capabilities')->where('tenant_id', $tenantId)
            ->selectRaw("COALESCE(category, 'uncategorised') AS category, COUNT(*) AS n")
            ->groupBy('category')->orderByDesc('n')->get()
            ->map(fn ($r) => ['category' => (string) $r->category, 'capabilities' => (int) $r->n])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byCriticality(string $tenantId): array
    {
        return DB::table('hpbrain_capabilities')->where('tenant_id', $tenantId)
            ->selectRaw("COALESCE(criticality, 'unstated') AS criticality, COUNT(*) AS n")
            ->groupBy('criticality')->orderByDesc('n')->get()
            ->map(fn ($r) => ['criticality' => (string) $r->criticality, 'capabilities' => (int) $r->n])->all();
    }

    /** @return array<string, mixed> */
    private function assignments(string $tenantId): array
    {
        $rows = DB::table('hpbrain_capability_assignments')->where('tenant_id', $tenantId)
            ->selectRaw("COALESCE(target_type, 'unspecified') AS target_type, COUNT(*) AS n, COUNT(DISTINCT target_id) AS targets")
            ->groupBy('target_type')->get();

        $byTarget = [];
        $total    = 0;

        foreach ($rows as $r) {
            $byTarget[(string) $r->target_type] = ['assignments' => (int) $r->n, 'distinctTargets' => (int) $r->targets];
            $total += (int) $r->n;
        }

        return ['total' => $total, 'byTargetType' => $byTarget];
    }

    /**
     * Proficiency records, read for state strength before level.
     *
     * @return array<string, mixed>
     */
    private function proficiency(string $tenantId): array
    {
        $dimensions = $this->dimensions();

        $selects = ['COUNT(*) AS assessed'];

        foreach ($dimensions as $dimension) {
            $column = $dimension.'_level';
            $selects[] = "COUNT(`{$column}`) AS nn_{$dimension}";
            $selects[] = "AVG(`{$column}`) AS avg_{$dimension}";
        }

        $selects[] = 'AVG(evidence_confidence) AS mean_evidence_confidence';
        $selects[] = 'COUNT(evidence_ref) AS with_evidence_ref';
        $selects[] = 'MAX(assessed_date) AS last_assessed';

        $agg = (array) DB::table('hpbrain_capability_proficiency')->where('tenant_id', $tenantId)
            ->selectRaw(implode(', ', $selects))->first();

        $assessed = (int) ($agg['assessed'] ?? 0);

        $byDimension     = [];
        $dimensionFilled = 0;

        foreach ($dimensions as $dimension) {
            $levelled = (int) ($agg['nn_'.$dimension] ?? 0);

            $byDimension[] = [
                'dimension' => $dimension,
                // The Product Bible's own vocabulary: Behaviour and Attitude are
                // observed rather than demonstrated, and the label has to say so.
                'topState'  => CapabilityState::forDimension(CapabilityState::DEMONSTRATED, $dimension),
                'levelled'  => $levelled,
                'unassessed' => $assessed - $levelled,
                // null, never 0. Nobody having looked is not a score.
                'meanLevel' => $agg['avg_'.$dimension] === null ? null : round((float) $agg['avg_'.$dimension], 2),
                'maxLevel'  => (int) config('brain.kasba.max_level', 5),
            ];

            if ($levelled > 0) {
                $dimensionFilled++;
            }
        }

        $states = DB::table('hpbrain_capability_proficiency')->where('tenant_id', $tenantId)
            ->selectRaw("COALESCE(capability_state, 'Unrecorded') AS state, COUNT(*) AS n")
            ->groupBy('state')->pluck('n', 'state')->all();

        // Everything above Asserted claims a measurement rather than a claim,
        // which is exactly where CapabilityState starts demanding an evidenceRef.
        $evidenced = 0;

        foreach ($states as $state => $count) {
            if (in_array($state, [CapabilityState::UNKNOWN, CapabilityState::ASSERTED, 'Unrecorded'], true)) {
                continue;
            }
            $evidenced += (int) $count;
        }

        return [
            'assessed'          => $assessed,
            'evidenced'         => $evidenced,
            'asserted'          => (int) ($states[CapabilityState::ASSERTED] ?? 0),
            'unknown'           => (int) ($states[CapabilityState::UNKNOWN] ?? 0),
            'unrecordedState'   => (int) ($states['Unrecorded'] ?? 0),
            'byState'           => $this->stateLadder($states),
            'byDimension'       => $byDimension,
            'dimensionCoverage' => count($dimensions) === 0 ? null : round($dimensionFilled / count($dimensions), 4),
            'meanEvidenceConfidence' => $agg['mean_evidence_confidence'] === null ? null : round((float) $agg['mean_evidence_confidence'], 4),
            'withEvidenceRef'   => (int) ($agg['with_evidence_ref'] ?? 0),
            'lastAssessed'      => $agg['last_assessed'] ?? null,
        ];
    }

    /**
     * The six-state ladder, in order, including the rungs nobody is on.
     *
     * Empty rungs are RETAINED. A ladder that shows only Asserted looks like a
     * complete picture; one that shows Assessed 0, Demonstrated 0, Mastered 0
     * shows that the organization has never verified anything, which is the
     * finding.
     *
     * @param array<string, int> $counts
     *
     * @return array<int, array<string, mixed>>
     */
    private function stateLadder(array $counts): array
    {
        $ladder = [
            CapabilityState::UNKNOWN, CapabilityState::ASSERTED, CapabilityState::INFERRED,
            CapabilityState::ASSESSED, CapabilityState::DEMONSTRATED, CapabilityState::OBSERVED,
            CapabilityState::MASTERED,
        ];

        $out = [];

        foreach ($ladder as $state) {
            $out[] = [
                'state'          => $state,
                'count'          => (int) ($counts[$state] ?? 0),
                'requiresEvidence' => ! in_array($state, [CapabilityState::UNKNOWN, CapabilityState::ASSERTED], true),
            ];
        }

        if (($counts['Unrecorded'] ?? 0) > 0) {
            $out[] = ['state' => 'Unrecorded', 'count' => (int) $counts['Unrecorded'], 'requiresEvidence' => false];
        }

        return $out;
    }

    /**
     * Capabilities nobody is responsible for.
     *
     * @return array<string, mixed>
     */
    private function unassigned(string $tenantId): array
    {
        $rows = DB::table('hpbrain_capabilities as c')
            ->where('c.tenant_id', $tenantId)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('hpbrain_capability_assignments as a')
                ->whereColumn('a.capability_id', 'c.id')->where('a.tenant_id', $tenantId))
            ->select('c.id', 'c.name', 'c.category', 'c.criticality')
            ->get();

        return [
            'count' => $rows->count(),
            'items' => $rows->map(fn ($r) => [
                'id'          => (string) $r->id,
                'name'        => (string) ($r->name ?? 'unnamed'),
                'category'    => (string) ($r->category ?? 'uncategorised'),
                'criticality' => (string) ($r->criticality ?? 'unstated'),
            ])->all(),
        ];
    }

    /**
     * Critical capabilities whose state has never advanced past a claim.
     *
     * The most consequential single query in this class: a capability the
     * organization has declared critical, and which nobody has ever verified
     * anybody actually has.
     *
     * @return array<string, mixed>
     */
    private function criticalUnassessed(string $tenantId): array
    {
        $rows = DB::table('hpbrain_capabilities as c')
            ->where('c.tenant_id', $tenantId)
            ->whereIn(DB::raw('LOWER(c.criticality)'), ['critical', 'high'])
            ->leftJoin('hpbrain_capability_assignments as a', function ($j) use ($tenantId) {
                $j->on('a.capability_id', '=', 'c.id')->where('a.tenant_id', '=', $tenantId);
            })
            ->leftJoin('hpbrain_capability_proficiency as p', function ($j) use ($tenantId) {
                $j->on('p.assignment_id', '=', 'a.id')->where('p.tenant_id', '=', $tenantId);
            })
            ->selectRaw("c.id, c.name, c.category, c.criticality,
                         COUNT(DISTINCT a.id) AS assignments,
                         COUNT(DISTINCT p.id) AS proficiency_records,
                         SUM(CASE WHEN p.capability_state IN ('Assessed','Demonstrated','Observed','Mastered') THEN 1 ELSE 0 END) AS verified")
            ->groupBy('c.id', 'c.name', 'c.category', 'c.criticality')
            ->get();

        $unverified = $rows->filter(fn ($r) => (int) $r->verified === 0)->values();

        return [
            'critical'   => $rows->count(),
            'unverified' => $unverified->count(),
            'items'      => $unverified->map(fn ($r) => [
                'id'          => (string) $r->id,
                'name'        => (string) ($r->name ?? 'unnamed'),
                'category'    => (string) ($r->category ?? 'uncategorised'),
                'criticality' => (string) ($r->criticality ?? 'unstated'),
                'assignments' => (int) $r->assignments,
                'proficiencyRecords' => (int) $r->proficiency_records,
                'why'         => (int) $r->assignments === 0
                    ? 'declared critical and assigned to nobody'
                    : ((int) $r->proficiency_records === 0
                        ? 'assigned but never assessed'
                        : 'assessed, but no state has advanced past Asserted — the level is a claim, not a measurement'),
            ])->all(),
        ];
    }

    /**
     * How much of the organization's structure has any capability attached.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function unitCoverage(string $tenantId, array $profile): array
    {
        $units  = $profile['erp']['OrganizationUnit']['count'] ?? null;
        $people = $profile['erp']['Person']['count'] ?? null;

        $covered = DB::table('hpbrain_capability_assignments')->where('tenant_id', $tenantId)
            ->selectRaw("COALESCE(target_type, 'unspecified') AS target_type, COUNT(DISTINCT target_id) AS n")
            ->groupBy('target_type')->pluck('n', 'target_type')->all();

        $unitsCovered  = (int) ($covered['Department'] ?? $covered['OrganizationUnit'] ?? 0);
        $peopleCovered = (int) ($covered['Person'] ?? 0);

        return [
            'units'          => $units,
            'unitsCovered'   => $unitsCovered,
            'unitShare'      => $units === null || $units === 0 ? null : round(min(1.0, $unitsCovered / $units), 4),
            'people'         => $people,
            'peopleCovered'  => $peopleCovered,
            'peopleShare'    => $people === null || $people === 0 ? null : round(min(1.0, $peopleCovered / $people), 4),
            'why'            => 'Distinct assignment targets against the counts held in this tenant\'s system of record. A share of null means the entity is not mapped for this tenant, not that coverage is zero.',
        ];
    }
}
