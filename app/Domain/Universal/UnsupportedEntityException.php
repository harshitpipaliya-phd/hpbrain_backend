<?php

declare(strict_types=1);

namespace App\Domain\Universal;

use RuntimeException;

/**
 * Raised when the vocabulary layer is asked for something a tenant has not
 * mapped.
 *
 * This exception is the whole point of the resolver. The alternative — falling
 * back to the school's tables when a mapping is missing — would make a hospital
 * tenant read the school's employee table and return its people as its own.
 * That is a cross-tenant data leak, and it would present as a puzzling bug rather than as
 * a security incident, which is exactly what makes it dangerous. Throwing is the
 * safe failure: loud, immediate, and impossible to mistake for data.
 */
final class UnsupportedEntityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $tenantId,
        public readonly string $entity,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function forEntity(string $tenantId, string $entity, array $known = []): self
    {
        $suffix = $known === []
            ? ' No entities are mapped for this tenant.'
            : ' Mapped entities: '.implode(', ', $known).'.';

        return new self(
            "No active entity mapping for '{$entity}' on tenant '{$tenantId}'.".$suffix
            .' Add rows to hpbrain_entity_mappings; the resolver will not fall back to another tenant\'s tables.',
            $tenantId,
            $entity,
        );
    }

    public static function forField(string $tenantId, string $entity, string $field, array $known = []): self
    {
        $suffix = $known === []
            ? ' No fields are mapped for this entity.'
            : ' Mapped fields: '.implode(', ', $known).'.';

        return new self(
            "Entity '{$entity}' on tenant '{$tenantId}' has no mapping for universal field '{$field}'.".$suffix
            .' Check has() before field() where the field is genuinely optional.',
            $tenantId,
            $entity,
            $field,
        );
    }

    /**
     * Two source tables claim the same universal entity. The resolver cannot
     * choose between them, and guessing would bind every later query to an
     * arbitrary winner.
     */
    public static function ambiguous(string $tenantId, string $entity, array $tables): self
    {
        sort($tables);

        return new self(
            "Entity '{$entity}' on tenant '{$tenantId}' is mapped to more than one source table: "
            .implode(', ', $tables).'. Exactly one source table per universal entity is required.',
            $tenantId,
            $entity,
        );
    }

    /**
     * A mapping exists but omits a binding the resolver cannot work without —
     * the primary key or the tenant-scoping column.
     */
    public static function incomplete(string $tenantId, string $entity, string $missing): self
    {
        return new self(
            "Entity '{$entity}' on tenant '{$tenantId}' is missing the required binding '{$missing}'. "
            ."Every mapped entity needs a row for universal_field 'id' and one for 'tenantKey'; "
            ."without the latter the resolver cannot scope reads to the tenant.",
            $tenantId,
            $entity,
            $missing,
        );
    }
}
