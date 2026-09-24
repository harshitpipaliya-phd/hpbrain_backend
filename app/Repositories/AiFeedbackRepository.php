<?php

declare(strict_types=1);

namespace App\Repositories;

final class AiFeedbackRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_ai_feedback';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $executionId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($executionId !== null && $executionId !== '') {
            $q->where('execution_id', $executionId);
        }

        return $q->orderByDesc('created_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }
}
