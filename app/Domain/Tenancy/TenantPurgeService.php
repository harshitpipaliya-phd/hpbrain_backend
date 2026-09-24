<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Permanent, irreversible deletion of one tenant and everything it owns.
 *
 * THIS IS NOT THE ARCHIVE, AND IT DELIBERATELY DOES NOT REUSE IT.
 * OrganizationController::archive() sets institute_detail.deleted_at on a single
 * row and is correct for what it is — an organization-record archive. It was
 * never a tenant offboarding, which is why an archived organization kept 12,592
 * Brain rows, 6,613 ERP rows, 39 active entity mappings, 2 live refresh tokens
 * and three usable logins. Archive stays exactly as it is and remains reachable
 * at its own endpoint; this class is a separate operation with a separate route,
 * a separate permission and a separate confirmation.
 *
 * THREE THINGS KEEP A DELETED ORGANIZATION ABLE TO SIGN IN, and all three are
 * closed here rather than by touching the authentication code:
 *
 *   1. tbluser rows — AuthController::findPersonByEmail matches on
 *      (email, status = 1, deleted_at IS NULL) and consults NOTHING about the
 *      organization. Deleting the rows is what denies the login.
 *   2. hpbrain_entity_mappings rows — EntityResolver::everyTenantWith('Person')
 *      enumerates login-eligible tenants from this table. With no rows the
 *      tenant is not searched at all.
 *   3. hpbrain_refresh_tokens rows — an unrevoked refresh token mints new access
 *      tokens without ever re-reading the user.
 *
 * All three fall out of the ordinary tenant-scoped sweep. No middleware is
 * weakened and no authentication check is relaxed to achieve it.
 *
 * ONE TRANSACTION, AND ONLY DML INSIDE IT. Every statement below is a DELETE or
 * an UPDATE; there is no schema change anywhere, so there is no implicit commit
 * to defeat the rollback. A failure at any point leaves the organization, its
 * people, its logins and its data exactly as they were.
 *
 * EVERY STATEMENT IS TENANT-SCOPED. There is no code path here that emits a
 * DELETE without a WHERE on the tenant column, no TRUNCATE, no DROP, and no
 * `SET FOREIGN_KEY_CHECKS = 0`. Foreign keys stay on for the whole operation,
 * which is the point: 41 of the 42 constraints on hpbrain_ tables are
 * ON DELETE RESTRICT, so the database itself refuses any delete that would
 * orphan a row the plan got wrong, and the transaction unwinds.
 */
final class TenantPurgeService
{
    public function __construct(
        private readonly TenantOwnedTables $tables,
        private readonly EntityResolver $resolver,
    ) {
    }

    /**
     * What deleting this tenant would destroy. Reads only.
     *
     * Throws notFound rather than returning a plan with an empty name. The
     * plan's organizationName is the string the confirmation is checked
     * against, so a blank one would be a plan nobody could ever confirm — and
     * it would render in the dialog as "permanently delete ''". A tenant with
     * no organization is a 404, and saying so here means the preview endpoint
     * and the delete endpoint answer identically for the same tenant.
     */
    public function plan(string $tenantId): TenantDeletionPlan
    {
        $this->assertDeletable($tenantId);

        $name = $this->organizationName($tenantId);

        if ($name === null) {
            throw TenantDeletionException::notFound($tenantId);
        }

        $classified = $this->tables->classify($tenantId);
        $counted    = $this->tables->withCounts($tenantId, $classified);
        $ordered    = $this->tables->inDeletionOrder($counted);

        // Rows in tables the sweep above does NOT delete, which its deletes
        // would nevertheless trip over: junction rows with no tenant column of
        // their own, owned transitively through a foreign key. Discovered from
        // the counted set, so only parents that actually have rows are walked.
        $dependents = $this->tables->dependentRowsWithCounts(
            $tenantId,
            $this->tables->dependentRows($counted),
        );

        return new TenantDeletionPlan(
            tenantId: $tenantId,
            organizationName: $name,
            tables: $ordered,
            // Cross-checked against the CLASSIFIED set, not the counted one.
            // withCounts() drops tables this tenant holds no rows in, so
            // comparing against it reported every empty table as missing — on
            // the live database that was 14 false alarms including
            // hpbrain_mental_models and hrms_job_titles, which exist and are
            // simply unused by that tenant. "The migration has not run" and
            // "this organization has none of these" are opposite findings, and
            // this list is only worth having if it means the first one.
            missingReferences: $this->tables->missingReferences($tenantId, $classified),
            dependents: $dependents,
        );
    }

