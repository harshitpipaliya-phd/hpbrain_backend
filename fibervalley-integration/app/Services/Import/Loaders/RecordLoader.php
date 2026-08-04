<?php

declare(strict_types=1);

namespace App\Services\Import\Loaders;

use App\Services\Import\ImportProfile;

/**
 * Where a mapped row ends up.
 *
 * Two implementations, because FiberValley's workbooks contain two genuinely
 * different kinds of data and the existing architecture already draws the line
 * between them (docs/ERP-TO-BRAIN-MAPPING.md):
 *
 *   OperationalRecordLoader -> hpbrain_operational_records  (Brain-owned facts)
 *   ErpRosterLoader         -> hrms_departments + tbluser   (ERP master data)
 *
 * Both must be idempotent. Running the same import twice is the normal case,
 * not an error case — next month's export will contain this month's rows again.
 */
interface RecordLoader
{
    /**
     * Write one mapped record. Returns the action taken, which becomes the
     * hpbrain_import_logs.action value: 'created', 'updated' or 'skipped'.
     *
     * @param  array<string, mixed>  $fields
     * @return array{action: string, entityId: ?string}
     */
    public function load(string $tenantId, ImportProfile $profile, string $naturalKey, array $fields, array $context): array;

    /**
     * Ids created by this loader during the run, for the import job's
     * rollback_data. ImportEngine::rollbackImport() already knows how to undo a
     * job from that structure — this is what finally gives it something to
     * undo.
     *
     * @return array<string, array<int, string>> entityType => ids
     */
    public function createdIds(): array;
}
