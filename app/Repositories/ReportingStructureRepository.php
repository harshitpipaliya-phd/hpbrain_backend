<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class ReportingStructureRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_reporting_structures';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $orgId = null, ?string $reportingType = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null) {
            $q->where('org_id', $orgId);
        }
        if ($reportingType !== null) {
            $q->where('reporting_type', $reportingType);
        }

        return $q->orderByDesc('start_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByReporter(string $tenantId, string $personId): array
    {
        return $this->scoped($tenantId)->where('reporter_person_id', $personId)->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function findByReportee(string $tenantId, string $personId): array
    {
        return $this->scoped($tenantId)->where('reportee_person_id', $personId)->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'                  => $id,
            'tenant_id'           => $tenantId,
            'org_id'              => $data['org_id'] ?? null,
            'reporter_person_id'  => $data['reporter_person_id'],
            'reportee_person_id'  => $data['reportee_person_id'],
            'reporting_type'      => $data['reporting_type'] ?? 'direct',
            'unit_id'             => $data['unit_id'] ?? null,
            'start_date'          => $data['start_date'] ?? null,
            'end_date'            => $data['end_date'] ?? null,
            'metadata'            => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_by'          => $data['created_by'],
            'created_date'        => $now,
            'updated_date'        => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'org_id'             => 'org_id',
            'reporter_person_id' => 'reporter_person_id',
            'reportee_person_id' => 'reportee_person_id',
            'reporting_type'     => 'reporting_type',
            'unit_id'            => 'unit_id',
            'start_date'         => 'start_date',
            'end_date'           => 'end_date',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('metadata', $data)) {
            $fields['metadata'] = $data['metadata'] !== null ? json_encode($data['metadata']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
