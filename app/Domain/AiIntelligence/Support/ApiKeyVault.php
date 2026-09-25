<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider credentials, encrypted at rest.
 *
 * G2G stores `ai_api_keys.api_key` in plaintext. Here the column only ever holds
 * `Crypt::encryptString()` output (APP_KEY-bound, authenticated), so a database
 * dump, a replica or a slow-query log does not carry a usable credential.
 *
 * The plaintext exists in exactly two places: the resolver that hands it to the
 * HTTP client, and `preview()`, which reduces it to a masked hint. No response,
 * audit row or log line receives it.
 */
final class ApiKeyVault
{
    public function seal(string $plain): string
    {
        return Crypt::encryptString(trim($plain));
    }

    /**
     * The plaintext, or null when the stored value cannot be opened.
     *
     * A value that fails to decrypt (APP_KEY rotated, row written by hand) is a
     * credential that cannot be used; returning null makes it resolve as "no key"
     * — a clean not-configured answer — instead of sending ciphertext to a vendor.
     */
    public function open(?string $sealed): ?string
    {
        $sealed = trim((string) $sealed);

        if ($sealed === '' || $sealed === '-') {
            return null;
        }

        try {
            $plain = trim(Crypt::decryptString($sealed));
        } catch (Throwable $exception) {
            Log::warning('[ai.intelligence] a stored provider credential could not be decrypted', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return $plain === '' ? null : $plain;
    }

    /**
     * Enough of a key to recognise it, never enough to use it.
     *
     * Same rule as G2G: a short value is masked entirely, a long one shows its
     * first and last four characters.
     */
    public function preview(?string $sealed): ?string
    {
        $key = $this->open($sealed);

        if ($key === null) {
            return trim((string) $sealed) === '' ? null : str_repeat('•', 8);
        }

        if (mb_strlen($key) < 16) {
            return str_repeat('•', 8);
        }

        return mb_substr($key, 0, 4) . str_repeat('•', 8) . mb_substr($key, -4);
    }
}
