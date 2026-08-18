<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Sources;

use App\Domain\Ingestion\DataSource;
use App\Domain\Ingestion\IngestionBatch;

/**
 * External ingestion — a file already on disk after upload.
 *
 * The simplest possible real source: no auth, no third party, no network. It
 * exists to prove the DataSource → FieldMap → Signal/Evidence path end to end
 * before the harder sources are attempted.
 *
 * NATIVE fgetcsv, NOT league/csv. ImportEngine already parses CSV this way, so
 * adding a Composer dependency would have meant two parsers in one codebase for
 * one file format — and in an environment where `composer require` has been a
 * live blocker, it would have made the whole feature un-installable for the
 * sake of convenience. The three things league/csv would have given us for free
 * are handled explicitly below: BOM, ragged rows, duplicate headers.
 *
 * A CSV UPLOAD IS ALWAYS A FULL HISTORICAL BATCH. $sinceCheckpoint is accepted
 * and ignored, because a file has no "since". It stays in the signature because
 * every other source needs it, and an interface that varied per source would
 * defeat the point of having one.
 */
final class CsvUploadSource implements DataSource
{
    /**
     * Rows are held in memory, so the file size is bounded at the HTTP layer.
     * This cap is the second line of defence: materialising rows as PHP arrays
     * is where the memory goes, not the file itself.
     *
     * RAISED FROM 50,000, which rejected ordinary organization exports — a
     * 65,000-row employee file is a normal size for this product and was
     * refused outright. 200,000 rows of ~30 narrow columns is roughly 240 MB of
     * PHP arrays against the configured 512 MB memory_limit, which is the real
     * ceiling here; raising this further without moving to a streaming reader
     * would trade a clear error for an out-of-memory fatal.
     *
     * IT THROWS RATHER THAN TRUNCATING, deliberately. A cap that silently kept
     * the first N rows would produce totals that look plausible and are wrong,
     * which is the one failure mode this product cannot tolerate.
     */
    private const MAX_ROWS = 200000;

    /**
     * The ceiling for the STREAMING delimited reader.
     *
     * Ten times MAX_ROWS, and it can be because memory no longer scales with
     * the row count — readCsvStreaming() holds one chunk, a header list and a
     * fifty-row sample, whatever the file. What this cap now bounds is TIME and
     * the size of the resulting import, not memory: two million rows at the
     * measured ~200 rows/second against the remote database is several hours of
     * committing, which is a decision an operator should make deliberately with
     * an incremental source rather than by dragging a file onto a form.
     *
     * Still throws rather than truncating, for the same reason MAX_ROWS does.
     */
    private const MAX_STREAM_ROWS = 2000000;

    /**
     * Rows kept in memory for preview and schema detection.
     *
     * SchemaDetector infers column types and entity hints from these, so it has
     * to be enough rows for a type to be evident and few enough to be free. The
     * preview endpoint shows three.
     */
    private const SAMPLE_ROWS = 50;

    /**
     * @param  string|null  $originalName       the client filename, for provenance
     * @param  string|null  $originalExtension  the VALIDATED client extension; when
     *        null the extension is read off $filePath, which is only correct if the
     *        caller named the stored file itself.
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $filePath,
        private readonly string $sourceKey,
        private readonly ?string $originalName = null,
        private readonly ?string $originalExtension = null,
    ) {
    }

    /**
     * The format to parse as.
     *
     * PREFERS THE CALLER'S EXTENSION OVER THE STORED PATH'S, and that ordering
     * is the whole point. Laravel's ->store() names uploads from a MIME sniff,
     * so an ordinary CSV — which sniffs as text/plain — was written as
     * `<hash>.txt`. Reading the extension back off that path made fetch() take
     * the plain-text branch and collapse the entire file into a single document
     * row: sixty-five thousand rows in, one row out, no error raised.
     *
     * The path is still the fallback, because ErpDataSource-style callers and
     * the existing tests construct this class with a real filename and no
     * explicit extension.
     */
    private function format(): string
    {
        if ($this->originalExtension !== null && $this->originalExtension !== '') {
            return strtolower($this->originalExtension);
        }

        return strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
    }

