<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Sources;

use App\Domain\Ingestion\DataSource;
use App\Domain\Ingestion\IngestionBatch;
use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Internal ingestion — the institute ERP, which already shares this database.
 *
 * THERE IS NO FILE AND NO NETWORK HOP HERE, AND THAT IS THE POINT. The Brain
 * does not copy the ERP; DATA-SYNCHRONIZATION-STRATEGY names the ERP as the
 * source of truth and the Brain as a reader. What this source does is take a
 * READING of the ERP at a point in time and hand it to the same pipeline an
 * uploaded file goes through, so that an observation drawn from internal data
 * carries the same provenance record as one drawn from an external file.
 *
 * IT NEVER NAMES AN ERP TABLE. Every table and column comes from
 * EntityResolver, which fails closed on an unmapped tenant. Writing `tbluser`
 * here would have re-introduced exactly the coupling hpbrain_entity_mappings
 * was built to remove, and would have made a hospital tenant read a school's
 * rows the moment a second industry was onboarded.
 *
 * THE CHECKPOINT IS THE PRIMARY KEY, NOT A TIMESTAMP — AND THAT IS A KNOWN
 * LIMITATION, NOT A DESIGN CHOICE. Person as mapped today (EntityMappingSeeder)
 * has no updatedAt column: the ERP's tbluser exposes joined_date and
 * deleted_at, neither of which moves when a row is EDITED. So an incremental
 * run here catches INSERTS and misses UPDATES. Recording that plainly is
 * better than checkpointing on wall-clock time, which would look complete and
 * silently drop every row written during the run itself. When an updatedAt
 * column is mapped, this class picks it up automatically — see watermarkField().
 */
final class ErpDataSource implements DataSource
{
    /** One page of ERP rows. Deliberately modest: this runs inside a request. */
    private const MAX_ROWS = 5000;

    /**
     * @param  string  $universalEntity  'Person', 'OrganizationUnit', … — a name
     *         from EntityResolver::ENTITIES, never a table name.
     */
    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly string $tenantId,
        private readonly string $universalEntity,
        private readonly string $sourceKey,
    ) {
    }

    public function fetch(?string $sinceCheckpoint = null): IngestionBatch
    {
        // resolve() throws UnsupportedEntityException for an unmapped tenant.
        // Deliberately not caught: an unmapped tenant is a configuration fault
        // that must surface as a 4xx with the entity named, not as a run that
        // reports zero rows.
        $source = $this->resolver->resolve($this->tenantId, $this->universalEntity);

        $watermark = $this->watermarkField($source);
        $columns = $source->columns($this->readableFields());

        // The primary key and tenant key are bindings rather than data, but
        // both must be selected: the key is the fallback watermark and the
        // stable external reference for every row.
        $columns['id'] = $source->primaryKey;

        $query = DB::table($source->table)
            ->where($source->tenantKey, $this->tenantId)
            ->limit(self::MAX_ROWS)
            ->orderBy($watermark);

        if ($sinceCheckpoint !== null && $sinceCheckpoint !== '') {
            $query->where($watermark, '>', $sinceCheckpoint);
        }

        // Soft deletion is not assumed — deletedAt is a mapped universal field
        // and a tenant whose ERP has no such column must not get a WHERE clause
        // naming one.
        if ($source->has('deletedAt')) {
            $query->whereNull($source->field('deletedAt'));
        }

        $select = [];

        foreach ($columns as $universal => $column) {
            $select[] = "{$column} as {$universal}";
        }

        // Aliased explicitly rather than inferred from the row's last key: the
        // watermark may or may not already be among the selected fields, and a
        // checkpoint read from the wrong column advances the sync past rows
        // that were never seen.
        $select[] = "{$watermark} as __watermark";

        $rows = [];
        $highest = null;

        foreach ($query->selectRaw(implode(', ', $select))->get() as $record) {
            $row = (array) $record;

            $mark = $row['__watermark'] ?? null;
            unset($row['__watermark']);

            foreach ($row as $key => $value) {
                $row[$key] = $value === null ? '' : (string) $value;
            }

            $rows[] = $row;

            // Highest value ACTUALLY SEEN, from an ordered read. Not max()
            // over the table, which would skip every row beyond the page
            // limit on the next run.
            if ($mark !== null && $mark !== '') {
                $highest = (string) $mark;
            }
        }

        return new IngestionBatch(
            tenantId: $this->tenantId,
            sourceKey: $this->sourceKey,
            sourceType: 'internal_erp',
            syncType: $sinceCheckpoint === null ? 'one_time_historical_import' : 'incremental_sync',
            rows: $rows,
            fetchedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            nextCheckpoint: $highest,
            // THE CHECKPOINT THIS READ STARTED FROM, NOT THE ONE IT ENDED AT.
            // source_ref is what commit re-reads, and it must reproduce the
            // rows the reviewer approved in the preview. Recording the ending
            // watermark would make commit fetch the rows AFTER the ones on
            // screen — a silent substitution of one batch for another.
            sourceRef: $this->universalEntity.'@'.($sinceCheckpoint ?? 'start'),
        );
    }

    /**
     * The column an incremental read advances on.
     *
     * Prefers a real modification timestamp when the tenant maps one; falls
     * back to the primary key, which is monotonic for inserts and blind to
     * updates. The fallback is recorded in the class docblock rather than
     * hidden here, because callers need to know their incremental sync is
     * insert-only.
     */
    private function watermarkField(\App\Domain\Universal\ResolvedSource $source): string
    {
        foreach (['updatedAt', 'modifiedDate', 'joinedDate'] as $candidate) {
            if ($source->has($candidate)) {
                return $source->field($candidate);
            }
        }

        return $source->primaryKey;
    }

    /**
     * Universal fields worth reading, skipped silently when unmapped.
     *
     * @return array<int, string>
     */
    private function readableFields(): array
    {
        return [
            'externalRef', 'firstName', 'lastName', 'email', 'phone',
            'name', 'description', 'code', 'title',
            'unit', 'position', 'status', 'joinedDate', 'updatedAt',
        ];
    }
}
