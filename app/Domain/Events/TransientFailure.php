<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Thrown when an event handler fails transiently and should be retried.
 */
final class TransientFailure extends \RuntimeException
{
}
