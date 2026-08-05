<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
 * The ingestion seam.
 *
 * ONE METHOD, ON PURPOSE. Every source this system will ever read from —
 * internal (the institute ERP already sharing this database) or external (a CSV
 * upload today, Drive or NetSuite later) — reduces to the same request: "give
 * me whatever is available since this checkpoint, as raw rows, and tell me
 * where they came from."
 *
 * THE RULE THIS INTERFACE EXISTS TO ENFORCE: no source-specific knowledge — CSV
 * column names, a vendor's JSON shape, an ERP's table names — may leak outside
 * app/Domain/Ingestion/Sources/. Everything past this boundary sees only
 * IngestionBatch. That is what makes a new source a new file rather than a
 * rewrite, and it is the same "adapters are disposable, the contract is the
 * seam" rule the Engineering Blueprint states for integration adapters.
 *
 * IMPLEMENTATIONS THROW ON FAILURE, NEVER RETURN EMPTY. A source that returns
 * zero rows because the connection failed is indistinguishable from a source
 * that genuinely had nothing new. This system's own rule is to report
 * UNDETERMINED rather than guess; silently returning an empty batch would be
 * guessing, and it would be recorded as a successful run of zero rows.
 */
interface DataSource
{
    /**
     * @param  string|null  $sinceCheckpoint  Opaque watermark from the last
     *         successful run, or null for a full historical read. Its meaning
     *         belongs to the implementation — a timestamp for one source, a
     *         monotonic id for another — and no caller may interpret it.
     *
     * @throws \RuntimeException on connection, auth, or read failure.
     */
    public function fetch(?string $sinceCheckpoint = null): IngestionBatch;
}
