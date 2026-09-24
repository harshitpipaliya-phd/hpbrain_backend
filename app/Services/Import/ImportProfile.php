<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One entry from config/import_profiles.php, validated and given accessors.
 *
 * Exists so that a typo in the config surfaces as a named exception at load
 * time rather than as a null column value 40,000 rows into an import.
 */
final class ImportProfile
{
    private function __construct(
        public readonly string $orgSlug,
        public readonly string $key,
        public readonly array $config,
    ) {
    }

    public static function fromConfig(string $orgSlug, string $key, array $config): self
    {
        foreach (['file', 'sheet', 'loader'] as $required) {
            if (empty($config[$required])) {
                throw new ImportConfigurationException(
                    "Import profile '{$orgSlug}.{$key}' is missing required setting '{$required}'."
                );
            }
        }

        $shape = $config['shape'] ?? 'tabular';

        if (! in_array($shape, ['tabular', 'matrix'], true)) {
            throw new ImportConfigurationException(
                "Import profile '{$orgSlug}.{$key}' has unknown shape '{$shape}'."
            );
        }

        if ($shape === 'matrix' && empty($config['matrix']['row_key'])) {
            throw new ImportConfigurationException(
                "Import profile '{$orgSlug}.{$key}' is shape 'matrix' but declares no matrix.row_key."
            );
        }

        if ($shape === 'tabular' && $config['loader'] === 'operational' && empty($config['key'])) {
            throw new ImportConfigurationException(
                "Import profile '{$orgSlug}.{$key}' must declare a natural 'key'; without one, "
                .'re-importing the same workbook would duplicate every row.'
            );
        }

        return new self($orgSlug, $key, $config);
    }

    public function filePattern(): string
    {
        return (string) $this->config['file'];
    }

    public function sheet(): string
    {
        return (string) $this->config['sheet'];
    }

    public function headerRow(): int
    {
        return (int) ($this->config['header_row'] ?? 1);
    }

    public function shape(): string
    {
        return (string) ($this->config['shape'] ?? 'tabular');
    }

    public function isMatrix(): bool
    {
        return $this->shape() === 'matrix';
    }

    public function loader(): string
    {
        return (string) $this->config['loader'];
    }

    /** operational_records.dataset; irrelevant for the erp_roster loader. */
    public function dataset(): string
    {
        return (string) ($this->config['dataset'] ?? $this->key);
    }

    /** @return array<int, string> */
    public function keyColumns(): array
    {
        return (array) ($this->config['key'] ?? []);
    }

    /** @return array<int, string> */
    public function requiredColumns(): array
    {
        return (array) ($this->config['required'] ?? []);
    }

    /** @return array<string, string> target => source header */
    public function map(): array
    {
        return (array) ($this->config['map'] ?? []);
    }

    /** @return array<string, string> target => cast name */
    public function casts(): array
    {
        return (array) ($this->config['casts'] ?? []);
    }

    /** @return array<string, mixed> target => literal value */
    public function constants(): array
    {
        return (array) ($this->config['constants'] ?? []);
    }

    /** @return array<string, string> target => derivation rule */
    public function derivations(): array
    {
        return (array) ($this->config['derive'] ?? []);
    }

    /** @return array<int, string> source headers copied into payload */
    public function payloadColumns(): array
    {
        return (array) ($this->config['payload'] ?? []);
    }

    /** @return array<string, mixed> */
    public function matrix(): array
    {
        return (array) ($this->config['matrix'] ?? []);
    }

    /** @return array<int, string> */
    public function activeFlags(): array
    {
        return (array) ($this->config['active_flags'] ?? []);
    }

    public function label(): string
    {
        return "{$this->orgSlug}.{$this->key}";
    }
}
