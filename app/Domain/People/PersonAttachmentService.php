<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The "how this person is attached to records" engine. Extracted from
 * PersonProfileService so the intelligence endpoint can re-use the same
 * rules without re-reading 388,401 rows.
 */
final class PersonAttachmentService
{
    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    /**
     * @return array{
     *   available: bool,
     *   rules: array<int, array<string, mixed>>,
     *   matched: array<int, array<string, mixed>>,
     *   records: int,
     *   datasets: array<int, array<string, mixed>>,
     * }
     */
    public function rules(string $tenantId, string $personId): array
    {
        try {
            $source = $this->resolver->resolve($tenantId, 'Person');
        } catch (Throwable) {
            return ['available' => false, 'rules' => [], 'matched' => [], 'records' => 0, 'datasets' => []];
        }

        $row = DB::table($source->table)
            ->where($source->primaryKey, $personId)
            ->where($source->tenantKey, $tenantId)
            ->whereNull('deleted_at')
            ->where($source->field('status'), 1)
            ->first();

        if (! $row) {
            return ['available' => false, 'rules' => [], 'matched' => [], 'records' => 0, 'datasets' => []];
        }

        $row = (array) $row;
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        $display = trim($first . ' ' . $last);
        $externalRef = isset($row[$source->field('externalRef')])
            ? (string) $row[$source->field('externalRef')]
            : null;

        $candidates = [];
        if ($externalRef !== null && $externalRef !== '') {
            $candidates[] = [
                'column' => 'subject_ref',
                'label' => 'Record subject',
                'value' => $externalRef,
                'basis' => 'The source reference on the record equals this person\'s reference in the ERP.',
            ];
        }
        if ($display !== '') {
            $candidates[] = [
                'column' => 'owner_name',
                'label' => 'Handled by',
                'value' => $display,
                'basis' => 'The name recorded in this column equals this person\'s full name.',
            ];
            $candidates[] = [
                'column' => 'supervisor_name',
                'label' => 'Supervised by',
                'value' => $display,
                'basis' => 'The name recorded in this column equals this person\'s full name.',
            ];
        }

        if ($candidates === []) {
            return ['available' => true, 'rules' => [], 'matched' => [], 'records' => 0, 'datasets' => []];
        }

        $select = ['dataset'];
        $bindings = [];
        foreach ($candidates as $i => $c) {
            $select[] = DB::raw("sum(case when `{$c['column']}` = ? then 1 else 0 end) as rule_{$i}");
            $bindings[] = $c['value'];
        }
        $select[] = DB::raw('count(*) as records');

        $rows = $this->recordQuery($tenantId, $candidates)
            ->select($select)
            ->addBinding($bindings, 'select')
            ->groupBy('dataset')
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        $rules = [];
        foreach ($candidates as $i => $c) {
            $rules[] = $c + ['records' => (int) $rows->sum('rule_' . $i)];
        }

        $datasets = $rows->map(fn ($d) => [
            'dataset' => (string) $d->dataset,
            'records' => (int) $d->records,
        ])->all();

        return [
            'available' => true,
            'rules' => $rules,
            'matched' => array_values(array_filter($rules, fn ($r) => $r['records'] > 0)),
            'records' => (int) $rows->sum('records'),
            'datasets' => $datasets,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function recordQuery(string $tenantId, array $rules)
    {
        $q = DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId);
        if ($rules === []) {
            return $q->whereRaw('1 = 0');
        }
        return $q->where(function ($w) use ($rules) {
            foreach ($rules as $rule) {
                $w->orWhere($rule['column'], $rule['value']);
            }
        });
    }
}
