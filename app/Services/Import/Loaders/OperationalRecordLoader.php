<?php

declare(strict_types=1);

namespace App\Services\Import\Loaders;

use App\Services\Import\ImportProfile;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Writes Brain-owned operational facts into hpbrain_operational_records.
 *
 * IDEMPOTENCY, in three tiers:
 *   1. UNIQUE (tenant_id, dataset, natural_key) makes duplication impossible at
 *      the storage layer regardless of what this class does.
 *   2. An existing key is UPDATED, so a corrected export overwrites rather than
 *      accumulating a second version of the same ticket.
 *   3. row_hash short-circuits tier 2: a row whose content is byte-identical to
 *      what is stored is skipped without a write. Re-importing an unchanged
 *      65k-row workbook costs 65k hash comparisons and zero UPDATEs, which is
 *      the difference between a re-run taking seconds and taking minutes.
 *
 * Existing keys are pre-loaded once per import rather than queried per row. At
 * 65,268 rows the per-row SELECT was the entire cost of the import; one indexed
 * scan of (tenant_id, dataset) replaces it.
 */
final class OperationalRecordLoader implements RecordLoader
{
    private const TABLE = 'hpbrain_operational_records';

    /** @var array<string, string> natural_key => row_hash */
    private array $existingHashes = [];

    /** @var array<string, string> natural_key => id */
    private array $existingIds = [];

    private bool $primed = false;

    /** @var array<int, string> */
    private array $created = [];

    /** @var array<int, array<string, mixed>> */
    private array $insertBuffer = [];

    /** @var array<string, int> natural_key => position in $insertBuffer */
    private array $bufferIndex = [];

    private const INSERT_CHUNK = 2000;

    public function load(string $tenantId, ImportProfile $profile, string $naturalKey, array $fields, array $context): array
    {
        $this->prime($tenantId, $profile->dataset());

        $payload = $fields['payload'] ?? [];
        $payloadJson = $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);

        $row = [
            'tenant_id'       => $tenantId,
            'org_id'          => $context['org_id'] ?? null,
            'dataset'         => $profile->dataset(),
            'natural_key'     => $naturalKey,
            'source_file'     => $context['source_file'] ?? null,
            'source_row'      => $context['source_row'] ?? null,
            'occurred_at'     => $fields['occurred_at'] ?? null,
            'closed_at'       => $fields['closed_at'] ?? null,
            'status'          => $this->clip($fields['status'] ?? null, 64),
            'category'        => $this->clip($fields['category'] ?? null, 191),
            'sub_category'    => $this->clip($fields['sub_category'] ?? null, 191),
            'owner_name'      => $this->clip($fields['owner_name'] ?? null, 191),
            'supervisor_name' => $this->clip($fields['supervisor_name'] ?? null, 191),
            'zone'            => $this->clip($fields['zone'] ?? null, 128),
            'area'            => $this->clip($fields['area'] ?? null, 128),
            'subject_ref'     => $this->clip($fields['subject_ref'] ?? null, 191),
            'metric_value'    => $fields['metric_value'] ?? null,
            'metric_unit'     => $this->clip($fields['metric_unit'] ?? null, 20),
            'quantity'        => $fields['quantity'] ?? null,
            'payload'         => $payloadJson,
            'import_job_id'   => $context['import_job_id'] ?? null,
        ];

        // The hash covers the content, NOT the bookkeeping. source_row and
        // import_job_id change on every run by definition; including them would
        // make every row look modified and defeat the skip entirely.
        $hashable = $row;
        unset($hashable['source_row'], $hashable['import_job_id'], $hashable['source_file']);
        $row['row_hash'] = hash('sha256', json_encode($hashable, JSON_UNESCAPED_UNICODE) ?: '');

        if (isset($this->existingHashes[$naturalKey])) {
            if ($this->existingHashes[$naturalKey] === $row['row_hash']) {
                return ['action' => 'skipped', 'entityId' => $this->existingIds[$naturalKey] ?? null];
            }

            // A key seen earlier in THIS run is still sitting in the insert
            // buffer and has never reached the database, so an UPDATE would
            // match zero rows and the buffered (earlier) value would win. On a
            // re-import the row does exist, so the UPDATE lands and the later
            // value wins. Same file, two different outcomes depending on
            // whether it had been imported before — which broke convergence:
            // the table only settled after a second run.
            //
            // The source genuinely contains duplicates (241 repeat attendance
            // submissions, 35 of them contradicting each other), so the rule has
            // to be stated and applied consistently: LAST OCCURRENCE WINS,
            // whether or not the row has been flushed yet.
            if (isset($this->bufferIndex[$naturalKey])) {
                $position = $this->bufferIndex[$naturalKey];
                $row['id'] = $this->insertBuffer[$position]['id'];
                $this->insertBuffer[$position] = $row;
                $this->existingHashes[$naturalKey] = $row['row_hash'];

                return ['action' => 'updated', 'entityId' => $row['id']];
            }

            DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('dataset', $profile->dataset())
                ->where('natural_key', $naturalKey)
                ->update($row);

            $this->existingHashes[$naturalKey] = $row['row_hash'];

            return ['action' => 'updated', 'entityId' => $this->existingIds[$naturalKey] ?? null];
        }

        $id = Uuid::uuid4()->toString();
        $row['id'] = $id;

        // Buffered so 65,268 rows become ~131 multi-row INSERTs rather than
        // 65,268 round trips.
        $this->bufferIndex[$naturalKey] = count($this->insertBuffer);
        $this->insertBuffer[] = $row;
        $this->existingHashes[$naturalKey] = $row['row_hash'];
        $this->existingIds[$naturalKey] = $id;
        $this->created[] = $id;

        if (count($this->insertBuffer) >= self::INSERT_CHUNK) {
            $this->flush();
        }

        return ['action' => 'created', 'entityId' => $id];
    }

    /**
     * Must be called once at the end of an import. The orchestrator does this
     * in a finally block so a mid-import failure still persists what succeeded
     * and still reports honest counts.
     */
    public function flush(): void
    {
        if ($this->insertBuffer === []) {
            return;
        }

        DB::table(self::TABLE)->insert($this->insertBuffer);
        $this->insertBuffer = [];

        // The positions this map holds are only meaningful while the buffer
        // exists. Leaving stale indexes behind would send a later duplicate to
        // a position in an emptied array.
        $this->bufferIndex = [];
    }

    public function createdIds(): array
    {
        return $this->created === [] ? [] : ['operational_records' => $this->created];
    }

    private function prime(string $tenantId, string $dataset): void
    {
        if ($this->primed) {
            return;
        }

        $this->primed = true;

        DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset)
            ->select('id', 'natural_key', 'row_hash')
            ->orderBy('id')
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $this->existingHashes[$row->natural_key] = (string) $row->row_hash;
                    $this->existingIds[$row->natural_key] = (string) $row->id;
                }
            });
    }

    /**
     * Truncate to the column width rather than letting MySQL do it.
     *
     * In strict mode an over-length value is an error that aborts the whole
     * insert chunk; in non-strict mode it is a silent truncation. Neither is
     * acceptable for a free-text field like 'Process Code', whose longest real
     * value is 43 characters but whose next export could be longer. mb_substr
     * so a multi-byte character is never cut in half.
     */
    private function clip(mixed $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
