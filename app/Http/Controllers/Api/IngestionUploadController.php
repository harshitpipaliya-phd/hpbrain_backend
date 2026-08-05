<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ingestion\Sources\CsvUploadSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Phase 2 entry point — POST a CSV, get back real Cases/Signals/Evidence.
 *
 * Deliberately returns the extraction preview rather than committing
 * directly to the graph — matches the real, honest pattern already
 * established (Extraction preview before Provenance commit). A human
 * confirms the field mapping looks right before anything is written.
 */
final class IngestionUploadController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:20480',
            'source_id' => 'required|string',
        ]);

        $path = $request->file('file')->store('ingestion-uploads');
        $source = new CsvUploadSource(
            filePath: storage_path('app/' . $path),
            sourceId: $request->input('source_id'),
        );

        $batch = $source->fetch();

        // Real field mapping happens here — matching column names to
        // Case/Signal/Evidence, per whichever mapping config exists for
        // this source_id. Deliberately not built out in this first pass;
        // the ISP data and the scholarclone task CSVs have different real
        // shapes, so this is the next real decision, not something to
        // guess generically here.
        return response()->json([
            'source_id' => $batch->sourceId,
            'sync_type' => $batch->syncType,
            'row_count' => count($batch->rows),
            'sample_rows' => array_slice($batch->rows, 0, 3),
            'fetched_at' => $batch->fetchedAt->format(\DateTimeInterface::ATOM),
            'status' => 'preview_only_not_committed',
        ]);
    }
}
