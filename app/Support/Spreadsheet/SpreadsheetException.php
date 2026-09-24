<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

/**
 * Anything that makes a source workbook unreadable: missing file, not a zip,
 * missing sheet, corrupt parts. Distinct from a row-level validation failure,
 * which is recorded against the import job rather than thrown.
 */
final class SpreadsheetException extends \RuntimeException
{
}
