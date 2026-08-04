<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * A profile in config/import_profiles.php is malformed, or names a column the
 * workbook does not contain. Always a developer/config error, never bad data —
 * bad data is recorded against the import job instead.
 */
final class ImportConfigurationException extends \RuntimeException
{
}
