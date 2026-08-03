<?php

declare(strict_types=1);

namespace App\Repositories;

final class AiEvaluationRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_ai_evaluations';
    }

    protected function jsonColumns(): array
    {
        return ['dataset', 'results'];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }
}
