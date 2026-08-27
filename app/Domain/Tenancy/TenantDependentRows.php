<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Rows in a table the deletion plan does NOT delete, whose ownership by this
 * tenant is proven by a foreign key into a row the plan DOES delete.
 *
 * WHY THIS EXISTS. TenantOwnedTables discovers tables by looking for a tenant
 * column, which is the right way to find everything a tenant owns DIRECTLY. It
 * is blind to a table that owns nothing itself and exists only to join two rows
 * together — hp_erp's content_mapping_type is exactly that: id, content_id,
 * mapping_type_id, mapping_value_id, and no tenant column anywhere.
 *
 * Its rows are still somebody's. content_mapping_type.content_id is a foreign
 * key to content_master.id, content_master IS tenant-scoped, and the constraint
 * is ON DELETE NO ACTION. So the sweep deleted content_master's rows, MySQL
 * refused because 7 junction rows still pointed at them, and the whole
 * transaction unwound — the organization survived its own deletion.
 *
 * OWNERSHIP HERE IS DERIVED, NOT ASSUMED, and that is the safety argument.
 * A row qualifies only when its foreign key points at a row this tenant owns.
 * content_mapping_type holds 56 rows; 7 point at Fiber Valley's content and 49
 * point at other organizations'. Those 49 are never in scope, because the
 * subquery that selects them is itself tenant-scoped. The junction table is not
 * emptied — it is filtered.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not apply to a dependent table
 * that has a tenant column of its own. Such a table is either already in the
 * plan (and swept by its own tenant scope) or holds no rows for this tenant at
 * all. If one of ITS rows — belonging to another tenant — points at a row this
 * tenant owns, that is a cross-tenant reference, and deleting it would destroy
 * another organization's data to make this deletion succeed. TenantPurgeService
 * refuses instead. See TenantDeletionException::crossTenantReference().
 */
final class TenantDependentRows
{
    /**
     * The row belongs to this tenant through the foreign key. Delete it.
     *
     * content_mapping_type.content_id is this: the junction row exists only to
     * describe the content row it points at, and means nothing once that row is
     * gone.
     */
    public const MODE_DELETE = 'delete';

    /**
     * The row does NOT belong to this tenant. It merely RECORDS that one of
     * this tenant's users touched it. Null the reference and leave the row.
     *
     * THIS DISTINCTION IS NOT COSMETIC — it is the difference between deleting
     * a junction row and deleting shared reference data. hp_erp's
     * lms_mapping_type holds 56 rows of LMS taxonomy shared by every
     * organization, and it foreign-keys to tbluser three times: created_by,
     * updated_by, deleted_by. A rule that says "this row points at a row the
     * tenant owns, therefore the tenant owns it" would destroy all 56 because
     * an administrator of one school happened to author them. Same for
     * document_type, and same for s_skill_matrix's authorship columns.
     *
     * A column named *_by records WHO ACTED. It never records WHAT OWNS. So an
     * edge through one is dissociated, never followed: the row survives, the
     * dangling pointer to a deleted user does not, and the foreign key is
     * satisfied either way.
     */
    public const MODE_DISSOCIATE = 'dissociate';

    /**
     * Columns that name an actor rather than an owner.
     *
     * Matched by suffix because the schema is consistent about it — created_by,
     * updated_by, deleted_by, reviewed_by, approved_by — and a suffix rule does
     * not go stale the way an enumerated list would when the next table adds
     * verified_by. assigned_to is listed explicitly: it has the same meaning and
     * not the same shape.
     */
    private const ACTOR_SUFFIXES = ['_by'];

    private const ACTOR_COLUMNS = ['assigned_to', 'owner_user_id', 'actor_id'];

    /**
     * Whether an edge through this column conveys ownership or only authorship.
     */
    public static function modeFor(string $column): string
    {
        $lower = strtolower($column);

        if (in_array($lower, self::ACTOR_COLUMNS, true)) {
            return self::MODE_DISSOCIATE;
        }

        foreach (self::ACTOR_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return self::MODE_DISSOCIATE;
            }
        }

        return self::MODE_DELETE;
    }

    /**
     * @param  string  $table  the dependent table rows are deleted from
     * @param  string  $column  its foreign-key column
     * @param  array<int, array{table: string, column: string, parentColumn: string}>  $path
     *         the chain from $table back to a plan table. The last entry names
     *         the plan table itself; entries before it are intermediate
     *         dependent tables, for a junction hanging off a junction.
     * @param  string  $rootTable  the plan table at the end of the chain
     * @param  string  $rootTenantColumn  that table's tenant column
     * @param  string  $tier  inherited from the plan table it hangs off, so a
     *         junction under an LMS table is acknowledged as source-system data
     *         rather than quietly deleted as though it were Brain-owned.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly array $path,
        public readonly string $rootTable,
        public readonly string $rootTenantColumn,
        public readonly string $tier,
        public readonly int $rows = 0,
        public readonly string $mode = self::MODE_DELETE,
    ) {
    }

    public function withRows(int $rows): self
    {
        return new self(
            $this->table,
            $this->column,
            $this->path,
            $this->rootTable,
            $this->rootTenantColumn,
            $this->tier,
            $rows,
            $this->mode,
        );
    }

    public function dissociates(): bool
    {
        return $this->mode === self::MODE_DISSOCIATE;
    }

    /**
     * How deep this table sits below the plan table. Used only for ordering:
     * the deepest dependent must be deleted first, or it becomes the next thing
     * blocking its own parent.
     */
    public function depth(): int
    {
        return count($this->path);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'table'  => $this->table,
            'column' => $this->column,
            'via'    => $this->rootTable,
            'tier'   => $this->tier,
            'rows'   => $this->rows,
            // Reported so the preview can say "7 rows deleted" and "3 references
            // cleared" rather than presenting them as the same thing.
            'mode'   => $this->mode,
        ];
    }
}
