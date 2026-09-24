<?php

declare(strict_types=1);

namespace App\Domain\Kasba;

/**
 * What a tenant assesses against, and what may be assessed.
 *
 * KASBA is a model of HUMAN capability. Knowledge, Ability, Skill, Behaviour and
 * Attitude are the right five words for a nurse and the wrong five for a
 * dialysis machine, whose dimensions are closer to Availability, Performance,
 * Quality and Compliance. Scoring an asset's "attitude" yields a number that
 * looks meaningful and is not — a figure nobody can act on, rendered with the
 * same authority as one they can.
 *
 * So the dimension list is per industry, and `assessableEntityTypes` says which
 * kinds of thing the model is even valid for. hpbrain_capability_assignments
 * already carries target_type/target_id, so the seam exists; this is what tells
 * it which targets a given model may be applied to.
 */
final class AssessmentModel
{
    /**
     * @param  array<int, string>  $dimensions
     * @param  array<int, string>  $assessableEntityTypes
     */
    public function __construct(
        public readonly array $dimensions,
        public readonly int $maxLevel,
        public readonly array $assessableEntityTypes,
        /** Where this model came from — 'template' or 'config'. Surfaced so a
         *  screen can say whether an industry has declared one or is on the
         *  default, which is a real difference an administrator should see. */
        public readonly string $origin,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw, string $origin): self
    {
        $dimensions = $raw['dimensions'] ?? [];

        if (! is_array($dimensions) || $dimensions === []) {
            throw new \InvalidArgumentException(
                'An assessment model needs at least one dimension. An empty model would '
                .'score everything as null and look like a measurement failure rather than '
                .'a configuration one.'
            );
        }

        $dimensions = array_values(array_map('strval', $dimensions));

        foreach ($dimensions as $dimension) {
            // Dimension names become column prefixes ("{$d}_level"), so anything
            // outside this shape could reach a query as an identifier.
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $dimension)) {
                throw new \InvalidArgumentException(
                    "Assessment dimension '{$dimension}' must be lower-case alphanumeric with "
                    .'underscores. Dimension names are used to build column names.'
                );
            }
        }

        $maxLevel = (int) ($raw['maxLevel'] ?? 5);

        if ($maxLevel < 1) {
            throw new \InvalidArgumentException('maxLevel must be at least 1.');
        }

        $targets = $raw['assessableEntityTypes'] ?? ['Person', 'OrganizationUnit'];

        return new self(
            $dimensions,
            $maxLevel,
            is_array($targets) ? array_values(array_map('strval', $targets)) : [],
            $origin,
        );
    }

    public function assesses(string $entityType): bool
    {
        return in_array($entityType, $this->assessableEntityTypes, true);
    }

    /** The `{dimension}_level` column names, in order. @return array<int, string> */
    public function levelColumns(): array
    {
        return array_map(fn (string $d) => $d.'_level', $this->dimensions);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dimensions'            => $this->dimensions,
            'maxLevel'              => $this->maxLevel,
            'assessableEntityTypes' => $this->assessableEntityTypes,
            'origin'                => $this->origin,
        ];
    }
}
