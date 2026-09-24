<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

/**
 * A streaming .xlsx reader built on ZipArchive + XMLReader.
 *
 * WHY THIS EXISTS RATHER THAN phpoffice/phpspreadsheet
 * ----------------------------------------------------
 * Two reasons, in order of weight:
 *
 * 1. MEMORY. The FiberValley complaint workbook is 65,268 rows x 33 columns.
 *    PhpSpreadsheet's default reader materialises a Worksheet object graph with
 *    a Cell object per cell — roughly 2.1 million objects for that one sheet —
 *    and routinely needs a gigabyte-plus to open it. Every import here is a
 *    forward-only pass over rows, so the whole object graph is waste. This
 *    reader yields one row at a time and holds nothing but the shared-string
 *    table, which is a few MB.
 *
 * 2. DEPENDENCY BUDGET. composer.json currently has five runtime packages.
 *    PhpSpreadsheet pulls in a tree of them for a job this narrow. The brief
 *    was "minimum necessary changes"; adding no package at all beats adding a
 *    large one. ext-zip and ext-xml are already required by Laravel's own
 *    tooling and present on every supported PHP 8.2+ install.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * No formulas (cached values only — which is what an export contains), no
 * styling, no charts, no writing. If a future requirement needs any of those,
 * that is the moment to reconsider PhpSpreadsheet, not now.
 *
 * CORRECTNESS NOTES THAT COST REAL DEBUGGING
 * ------------------------------------------
 * - Sheets are addressed by NAME, resolved through workbook.xml -> rels, not by
 *   the sheet1.xml/sheet2.xml filename. Those two orders do not match: Excel
 *   keeps the tab order in workbook.xml and the file numbering reflects
 *   creation order. Reading "the second file" would silently read the wrong tab
 *   in Help_Desk_Call_Report.xlsx.
 * - Empty cells are OMITTED from the XML entirely. A row that skips column C
 *   jumps from r="B7" to r="D7". Indexing by encounter order therefore shifts
 *   every later value one column left. Cells are placed by decoding the column
 *   letters in the r attribute instead.
 * - t="s" means the value is an index into sharedStrings.xml; t="inlineStr"
 *   means the text is inline; t="b" is a boolean; no t attribute means numeric.
 *   Treating a shared-string index as a number yields plausible-looking integers
 *   in a text column, which is the worst possible failure mode.
 * - Dates arrive as Excel serial numbers with no type marker. They are NOT
 *   converted here — the reader returns what the file says and the transformer
 *   decides, because only the profile knows which columns are dates.
 */
final class XlsxReader
{
    /** Rows are yielded as list<?string>; a cell absent from the XML is null. */
    private const MAX_COLUMNS = 1024;

    private string $path;

    /** @var array<int, string> */
    private array $sharedStrings = [];

    private bool $sharedStringsLoaded = false;

    public function __construct(string $path)
    {
        if (! is_readable($path)) {
            throw new SpreadsheetException("Spreadsheet not readable: {$path}");
        }

        if (! class_exists(\ZipArchive::class)) {
            throw new SpreadsheetException('ext-zip is required to read .xlsx files.');
        }

        $this->path = $path;
    }