    /**
     * Destroy the tenant.
     *
     * @param  string  $confirmName  must equal the organization's name exactly
     * @param  bool  $acknowledgeSourceSystemData  required when the tenant holds
     *         rows in tables belonging to other applications sharing this
     *         database. Without it the operation refuses rather than guessing —
     *         those rows are tenant-scoped but not Brain-owned, and the Brain
     *         cannot know what the LMS, CRM or talent suite do when their rows
     *         disappear.
     * @return array<string, mixed> what was actually deleted
     *
     * @throws TenantDeletionException
     */
    public function purge(
        string $tenantId,
        string $confirmName,
        bool $acknowledgeSourceSystemData = false,
        ?string $actorId = null,
    ): array {
        $this->assertDeletable($tenantId);

        $name = $this->organizationName($tenantId);

        if ($name === null) {
            throw TenantDeletionException::notFound($tenantId);
        }

        // Compared before the transaction opens, byte for byte, and NOT
        // case-folded: 'sunrise international school' is refused. The whole
        // purpose of a type-the-name confirmation is that it is hard to do by
        // accident, and case-insensitive matching would halve the work.
        //
        // Leading and trailing whitespace is the one difference that survives,
        // because Laravel's global TrimStrings middleware has already stripped
        // it from the request before this method is reached. That is worth
        // stating rather than pretending otherwise: a stray space picked up from
        // a copy-paste is a typo, not a second organization, and no name in the
        // register differs from another only by its outer whitespace.
        if (! hash_equals($name, $confirmName)) {
            throw TenantDeletionException::nameMismatch();
        }

        $plan = $this->plan($tenantId);

        $sourceSystem = $plan->sourceSystemTables();

        if ($sourceSystem !== [] && ! $acknowledgeSourceSystemData) {
            throw TenantDeletionException::sourceSystemData(
                $tenantId,
                array_map(static fn (TenantTable $t) => $t->toArray(), $sourceSystem),
                $plan->rowsInTier(TenantTable::TIER_SOURCE_SYSTEM),
            );
        }

        $effective = $acknowledgeSourceSystemData ? $plan : $plan->withoutSourceSystem();

        // CHECKED BEFORE THE TRANSACTION OPENS, and it is the check that keeps
        // the safety guarantee honest while the deletion actually works.
        //
        // The dependent sweep below removes junction rows that have no owner of
        // their own. This finds the opposite case: a row that DOES name an
        // owner, names a different organization, and points at a row this
        // tenant owns. Deleting it would destroy another organization's data
        // purely to let this deletion through; leaving it makes the foreign key
        // refuse and the transaction unwind. Neither is acceptable silently, so
        // the caller is told which table and how many rows, and nothing runs.
        $conflicts = $this->tables->crossTenantConflicts($tenantId, $effective->tables);

        if ($conflicts !== []) {
            throw TenantDeletionException::crossTenantReference($tenantId, $conflicts);
        }

        return DB::transaction(function () use ($effective, $tenantId, $name, $actorId): array {
            $deleted     = [];
            $dissociated = [];

            // FIRST, and deepest-first within itself: rows owned transitively
            // through a foreign key rather than by a tenant column. Every one
            // of these is a child of a row the sweep below deletes, and 41 of
            // the 42 hpbrain_ constraints — plus content_mapping_type's, which
            // is the one that actually bit — are RESTRICT. The parent cannot go
            // until the child has.
            //
            // The query is built by TenantOwnedTables::scopedDependentQuery and
            // is tenant-scoped at its innermost level, so a junction row
            // belonging to another organization is never in the result set.
            foreach ($effective->dependents as $dependent) {
                $query = $this->tables->scopedDependentQuery($tenantId, $dependent);

                if ($dependent->dissociates()) {
                    // The row is NOT this tenant's — it only records that one of
                    // this tenant's users touched it. lms_mapping_type's 56 rows
                    // of shared LMS taxonomy carry created_by/updated_by/
                    // deleted_by into tbluser; deleting them because a Fiber
                    // Valley administrator authored them would destroy every
                    // other organization's reference data. Clearing the pointer
                    // satisfies the constraint and leaves the row where it is.
                    $n = $query->update([$dependent->column => null]);

                    if ($n > 0) {
                        $dissociated[$dependent->table.'.'.$dependent->column] = $n;
                    }

                    continue;
                }

                $n = $query->delete();

                if ($n > 0) {
                    $deleted[$dependent->table] = ($deleted[$dependent->table] ?? 0) + $n;
                }
            }

            // Read BEFORE the sweep: school_setup is deleted below, and after
            // that there is no way left to tell which client belonged to this
            // tenant. Captured as a single id so the cleanup at the end stays
            // scoped to this tenant instead of hunting orphans database-wide.
            $clientId = $this->clientIdFor($tenantId);

            foreach ($effective->tables as $table) {
                // Self-referencing RESTRICT constraints: break the parent/child
                // links inside this tenant before removing the rows, or the
                // engine can refuse mid-statement depending on the order it
                // visits rows in.
                if ($table->selfReferenceColumn !== null) {
                    DB::table($table->table)
                        ->where($table->tenantColumn, $tenantId)
                        ->whereNotNull($table->selfReferenceColumn)
                        ->update([$table->selfReferenceColumn => null]);
                }

                $n = DB::table($table->table)
                    ->where($table->tenantColumn, $tenantId)
                    ->delete();

                if ($n > 0) {
                    $deleted[$table->table] = $n;
                }
            }

            $deleted += $this->deleteOrphanedClient($tenantId, $clientId);

            // Written AFTER the deletes and inside the same transaction, so the
            // record of the deletion commits with it or not at all. The audit
            // table is tenant-scoped and was just emptied for this tenant; this
            // row is the one thing intentionally left behind, because an
            // organization vanishing with no trace of who removed it is worse
            // than a single retained row.
            $this->recordAudit($tenantId, $name, $deleted, $actorId, $dissociated);

            // The resolver caches mappings per request. They have just been
            // deleted, so anything resolving this tenant later in the same
            // request must not keep answering from a cache that predates it.
            $this->resolver->flush($tenantId);

            return [
                'tenantId'         => $tenantId,
                'organizationName' => $name,
                'tables'           => count($deleted),
                'rows'             => array_sum($deleted),
                'deleted'          => $deleted,
                // Reported separately because it is a different outcome. These
                // rows still exist; only their pointer at a deleted user was
                // cleared. Collapsing the two into one "rows" figure would
                // claim shared reference data had been destroyed when it was
                // deliberately kept.
                'dissociated'      => $dissociated,
            ];
        });
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    private function assertDeletable(string $tenantId): void
    {
        if (TenantOwnedTables::isReserved($tenantId)) {
            throw TenantDeletionException::reservedTenant($tenantId);
        }
    }

    /**
     * The organization's name, read through EntityResolver rather than by
     * naming institute_detail — a tenant on different tables must confirm
     * against its own name, not against a table it does not use.
     *
     * Deliberately does NOT filter on deleted_at. An organization that was
     * archived first is exactly the one an administrator is most likely to be
     * deleting permanently, and refusing to find it would make the archive a
     * trap: archive, then discover the real delete can no longer see it.
     */
    private function organizationName(string $tenantId): ?string
    {
        try {
            $org = $this->resolver->resolve($tenantId, 'Organization');

            $name = DB::table($org->table)
                ->where($org->tenantKey, $tenantId)
                ->value($org->field('name'));

            return $name === null ? null : (string) $name;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The ERP's billing parent, removed only when this tenant was its last
     * school.
     *
     * tblclient is genuinely shared: school_setup.client_id points at it and one
     * client can own several schools (the table even carries a
     * number_of_schools column). Deleting it unconditionally would take another
     * organization's billing parent with it. Deleting it never would leave an
     * orphan row behind on every self-service signup, since
     * OrganizationSignupService creates one client per organization.
     *
     * So: only THIS tenant's client is ever considered, and only when no other
     * school_setup row still points at it. This is the one place in this class
     * where a row is classified at runtime rather than by tier, and it is the
     * case section 8 of the specification describes as "referenced by multiple
     * tenants — PRESERVE".
     *
     * @return array<string, int>
     */
    private function deleteOrphanedClient(string $tenantId, ?int $clientId): array
    {
        if ($clientId === null || ! Schema::hasTable('tblclient')) {
            return [];
        }

        try {
            // This tenant's school_setup row is already gone. Any row still
            // pointing at this client therefore belongs to a DIFFERENT
            // organization, and the client stays.
            $stillUsed = DB::table('school_setup')->where('client_id', $clientId)->exists();

            if ($stillUsed) {
                return [];
            }

            $n = DB::table('tblclient')->where('id', $clientId)->delete();

            return $n > 0 ? ['tblclient' => $n] : [];
        } catch (Throwable $e) {
            // A constraint elsewhere still needs this client. Not fatal to the
            // deletion — the tenant is gone either way — but it must be visible
            // rather than swallowed silently.
            Log::warning('Tenant purge left tblclient in place', [
                'tenantId' => $tenantId,
                'message'  => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * This tenant's billing parent, read while school_setup still exists.
     */
    private function clientIdFor(string $tenantId): ?int
    {
        if (! Schema::hasTable('school_setup')) {
            return null;
        }

        try {
            $id = DB::table('school_setup')->where('id', $tenantId)->value('client_id');

            return $id === null ? null : (int) $id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, int>  $deleted
     * @param  array<string, int>  $dissociated
     */
    private function recordAudit(
        string $tenantId,
        string $name,
        array $deleted,
        ?string $actorId,
        array $dissociated = [],
    ): void
    {
        if (! Schema::hasTable('hpbrain_audit_logs')) {
            return;
        }

        try {
            DB::table('hpbrain_audit_logs')->insert([
                'id'          => \Ramsey\Uuid\Uuid::uuid4()->toString(),
                'tenant_id'   => $tenantId,
                'entity_type' => 'Organization',
                'entity_id'   => $tenantId,
                'action'      => 'organization.permanently_deleted',
                'actor_id'    => $actorId ?? 'system',
                'actor_name'  => $actorId ?? 'system',
                'changes'     => json_encode([
                    'organizationName' => $name,
                    'tables'           => count($deleted),
                    'rows'             => array_sum($deleted),
                    'deleted'          => $deleted,
                    'dissociated'      => $dissociated,
                ]),
                'created_at'  => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Unlike RequirePermission's audit — which must never turn a denial
            // into a 500 — this one runs INSIDE the deletion transaction and is
            // allowed to fail the operation. Destroying an organization with no
            // record that it happened is not an acceptable outcome, so if the
            // audit cannot be written the deletion does not commit.
            throw new TenantDeletionException(
                'audit_write_failed',
                'The deletion was rolled back because it could not be recorded in the audit log: '
                .$e->getMessage(),
                500,
            );
        }
    }
}