    public function fetch(?string $sinceCheckpoint = null): IngestionBatch
    {
        if (! is_readable($this->filePath)) {
            // Thrown, not returned as an empty batch — see the DataSource
            // docblock. This is also the exact failure the Laravel 11 default
            // disk root produces when a caller builds the path by hand:
            // ->store() writes under storage/app/private, storage_path('app/…')
            // points one directory too high, and is_readable() is false.
            throw new \RuntimeException("Cannot read upload at {$this->filePath}");
        }

        $extension = $this->format();

        if ($extension === 'json') {
            return $this->fromRows($this->readJson(), 'json_upload');
        }

        if ($extension === 'xlsx') {
            try {
                return $this->fromRows($this->readXlsx(), 'xlsx_upload');
            } catch (\RuntimeException) {
                return $this->fromRows($this->readBinaryMetadata('xlsx'), 'xlsx_upload');
            }
        }

        if (in_array($extension, ['txt', 'md', 'markdown', 'xml', 'html', 'htm', 'sql'], true)) {
            return $this->fromRows($this->readText($extension), "{$extension}_upload");
        }

        if (! in_array($extension, ['csv', 'tsv'], true)) {
            return $this->fromRows($this->readBinaryMetadata($extension), "{$extension}_upload");
        }

        return $this->readCsvStreaming($this->detectDelimiter());
    }

