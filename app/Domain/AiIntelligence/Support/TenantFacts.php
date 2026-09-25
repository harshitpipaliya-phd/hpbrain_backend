<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use App\Domain\Organization\FoundationCounts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Tenant-level figures from HP Brain's own tables — counts and taxonomy only.
 *
 * Shared by the grounding briefing (OrganisationContext) and the template preview
 * (TemplatePreviewData) so the two can never disagree about a number.
 *
 * Every count is scoped to one tenant. A missing table answers null, not zero:
 * zero is a claim that the tenant has none of something, while a missing table
 * means this deployment cannot say. People and departments come from
 * FoundationCounts — the same ERP-backed source the Organization and Department
 * screens use, resolved per tenant through EntityResolver.
 */
final class TenantFacts
{
    /** Signal statuses that close a signal (see OperationalSignalWriter / RuleEvaluator). */
    public const CLOSED_SIGNAL_STATUSES = ['resolved', 'dismissed'];

    /** Recommendation statuses still awaiting a decision. */
    public const PENDING_RECOMMENDATION_STATUSES = ['open', 'pending'];

    /** @var array<string, array<string, int|null>> */
    private array $memo = [];

    public function __construct(private readonly FoundationCounts $foundation)
    {
    }

    /**
     * Every figure, keyed by the template-variable name it fills.
     *
     * @return array<string, int|null>
     */
    public function all(string $tenantId): array
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $foundation = $this->foundation($tenantId);

        return $this->memo[$tenantId] = [
            'people' => $foundation['people'],
            'departments' => $foundation['departments'],
            'open_signals' => $this->count('hpbrain_signals', $tenantId, fn (Builder $q) => $q->whereNotIn('status', self::CLOSED_SIGNAL_STATUSES)),
            'evidence_count' => $this->count('hpbrain_evidence', $tenantId),
            'open_cases' => $this->count('hpbrain_cases', $tenantId, fn (Builder $q) => $q->whereNotIn('status', ['closed', 'resolved', 'archived', 'dismissed'])),
            'decision_count' => $this->count('hpbrain_decisions', $tenantId),
            'pending_recommendations' => $this->count(
                'hpbrain_recommendations',
                $tenantId,
                fn (Builder $q) => $q->whereIn(DB::raw('LOWER(TRIM(status))'), self::PENDING_RECOMMENDATION_STATUSES)
            ),
            'capability_count' => $this->count('hpbrain_capabilities', $tenantId, fn (Builder $q) => $q->where('status', '!=', 'archived')),
            'knowledge_asset_count' => $this->count('hpbrain_knowledge_assets', $tenantId, fn (Builder $q) => $q->where('status', '!=', 'archived')),
            'ai_templates_published' => $this->count(
                'hpbrain_ai_templates',
                $tenantId,
                fn (Builder $q) => $q->where('status', 'published'),
                includePlatform: true
            ),
            'ai_policies_active' => $this->count(
                'hpbrain_ai_policies',
                $tenantId,
                fn (Builder $q) => $q->where('status', 1),
                includePlatform: true
            ),
        ];
    }

    /**
     * A tenant-scoped count, or null when the table is not on this deployment.
     *
     * `$includePlatform` is for this layer's own hpbrain_ai_* tables, where '*'
     * rows are shared configuration every tenant resolves.
     */
    public function count(string $table, string $tenantId, ?callable $filter = null, bool $includePlatform = false): ?int
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }

            $query = DB::table($table);

            $includePlatform
                ? Platform::visible($query, $tenantId)
                : $query->where('tenant_id', $tenantId);

            if ($filter !== null) {
                $filter($query);
            }

            return (int) $query->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{people: ?int, departments: ?int} */
    private function foundation(string $tenantId): array
    {
        try {
            $headline = $this->foundation->headline($tenantId);

            return [
                'people' => (int) $headline['people'],
                'departments' => (int) $headline['departments'],
            ];
        } catch (Throwable) {
            // The ERP source is unreachable or unmapped for this tenant: say nothing
            // rather than report a zero that was never measured.
            return ['people' => null, 'departments' => null];
        }
    }
}
