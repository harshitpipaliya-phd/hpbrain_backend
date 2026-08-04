<?php

declare(strict_types=1);

namespace App\Domain\Universal;

/**
 * Where one universal entity actually lives for one tenant.
 *
 * A resolved binding of a universal entity name ('Person') to the physical
 * table behind it ('tbluser'), the column that scopes rows to a tenant
 * ('sub_institute_id'), its primary key, and the source column for each
 * universal field the tenant has mapped.
 *
 * Immutable and query-free by design: it describes a source, it does not read
 * from one. Callers build their own Query Builder statements from it, which
 * keeps data access in the repositories where the project's rules put it.
 *
 * TWO RESERVED UNIVERSAL FIELDS. 'id' and 'tenantKey' are bindings rather than
 * data: every entity must map both, because without them the resolver cannot
 * identify a row or confine a read to one tenant. They are exposed as the
 * ->primaryKey and ->tenantKey properties and are also reachable through
 * field(), since 'id' is a legitimate universal field in its own right.
 *
 * AN ABSENT FIELD IS NOT AN EMPTY ONE. has() returning false means the source
 * system has no column for that concept — hrms_job_titles has no vacancy flag,
 * so Position.isVacant is unmapped for the school tenant. That is a fact about
 * the ERP, and the honest rendering of it is "never measured", not false and
 * not zero. field() throws rather than returning null so the distinction cannot
 * be lost by accident downstream.
 */
final class ResolvedSource
{
    /**
     * @param  array<string, array{column: string, type: string, expression: ?string, lookupTable: ?string}>  $fields
     *         keyed by universal field name
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $entity,
        public readonly string $table,
        public readonly string $sourceSystem,
        public readonly string $tenantKey,
        public readonly string $primaryKey,
        private readonly array $fields,
    ) {
    }

    /** The source column backing a universal field. */
    public function field(string $universalField): string
    {
        if (! isset($this->fields[$universalField])) {
            throw UnsupportedEntityException::forField(
                $this->tenantId,
                $this->entity,
                $universalField,
                $this->universalFields(),
            );
        }

        return $this->fields[$universalField]['column'];
    }

    public function has(string $universalField): bool
    {
        return isset($this->fields[$universalField]);
    }

    /** `tbluser.first_name` — for joins and selects that need disambiguation. */
    public function qualified(string $universalField): string
    {
        return $this->table.'.'.$this->field($universalField);
    }

    /**
     * Full mapping detail, for the callers that must honour mapping_type rather
     * than just read a column.
     *
     * @return array{column: string, type: string, expression: ?string, lookupTable: ?string}
     */
    public function mapping(string $universalField): array
    {
        // Routed through field() so the unmapped case raises the same named
        // exception here as everywhere else.
        $this->field($universalField);

        return $this->fields[$universalField];
    }

    public function isDirect(string $universalField): bool
    {
        return $this->mapping($universalField)['type'] === 'direct';
    }

    /** @return array<int, string> sorted, so error messages are stable */
    public function universalFields(): array
    {
        $names = array_keys($this->fields);
        sort($names);

        return $names;
    }

    /**
     * Source columns for a set of universal fields, skipping the unmapped ones.
     *
     * The common shape at a call site is "select whichever of these the tenant
     * actually has". Doing it here keeps has()/field() pairs out of every
     * controller.
     *
     * @param  array<int, string>  $universalFields
     * @return array<string, string> universal field => source column
     */
    public function columns(array $universalFields): array
    {
        $out = [];

        foreach ($universalFields as $name) {
            if ($this->has($name)) {
                $out[$name] = $this->fields[$name]['column'];
            }
        }

        return $out;
    }
}
