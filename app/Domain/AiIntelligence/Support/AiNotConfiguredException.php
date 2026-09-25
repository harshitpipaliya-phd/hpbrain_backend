<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use RuntimeException;

/**
 * A model call could not be made because nothing is configured to make it with.
 *
 * Not a fault: a tenant that has not saved a credential is in a normal state, and
 * the message names the screen that fixes it. AiIntelligenceController renders a
 * RuntimeException as a 422 with its message, which is the treatment this needs.
 */
class AiNotConfiguredException extends RuntimeException
{
    public static function forModule(string $moduleLabel, string $providerLabel): self
    {
        return new self(sprintf(
            '%s has no usable credential. It resolves to %s, but no API key is saved for '
            . 'that provider and none is set in the environment. Add one under '
            . 'AI & Intelligence → AI Providers.',
            $moduleLabel,
            $providerLabel
        ));
    }

    public static function providerNotDriveable(string $providerLabel): self
    {
        return new self(sprintf(
            '%s cannot be called from this platform. Choose a different provider under '
            . 'AI & Intelligence → AI Providers.',
            $providerLabel
        ));
    }
}
