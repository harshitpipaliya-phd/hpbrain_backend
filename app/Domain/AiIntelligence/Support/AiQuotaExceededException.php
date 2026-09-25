<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use RuntimeException;

/**
 * A call was refused because the tenant's token quota is spent.
 *
 * Its own class so a caller can tell it apart from a provider failure: a quota
 * refusal is fixed by raising a limit or waiting for the period to reset, and
 * retrying will not help.
 */
class AiQuotaExceededException extends RuntimeException
{
}
