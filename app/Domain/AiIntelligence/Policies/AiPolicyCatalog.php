<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Policies;

use App\Domain\AiIntelligence\Support\Platform;
use App\Domain\Organization\OrganizationStructureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What an AI policy can say, and where it can apply — HP Brain's vocabulary.
 *
 * The storage shape is G2G's (policy + rules + assignments) so a policy means the
 * same record in both products. The terms are HP Brain's: its scopes are the whole
 * organisation, an HP Brain area (hpbrain_ai_modules), a department (the tenant's
 * ERP-backed department source, via OrganizationStructureService) and a position
 * (hpbrain_positions); its rules name things HP Brain actually asks an AI to do.
 *
 * Scope targets are read live from those sources for the caller's tenant. A scope
 * with no rows arrives empty and the screen offers it without a picker.
 */
final class AiPolicyCatalog
{
    /** Narrowest last — the order is the precedence. */
    private const SCOPE_TYPES = [
        ['value' => 'global', 'label' => 'Whole organisation'],
        ['value' => 'module', 'label' => 'Module'],
        ['value' => 'department', 'label' => 'Department'],
        ['value' => 'position', 'label' => 'Position'],
    ];

    public function __construct(private readonly OrganizationStructureService $structure)
    {
    }

    /** @return array<int, array{value:string, label:string}> */
    public function scopeTypeOptions(): array
    {
        return self::SCOPE_TYPES;
    }

    /** @return array<int, array{value:string, label:string}> */
    public function policyTypeOptions(): array
    {
        return [
            ['value' => 'ai_free', 'label' => 'AI-Free'],
            ['value' => 'ai_assisted', 'label' => 'AI-Assisted'],
            ['value' => 'ai_empowered', 'label' => 'AI-Empowered'],
            ['value' => 'custom', 'label' => 'Custom'],
        ];
    }

    /** @return array<int, array{key:string, label:string, default:bool}> */
    public function ruleCatalogue(): array
    {
        return [
            ['key' => 'use_ai_for_summarization', 'label' => 'Summarising signals, evidence and reports', 'default' => true],
            ['key' => 'use_ai_for_explanations', 'label' => 'Explaining a record, a signal or a recommendation', 'default' => true],
            ['key' => 'use_ai_for_brainstorming', 'label' => 'Brainstorming hypotheses and options', 'default' => true],
            ['key' => 'use_ai_for_rewriting', 'label' => 'Rewriting and tone adjustment', 'default' => true],
            ['key' => 'use_ai_for_signal_triage', 'label' => 'Classifying and prioritising signals', 'default' => true],
            ['key' => 'use_ai_for_evidence_grading', 'label' => 'Grading evidence confidence', 'default' => false],
            ['key' => 'use_ai_for_recommendation_drafting', 'label' => 'Drafting recommendations', 'default' => true],
            ['key' => 'use_ai_for_decision_proposal', 'label' => 'Proposing a decision for approval', 'default' => false],
            ['key' => 'use_ai_for_capability_assessment', 'label' => 'Proposing a capability (KASBA) rating', 'default' => false],
            ['key' => 'use_ai_for_execution_planning', 'label' => 'Planning an ESO execution', 'default' => false],
            ['key' => 'use_ai_for_generating_code', 'label' => 'Generating code', 'default' => false],
            ['key' => 'use_ai_for_autonomous_action', 'label' => 'Acting without a human approving first', 'default' => false],
        ];
    }

    /**
     * The real targets an assignment can name, per scope that has any.
     *
     * @return array<string, array<int, array{id:string, label:string}>>
     */
    public function scopeTargets(string $tenantId): array
    {
        return [
            'module' => array_map(
                fn (array $m) => ['id' => $m['id'], 'label' => $m['label']],
                $this->moduleOptions($tenantId)
            ),
            'department' => $this->departments($tenantId),
            'position' => $this->positions($tenantId),
        ];
    }

    /** @return array<int, array{id:string, key:string, label:string}> */
    public function moduleOptions(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_ai_modules')) {
            return [];
        }

        $rows = Platform::visible(DB::table('hpbrain_ai_modules')->where('status', 1), $tenantId)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['id', 'module_key', 'label', 'tenant_id']);

        // One option per module_key: an organisation row that shadows a platform
        // module replaces it rather than appearing beside it in the picker.
        $byKey = [];
        foreach ($rows as $row) {
            $key = (string) $row->module_key;
            if (! isset($byKey[$key]) || (string) $row->tenant_id === $tenantId) {
                $byKey[$key] = ['id' => (string) $row->id, 'key' => $key, 'label' => (string) $row->label];
            }
        }

        return array_values($byKey);
    }

    /** @return array<int, string> Every hpbrain_ai_modules id this tenant resolves for one key. */
    public function moduleIds(string $moduleKey, string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_ai_modules')) {
            return [];
        }

        return Platform::visible(DB::table('hpbrain_ai_modules')->where('module_key', $moduleKey), $tenantId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    public function moduleKeysFor(array $ids, string $tenantId): array
    {
        if ($ids === [] || ! Schema::hasTable('hpbrain_ai_modules')) {
            return [];
        }

        return array_values(array_unique(
            Platform::visible(DB::table('hpbrain_ai_modules')->whereIn('id', $ids), $tenantId)
                ->pluck('module_key')
                ->map(fn ($key) => (string) $key)
                ->all()
        ));
    }

    /**
     * Whether a scope target belongs to this tenant. An assignment naming a module,
     * department or position the tenant does not have would match nothing — or,
     * worse, another tenant's row — so it is refused at save time.
     */
    public function targetExists(string $scopeType, ?string $scopeId, string $tenantId): bool
    {
        if ($scopeType === 'global') {
            return $scopeId === null;
        }

        if ($scopeId === null) {
            return false;
        }

        return in_array($scopeId, array_column($this->scopeTargets($tenantId)[$scopeType] ?? [], 'id'), true);
    }

    /** @return array<int, array{id:string, label:string}> */
    private function departments(string $tenantId): array
    {
        try {
            $departments = $this->structure->forTenant($tenantId)['departments'] ?? [];
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($departments as $department) {
            if (($department['status'] ?? 'active') !== 'active') {
                continue;
            }

            $out[] = ['id' => (string) $department['id'], 'label' => (string) ($department['name'] ?? $department['id'])];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return array_slice($out, 0, 500);
    }

    /** @return array<int, array{id:string, label:string}> */
    private function positions(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_positions')) {
            return [];
        }

        try {
            return DB::table('hpbrain_positions')
                ->where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereNotIn('status', ['inactive', 'archived', 'deleted', 'retired']);
                })
                ->orderBy('title')
                ->limit(500)
                ->get(['id', 'title'])
                ->map(fn ($row) => ['id' => (string) $row->id, 'label' => (string) $row->title])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
