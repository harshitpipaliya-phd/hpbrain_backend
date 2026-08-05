<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
 * The ingestion seam.
 *
 * ONE METHOD, ON PURPOSE — same discipline as AiProvider (ADR-004). Every
 * real source this system will ever ingest from, internal (K-12's own
 * database, G2G's own database) or external (a CSV upload, eventually
 * Google Drive/NetSuite/Slack), reduces to the same shape: "pull whatever
 * is available since the last checkpoint, return it as raw rows plus
 * where they came from."
 *
 * THE RULE THIS INTERFACE EXISTS TO ENFORCE: no source-specific parsing
 * logic (CSV column names, a specific API's JSON shape, a specific
 * database's table names) may leak outside app/Domain/Ingestion/Sources/.
 * The rest of the system — field mapping, Case/Signal/Evidence
 * extraction, the graph — must only ever see IngestionBatch, never a
 * raw CSV row or a raw API response. This is what makes adding a new
 * source (Phase 3: Google Drive, NetSuite) a new file, not a rewrite.
 *
 * Implementations THROW on connection/auth failure — same reasoning as
 * AiProvider. A source that silently returns zero rows because the
 * connection failed is indistinguishable from a source that genuinely
 * had nothing new, and that ambiguity is exactly what this system's own
 * "never fabricate, report UNDETERMINED honestly" principle exists to
 * prevent.
 */
interface DataSource
{
    public function fetch(?string $sinceCheckpoint = null): IngestionBatch;
}
