<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Configuration;

use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The models each provider offers, as one editable list (hpbrain_ai_models).
 *
 * Platform rows (`tenant_id = '*'`) are the shared catalogue every tenant sees; a
 * tenant's own rows are its alone. The dropdown, Model Management and the runtime
 * resolver all read through here, so a model added once is offered everywhere.
 * Ported from G2G's ModelCatalog; precedence is platform rows first, as there.
 */
final class ModelCatalog
{
    private const TABLE = 'hpbrain_ai_models';

    /** @return array<int, array<string, mixed>> */
    public function forProvider(string $provider, string $tenantId, bool $includeRetired = false): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $query = DB::table(self::TABLE)->where('provider', $provider);

        if (! $includeRetired) {
            $query->where('status', 1);
        }

        Platform::visible($query, $tenantId);

        return $this->ordered($query)->get()->map(fn ($row) => $this->present($row))->all();
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function grouped(string $tenantId, bool $includeRetired = true): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $query = DB::table(self::TABLE);

        if (! $includeRetired) {
            $query->where('status', 1);
        }

        Platform::visible($query, $tenantId);

        $rows = $this->ordered($query->orderBy('provider'))->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->provider][] = $this->present($row);
        }

        return $grouped;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id, string $tenantId): ?array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        $row = Platform::visible(DB::table(self::TABLE)->where('id', $id), $tenantId)->first();

        return $row ? $this->present($row) : null;
    }

    public function offers(string $provider, string $modelId, string $tenantId): bool
    {
        if (! Schema::hasTable(self::TABLE)) {
            return true;
        }

        $query = DB::table(self::TABLE)
            ->where('provider', $provider)
            ->where('model_id', $modelId)
            ->where('status', 1);

        return Platform::visible($query, $tenantId)->exists();
    }

    public function defaultFor(string $provider, string $tenantId): ?string
    {
        return $this->forProvider($provider, $tenantId)[0]['model_id'] ?? null;
    }

    /**
     * Rate per 1,000 tokens for one model — the tenant's own row beats the platform's.
     *
     * @return array{input:float, output:float}|null
     */
    public function rates(string $provider, ?string $modelId, string $tenantId): ?array
    {
        if ($modelId === null || ! Schema::hasTable(self::TABLE)) {
            return null;
        }

        $row = Platform::visible(
            DB::table(self::TABLE)->where('provider', $provider)->where('model_id', $modelId),
            $tenantId
        )
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->first(['input_cost_per_1k', 'output_cost_per_1k']);

        if ($row === null || $row->input_cost_per_1k === null || $row->output_cost_per_1k === null) {
            return null;
        }

        return ['input' => (float) $row->input_cost_per_1k, 'output' => (float) $row->output_cost_per_1k];
    }

    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [Platform::TENANT])
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    /** @return array<string, mixed> */
    public function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'provider' => (string) $row->provider,
            'model_id' => (string) $row->model_id,
            'label' => (string) $row->label,
            'max_output_tokens' => $row->max_output_tokens === null ? null : (int) $row->max_output_tokens,
            'input_cost_per_1k' => $row->input_cost_per_1k === null ? null : (float) $row->input_cost_per_1k,
            'output_cost_per_1k' => $row->output_cost_per_1k === null ? null : (float) $row->output_cost_per_1k,
            'sort_order' => (int) ($row->sort_order ?? 0),
            'status' => (int) $row->status,
            'scope' => Platform::isPlatform($row) ? 'platform' : 'institute',
        ];
    }
}
