<?php

declare(strict_types=1);

namespace App\Repositories;

final class AiExecutionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_ai_executions';
    }

    protected function jsonColumns(): array
    {
        return [];
    }

    public function list(string $tenantId, array $filters = []): array
    {
        $q = $this->scoped($tenantId);

        if (!empty($filters['userId'])) {
            $q->where('user_id', $filters['userId']);
        }

        if (!empty($filters['service'])) {
            $q->where('service_name', $filters['service']);
        }

        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->orderByDesc('created_date')->limit(200)->get()->map(fn ($r) => (array) $r)->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? (array) $row : null;
    }
}