    /**
     * Sheet names in workbook (tab) order.
     *
     * @return array<int, string>
     */
    public function sheetNames(): array
    {
        $xml = $this->entry('xl/workbook.xml');

        if ($xml === null) {
            throw new SpreadsheetException('Not a valid .xlsx file: xl/workbook.xml missing.');
        }

        $names = [];
        $reader = new \XMLReader();
        $reader->XML($xml);

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'sheet') {
                $names[] = (string) $reader->getAttribute('name');
            }
        }

        $reader->close();

        return $names;
    }

    /**
     * Stream one sheet's rows.
     *
     * Yields row-number => list of cell values, 1-based row numbers matching
     * what the user sees in Excel. Rows that Excel omits entirely (never
     * touched) are not yielded at all, so consumers must not assume the keys
     * are contiguous.
     *
     * @return \Generator<int, array<int, ?string>>
     */
    public function rows(string $sheetName, int $skipRows = 0): \Generator
    {
        $target = $this->sheetPath($sheetName);
        $this->loadSharedStrings();

        $zip = new \ZipArchive();

        if ($zip->open($this->path) !== true) {
            throw new SpreadsheetException("Could not open archive: {$this->path}");
        }

        // Reading through the zip:// stream wrapper keeps the sheet XML off the
        // heap. The complaint sheet's XML is ~90 MB uncompressed; entry() would
        // hold all of it as a string.
        $stream = 'zip://'.$this->path.'#'.$target;

        $reader = new \XMLReader();

        if (! @$reader->open($stream)) {
            $zip->close();
            throw new SpreadsheetException("Could not read sheet XML: {$target}");
        }

        $rowNumber = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'row') {
                    continue;
                }

                $rowNumber = (int) ($reader->getAttribute('r') ?: $rowNumber + 1);

                if ($rowNumber <= $skipRows) {
                    continue;
                }

                $row = $this->readRow($reader);

                if ($row !== null) {
                    yield $rowNumber => $row;
                }
            }
        } finally {
            $reader->close();
            $zip->close();
        }
    }

    /**
     * Read every <c> inside the current <row>, placing values by column letter.
     *
     * @return array<int, ?string>|null
     */
    private function readRow(\XMLReader $reader): ?array
    {
        // An empty <row/> has no children to walk into.
        if ($reader->isEmptyElement) {
            return [];
        }

        $row = [];
        $depth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT
                && $reader->name === 'row'
                && $reader->depth === $depth) {
                break;
            }

            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'c') {
                continue;
            }

            $ref  = (string) $reader->getAttribute('r');
            $type = (string) $reader->getAttribute('t');
            $index = $ref !== '' ? $this->columnIndex($ref) : count($row);

            if ($index < 0 || $index > self::MAX_COLUMNS) {
                continue;
            }

            $row[$index] = $this->cellValue($reader, $type);
        }

        if ($row === []) {
            return [];
        }

        // Densify: fill the gaps left by omitted cells so consumers can index
        // positionally against the header row.
        $max = max(array_keys($row));
        $dense = [];

        for ($i = 0; $i <= $max; $i++) {
            $dense[$i] = $row[$i] ?? null;
        }

        return $dense;
    }

    /**
     * The text of one cell, resolved against its type.
     */
    private function cellValue(\XMLReader $reader, string $type): ?string
    {
        if ($reader->isEmptyElement) {
            return null;
        }

        $value = null;
        $depth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT
                && $reader->name === 'c'
                && $reader->depth === $depth) {
                break;
            }

            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }

            // <v> holds the value for every type except inlineStr, which uses
            // <is><t>. Formula text in <f> is skipped: we want the cached
            // result, never the expression.
            if ($reader->name === 'v' || $reader->name === 't') {
                $value = $reader->readString();
            }
        }

        if ($value === null || $value === '') {
            return null;
        }

        if ($type === 's') {
            $i = (int) $value;

            return $this->sharedStrings[$i] ?? null;
        }

        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        // 'e' is an Excel error literal (#VALUE!, #N/A). It is preserved rather
        // than nulled — "the source says #VALUE! here" is information, and the
        // Time Group column in the complaint export genuinely contains one.
        return $value;
    }

    /**
     * Decode the column letters of a cell reference into a 0-based index.
     * "A1" -> 0, "Z9" -> 25, "AA1" -> 26, "BC120" -> 54.
     */
    private function columnIndex(string $ref): int
    {
        $index = 0;
        $length = strlen($ref);

        for ($i = 0; $i < $length; $i++) {
            $char = $ref[$i];

            if ($char < 'A' || $char > 'Z') {
                break;
            }

            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /**
     * Resolve a sheet NAME to its part path via workbook.xml + the rels map.
     */
    private function sheetPath(string $sheetName): string
    {
        $workbook = $this->entry('xl/workbook.xml');
        $rels     = $this->entry('xl/_rels/workbook.xml.rels');

        if ($workbook === null || $rels === null) {
            throw new SpreadsheetException('Not a valid .xlsx file: workbook parts missing.');
        }

        $relTargets = [];
        $reader = new \XMLReader();
        $reader->XML($rels);

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'Relationship') {
                $relTargets[(string) $reader->getAttribute('Id')] = (string) $reader->getAttribute('Target');
            }
        }

        $reader->close();

        $reader = new \XMLReader();
        $reader->XML($workbook);
        $relId = null;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT
                && $reader->name === 'sheet'
                && (string) $reader->getAttribute('name') === $sheetName) {
                $relId = (string) $reader->getAttribute('r:id');
                break;
            }
        }

        $reader->close();

        if ($relId === null || ! isset($relTargets[$relId])) {
            $available = implode(', ', $this->sheetNames());
            throw new SpreadsheetException("Sheet '{$sheetName}' not found. Available: {$available}");
        }

        $target = ltrim($relTargets[$relId], '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /**
     * sharedStrings.xml is loaded once and kept. It is the one part that has to
     * be resident: cell values reference it by index in arbitrary order, so
     * streaming it per-lookup would mean re-scanning the file per cell.
     */
    private function loadSharedStrings(): void
    {
        if ($this->sharedStringsLoaded) {
            return;
        }

        $this->sharedStringsLoaded = true;

        $stream = 'zip://'.$this->path.'#xl/sharedStrings.xml';
        $reader = new \XMLReader();

        if (! @$reader->open($stream)) {
            return; // A workbook with only inline strings has no such part.
        }

        $index = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'si') {
                continue;
            }

            // <si> may hold a single <t>, or several <r><t> runs when part of
            // the string is styled differently. Concatenating the runs is the
            // only reading that reproduces what the cell displays.
            $this->sharedStrings[$index++] = $this->collectText($reader);
        }

        $reader->close();
    }

    private function collectText(\XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $parts = [];
        $depth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT
                && $reader->name === 'si'
                && $reader->depth === $depth) {
                break;
            }

            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 't') {
                $parts[] = $reader->readString();
            }
        }

        return implode('', $parts);
    }

    /**
     * Read a small archive entry fully. Only used for the workbook metadata
     * parts, never for sheet data.
     */
    private function entry(string $name): ?string
    {
        $zip = new \ZipArchive();

        if ($zip->open($this->path) !== true) {
            throw new SpreadsheetException("Could not open archive: {$this->path}");
        }

        $contents = $zip->getFromName($name);
        $zip->close();

        return $contents === false ? null : $contents;
    }
}
