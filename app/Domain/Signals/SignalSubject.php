<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Who a signal is about — derived from the rows that fired the rule, never guessed.
 *
 * WHY THIS EXISTS. `who_is_affected` is one of the seven UODM questions
 * SufficiencyCheck requires, and ExplainVerb answers it from three signal
 * columns in order: related_entity_id, then department_id, then org_id. Neither
 * RuleEvaluator nor OperationalSignalWriter has ever written any of the three,
 * so every rule-fired signal in the database answers null — and EXPLAIN
 * correctly refuses to frame it. The gap was never in the verb; it was that
 * nothing populated the columns the verb reads.
 *
 * THE HARD RULE: A SINGLE SUBJECT IS CLAIMED ONLY WHEN THERE IS ONE.
 *
 * "50 departments have no manager" is not a fact about department 1. Writing
 * sampleIds[0] into related_entity_id would make EXPLAIN answer
 * "who_is_affected: OrganizationUnit:1" — a specific, checkable, and false
 * claim, and precisely the fabrication this build refuses elsewhere. So:
 *
 *   - exactly one affected row  → related_entity_type/_id name that row. There
 *     is one subject and we know which it is.
 *   - more than one, or none    → related_entity_* stay null. The affected party
 *     of an org-scoped aggregate is the ORGANIZATION, and org_id says so.
 *
 * org_id is not a consolation prize. The rule ran scoped to one tenant's
 * organization and counted rows inside it, so "this organization has 50
 * unmanaged departments" is exactly what was observed — a narrower claim would
 * be a stronger one than the evidence supports.
 *
 * EVERY VALUE COMES THROUGH EntityResolver, the same tenant-safe mapping the
 * ingestion sources use. Nothing here knows a table name: a tenant whose people
 * live somewhere else resolves somewhere else, and a tenant with no
 * Organization mapping gets null rather than a borrowed id.
 */
final class SignalSubject
{
    /**
     * hpbrain_signals.related_entity_id is varchar(36). A source key longer than
     * that would be silently truncated into a DIFFERENT, possibly existing id,
     * so it is dropped instead — a null subject is honest, a truncated one points
     * at the wrong row.
     */
    private const ID_LIMIT = 36;

    /** @var array<string, string|null> tenantId => organization id, false-y cached */
    private array $organizations = [];

    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    /**
     * The subject columns for a signal about $affectedIds.
     *
     * @param  string|null  $universalEntity  the entity the rule counted rows of
     * @param  array<int, mixed>  $affectedIds  every affected primary key, not a sample
     * @return array{org_id: string|null, related_entity_type: string|null, related_entity_id: string|null}
     */
    public function columnsFor(string $tenantId, ?string $universalEntity = null, array $affectedIds = []): array
    {
        $single = $this->singleSubject($tenantId, $universalEntity, $affectedIds);

        return [
            'org_id'              => $this->organizationId($tenantId),
            'related_entity_type' => $single['type'],
            'related_entity_id'   => $single['id'],
        ];
    }

    /**
     * The tenant's organization as the ERP itself identifies it.
     *
     * Read from the mapped source row rather than assumed to equal the tenant
     * id: they coincide for the tenants onboarded so far because Organization
     * maps tenantKey and id to the same column, but that is a property of those
     * mappings, not a rule, and the next tenant may separate them.
     */
    private function organizationId(string $tenantId): ?string
    {
        if (array_key_exists($tenantId, $this->organizations)) {
            return $this->organizations[$tenantId];
        }

        $this->organizations[$tenantId] = null;

        try {
            $org = $this->resolver->resolve($tenantId, 'Organization');

            $query = DB::table($org->table)->where($org->tenantKey, $tenantId);

            if ($org->has('deletedAt')) {
                $query->whereNull($org->field('deletedAt'));
            }

            $value = $query->value($org->primaryKey);

            $this->organizations[$tenantId] = $this->usable($value);
        } catch (Throwable) {
            // An unmapped or unreadable Organization means we do not know which
            // organization this is about. Detection must still raise the signal;
            // EXPLAIN will then say who_is_affected is unanswered, which is true.
        }

        return $this->organizations[$tenantId];
    }

    /**
     * @param  array<int, mixed>  $affectedIds
     * @return array{type: string|null, id: string|null}
     */
    private function singleSubject(string $tenantId, ?string $universalEntity, array $affectedIds): array
    {
        $none = ['type' => null, 'id' => null];

        if ($universalEntity === null || $universalEntity === '' || count($affectedIds) !== 1) {
            return $none;
        }

        // The entity name is written into the signal, so it must be one this
        // tenant actually maps — otherwise related_entity_type would name a
        // vocabulary term nothing can resolve back to a row.
        if (! $this->resolver->has($tenantId, $universalEntity)) {
            return $none;
        }

        $id = $this->usable(reset($affectedIds));

        return $id === null ? $none : ['type' => $universalEntity, 'id' => $id];
    }

    private function usable(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $id = trim((string) $value);

        return ($id === '' || mb_strlen($id) > self::ID_LIMIT) ? null : $id;
    }
}
