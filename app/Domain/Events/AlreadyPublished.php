<?php

declare(strict_types=1);

namespace App\Domain\Events;

use RuntimeException;

/**
 * Control-flow signal, not an error: this event's idempotency key is already in
 * hpbrain_event_store.
 *
 * It is an exception rather than a return value because it has to unwind the
 * transaction opened by EventPublisher::publishInTransaction — the domain write
 * inside it must not commit when the event it belongs to was already published.
 * It never escapes EventPublisher.
 */
final class AlreadyPublished extends RuntimeException
{
}