    /**
     * Delimited files, read WITHOUT ever holding the whole file in memory.
     *
     * WHY THIS REPLACED THE ARRAY READ. The previous implementation appended
     * every row to a PHP array and refused anything past MAX_ROWS, because
     * ~240 MB of arrays against a 512 MB memory_limit was the real ceiling. A
     * 388,401-row export was therefore rejected with `unreadable_upload` — the
     * cap doing exactly what it was designed to do, and being useless anyway
     * because the file was legitimate.
     *
     * ONE SCAN UP FRONT, then a fresh scan per read. The first pass counts rows
     * and keeps a small sample, because the batch has to be able to answer
     * count() and headers() without consuming anything — the preview, the queue
     * threshold and the import job's total_rows all need the count before a
     * single row is committed. Every later read reopens the file through the
     * factory handed to IngestionBatch, so peak memory is one chunk regardless
     * of file size.
     *
     * A COUNTING PASS IS CHEAP AND A GUESS IS NOT. Counting 388,401 rows costs
     * well under a second of sequential I/O; estimating from file size would
     * put a wrong total in front of the reviewer approving the import, and the
     * whole point of the preview is that the number is real.
     *
     * MAX_ROWS STILL APPLIES, at a far higher streaming ceiling, and still
     * throws rather than truncating. Silently keeping the first N rows would
     * produce plausible wrong totals, which remains the one outcome this
     * product cannot tolerate.
     */
    private function readCsvStreaming(string $delimiter): IngestionBatch
    {
        $headers = [];
        $sample  = [];
        $count   = 0;

        $handle = fopen($this->filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open upload at {$this->filePath}");
        }

        try {
            $headers = $this->readHeaders($handle, $delimiter);

            while (($record = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                // fgetcsv yields [null] for a blank line. Skipping it here
                // keeps blank separator lines out of the row count, which is
                // the number the reviewer checks the preview against.
                if ($record === [null] || $record === []) {
                    continue;
                }

                if ($count >= self::MAX_STREAM_ROWS) {
                    throw new \RuntimeException(
                        'CSV exceeds '.self::MAX_STREAM_ROWS.' rows; split the file or use an incremental source.'
                    );
                }

                if ($count < self::SAMPLE_ROWS) {
                    $sample[] = $this->combine($headers, $record);
                }

                $count++;
            }
        } finally {
            fclose($handle);
        }

        $path = $this->filePath;
        $combine = fn (array $record): array => $this->combine($headers, $record);

        /**
         * Reopens the file on every call. See the IngestionBatch docblock on
         * why this is a factory rather than a Generator: the batch is read more
         * than once, and a half-consumed iterator would commit a partial file.
         */
        $stream = static function () use ($path, $delimiter, $headers, $combine): \Generator {
            $fh = fopen($path, 'r');

            if ($fh === false) {
                throw new \RuntimeException("Cannot reopen upload at {$path}");
            }

            try {
                // Skip the header line the same way the counting pass did.
                fgetcsv($fh, 0, $delimiter, '"', '\\');

                while (($record = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
                    if ($record === [null] || $record === []) {
                        continue;
                    }

                    yield $combine($record);
                }
            } finally {
                fclose($fh);
            }
        };

        return new IngestionBatch(
            tenantId: $this->tenantId,
            sourceKey: $this->sourceKey,
            sourceType: 'csv_upload',
            syncType: 'one_time_historical_import',
            rows: [],
            fetchedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            nextCheckpoint: null,
            sourceRef: $this->filePath,
            rowStream: $stream,
            streamCount: $count,
            streamHeaders: $headers,
            streamSample: $sample,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJson(): array
    {
        $raw = file_get_contents($this->filePath);

        if ($raw === false) {
            throw new \RuntimeException("Cannot read JSON upload at {$this->filePath}");
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON upload is invalid: '.json_last_error_msg());
        }

        $rows = array_is_list($decoded) ? $decoded : [$decoded];

        return array_map(function ($row, int $index) {
            if (is_array($row)) {
                return $this->flatten($row);
            }

            return [
                'title' => basename($this->filePath).' item '.($index + 1),
                'state' => 'uploaded',
                'evidence_text' => is_scalar($row) ? (string) $row : json_encode($row),
                'external_ref' => (string) ($index + 1),
            ];
        }, $rows, array_keys($rows));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readXlsx(): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('XLSX upload requires the Zip PHP extension.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($this->filePath) !== true) {
            throw new \RuntimeException("Cannot open XLSX upload at {$this->filePath}");
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheet === false) {
            $zip->close();
            throw new \RuntimeException('XLSX upload does not contain a readable first sheet.');
        }

        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        $sharedStrings = [];

        if ($sharedStringsXml !== false) {
            $ssXml = simplexml_load_string($sharedStringsXml);
            if ($ssXml !== false) {
                $ssNs = $ssXml->getNamespaces(true);
                $ssRoot = $ssXml->children($ssNs[''] ?? null);

                foreach ($ssRoot->si as $si) {
                    $siChildren = $si->children($ssNs[''] ?? null);
                    $text = (string) ($siChildren->t ?? $siChildren->phoneticPr ?? '');
                    $sharedStrings[] = $text;
                }
            }
        }

        $zip->close();

        $xml = simplexml_load_string($sheet);

        if ($xml === false) {
            throw new \RuntimeException('XLSX first sheet is not valid XML.');
        }

        $ns = $xml->getNamespaces(true);
        $sheetData = $xml->children($ns[''] ?? null)->sheetData;
        $rows = [];
        $headers = [];

        foreach ($sheetData->row as $row) {
            $cells = [];
            $rowIndex = (string) $row['r'];

            foreach ($row->c as $cell) {
                $cellRef = (string) $cell['r'];
                $column = preg_replace('/[0-9]/', '', $cellRef) ?: 'A';
                $type = (string) $cell['t'];
                $value = (string) $cell->v;

                if ($type === 's' && $value !== '' && isset($sharedStrings[(int) $value])) {
                    $value = $sharedStrings[(int) $value];
                }

                $cells[$column] = $value;
            }

            if (empty($headers)) {
                $headers = array_values($cells);
                continue;
            }

            if (count($cells) === 0) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new \RuntimeException(
                    'XLSX exceeds '.self::MAX_ROWS.' rows; split the file or use an incremental source.'
                );
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $columnLetters = $this->columnLettersForIndex($index);
                $row[$header] = trim((string) ($cells[$columnLetters] ?? ''));
            }

            $rows[] = $row;
        }

        if ($headers === []) {
            throw new \RuntimeException('XLSX has no header row.');
        }

        return $this->fromRows($rows, 'xlsx_upload');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readText(string $extension): array
    {
        $raw = file_get_contents($this->filePath);

        if ($raw === false) {
            throw new \RuntimeException("Cannot read upload at {$this->filePath}");
        }

        return [[
            'title' => basename($this->filePath),
            'state' => 'uploaded',
            'evidence_text' => trim($raw),
            'external_ref' => hash('sha256', $this->filePath.'|'.filesize($this->filePath)),
            'file_type' => $extension,
            'file_size_bytes' => (string) filesize($this->filePath),
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readBinaryMetadata(string $extension): array
    {
        return [[
            'title' => basename($this->filePath),
            'state' => 'uploaded',
            'evidence_text' => 'Binary file uploaded for AI ingestion preview. Install a format-specific extractor to index full content.',
            'external_ref' => hash_file('sha256', $this->filePath) ?: basename($this->filePath),
            'file_type' => $extension,
            'file_size_bytes' => (string) filesize($this->filePath),
        ]];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function flatten(array $row, string $prefix = ''): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $out += $this->flatten($value, $name);
            } else {
                $out[$name] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fromRows(array $rows, string $sourceType): IngestionBatch
    {
        return new IngestionBatch(
            tenantId: $this->tenantId,
            sourceKey: $this->sourceKey,
            sourceType: $sourceType,
            syncType: 'one_time_historical_import',
            rows: $rows,
            fetchedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            // No watermark exists for a file. Recording null keeps the run
            // honestly labelled as a one-off rather than seeding a checkpoint
            // that would make the NEXT run skip real rows.
            nextCheckpoint: null,
            sourceRef: $this->filePath,
        );
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>
     */
    /**
     * Which character separates this file's fields.
     *
     * fgetcsv defaults to a comma, so a semicolon-delimited export — what Excel
     * produces on any machine with a comma decimal separator, i.e. most of
     * Europe — parsed as ONE column whose name was the entire header line. It
     * did not error; it produced a single-column dataset, which is worse.
     *
     * Decided by counting candidates in the header line and taking the most
     * frequent. The header is the right line to sample because it is the one
     * line guaranteed to contain every separator exactly once between fields
     * and no free text with stray punctuation. A tie falls back to comma.
     */
    private function detectDelimiter(): string
    {
        if ($this->format() === 'tsv') {
            return "	";
        }

        $handle = fopen($this->filePath, 'r');

        if ($handle === false) {
            return ',';
        }

        $line = fgets($handle, 65536);
        fclose($handle);

        if ($line === false || $line === '') {
            return ',';
        }

        // Quoted sections are stripped first, so a comma inside "Smith, John"
        // cannot outvote the real separator.
        $unquoted = (string) preg_replace('/"[^"]*"/', '', $line);

        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "	", '|'] as $candidate) {
            $count = substr_count($unquoted, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function readHeaders($handle, string $delimiter = ','): array
    {
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');

        if ($headers === false || $headers === [null]) {
            throw new \RuntimeException('CSV has no header row.');
        }

        $seen = [];
        $clean = [];

        foreach ($headers as $index => $header) {
            $name = trim((string) $header);

            // Excel writes a UTF-8 BOM at the start of the file, which lands
            // inside the FIRST header name. Left in place it makes "Subject"
            // and "\u{FEFF}Subject" different keys, so a field map saved from
            // one export silently matches nothing in the next.
            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            if ($name === '') {
                $name = 'column_'.($index + 1);
            }

            // Duplicate headers are common in exported spreadsheets. Left
            // alone, array_combine keeps only the last one and the earlier
            // column vanishes without a word.
            if (isset($seen[$name])) {
                $seen[$name]++;
                $name .= '_'.$seen[$name];
            } else {
                $seen[$name] = 1;
            }

            $clean[] = $name;
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string|null>  $record
     * @return array<string, string>
     */
    private function combine(array $headers, array $record): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            // Ragged rows are the normal case in hand-edited exports.
            // array_combine() would throw on a length mismatch and abort the
            // whole file; a short row is far more likely to be a trailing
            // empty cell than a corrupt file, so the missing tail reads as ''.
            $row[$header] = trim((string) ($record[$index] ?? ''));
        }

        return $row;
    }

    /**
     * Convert a 0-based column index to Excel column letters.
     *
     * @return string
     */
    private static function columnLettersForIndex(int $index): string
    {
        $letters = '';

        do {
            $remainder = $index % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = (int) ($index / 26) - 1;
        } while ($index >= 0);

        return $letters;
    }
}
