<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use RuntimeException;

/**
 * A signup lost a race for a unique key.
 *
 * Distinct from a validation failure on purpose. SignupRequest checks the same
 * conditions up front and reports them per field, which is what the form needs;
 * this is the narrower case where two requests passed that check at the same
 * moment and the database settled it. Both end as a 422 to the caller — the
 * difference is that reaching this one means the transaction rolled back, so
 * there is no partial tenant to clean up.
 */
final class SignupConflictException extends RuntimeException
{
}
