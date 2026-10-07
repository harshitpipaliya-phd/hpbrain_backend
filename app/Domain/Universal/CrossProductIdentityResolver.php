<?php

declare(strict_types=1);

namespace App\Domain\Universal;

use Illuminate\Support\Facades\DB;

/**
 * Phase 7.4 — resolves the same real person once across K-12 (vivek_erp)
 * and G2G (hp_erp), the two schemas this process can already read: the
 * `dev_db` connection has cross-schema SELECT on both (confirmed before
 * writing this, not assumed), so no new connection, grant, or network path
 * was needed to build this.
 *
 * EMAIL IS THE ONLY VALIDATED SIGNAL. tbluser.id is NOT a shared identity
 * space between the two schemas — measured: the same id is routinely a
 * different person in a different tenant. email is what actually
 * corresponds to the same real person.
 *
 * CONFIDENCE REFLECTS CORROBORATION, NOT ONE MATCH IN ISOLATION. A tenant
 * pair with many matched emails (K-12 tenant 329 / G2G tenant 1000018: 64)
 * is a real organization using both products. A tenant pair with one or two
 * matches on a low-numbered tenant is more likely a coincidental shared
 * dev/seed email than a real cross-product identity - scored low rather
 * than claimed as certain.
 *
 * DRAFT-ONLY WRITES. Nothing this class writes is used by resolve() until a
 * human sets status='confirmed' - see the migration's own note on why a
 * wrong link is worse than no link.
 */
class CrossProductIdentityResolver
{
    private const TABLE = 'cross_product_identity_links';

    /** Tenant pairs with fewer matches than this are scored low-confidence, not high. */
    private const CORROBORATION_THRESHOLD = 5;

    /**
     * Find every K-12/G2G user pair sharing an email, score each by how
     * much its tenant pairing is corroborated by other matches, and write
     * (or refresh) a draft row for each. Confirmed/rejected rows are never
     * touched — a human's verdict survives a re-run.
     *
     * @return array{proposed:int, skipped_confirmed:int, skipped_rejected:int}
     */
    public function proposeMatches(): array
    {
        $matches = DB::select("
            SELECT v.sub_institute_id AS k12_tenant, v.id AS k12_user_id,
                   h.sub_institute_id AS g2g_tenant, h.id AS g2g_user_id,
                   v.email AS email
            FROM vivek_erp.tbluser v
            INNER JOIN hp_erp.tbluser h ON h.email = v.email
            WHERE v.email IS NOT NULL AND v.email != ''
        ");

        // Corroboration count per (k12_tenant, g2g_tenant) pair, computed
        // once over the whole batch rather than per row.
        $pairCounts = [];
        foreach ($matches as $m) {
            $key = $m->k12_tenant . ':' . $m->g2g_tenant;
            $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
        }

        $proposed = 0;
        $skippedConfirmed = 0;
        $skippedRejected = 0;

        foreach ($matches as $m) {
            $existing = DB::table(self::TABLE)
                ->where('k12_user_id', $m->k12_user_id)
                ->where('g2g_user_id', $m->g2g_user_id)
                ->first();

            if ($existing !== null && $existing->status !== 'draft') {
                $existing->status === 'confirmed' ? $skippedConfirmed++ : $skippedRejected++;
                continue;
            }

            $pairKey = $m->k12_tenant . ':' . $m->g2g_tenant;
            $corroboration = $pairCounts[$pairKey];
            $confidence = $corroboration >= self::CORROBORATION_THRESHOLD ? 0.900 : 0.300;

            DB::table(self::TABLE)->updateOrInsert(
                ['k12_user_id' => $m->k12_user_id, 'g2g_user_id' => $m->g2g_user_id],
                [
                    'k12_sub_institute_id' => $m->k12_tenant,
                    'g2g_sub_institute_id' => $m->g2g_tenant,
                    'match_email' => $m->email,
                    'match_method' => 'email',
                    'confidence' => $confidence,
                    'status' => 'draft',
                    'updated_at' => now(),
                    'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                ]
            );

            $proposed++;
        }

        return [
            'proposed' => $proposed,
            'skipped_confirmed' => $skippedConfirmed,
            'skipped_rejected' => $skippedRejected,
        ];
    }

    /**
     * The confirmed cross-product identity for one system's user, or null.
     *
     * Only 'confirmed' rows are returned — a 'draft' proposal is a
     * suggestion for a human to review, never something a caller may act on.
     *
     * @return array{k12:?array, g2g:?array}|null
     */
    public function resolve(string $fromSystem, int $subInstituteId, int $userId): ?array
    {
        $query = DB::table(self::TABLE)->where('status', 'confirmed');

        $row = match ($fromSystem) {
            'k12' => $query->where('k12_sub_institute_id', $subInstituteId)->where('k12_user_id', $userId)->first(),
            'g2g' => $query->where('g2g_sub_institute_id', $subInstituteId)->where('g2g_user_id', $userId)->first(),
            default => null,
        };

        if ($row === null) {
            return null;
        }

        return [
            'k12' => $row->k12_user_id !== null
                ? ['sub_institute_id' => (int) $row->k12_sub_institute_id, 'user_id' => (int) $row->k12_user_id]
                : null,
            'g2g' => $row->g2g_user_id !== null
                ? ['sub_institute_id' => (int) $row->g2g_sub_institute_id, 'user_id' => (int) $row->g2g_user_id]
                : null,
        ];
    }

    /** Pending human review, highest confidence first. */
    public function pendingReview(int $limit = 100): array
    {
        return DB::table(self::TABLE)
            ->where('status', 'draft')
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** A human's verdict on one proposal. Never overwrites an existing verdict silently. */
    public function confirm(int $linkId, int $confirmedBy): bool
    {
        return (bool) DB::table(self::TABLE)
            ->where('id', $linkId)
            ->where('status', 'draft')
            ->update(['status' => 'confirmed', 'confirmed_by' => $confirmedBy, 'confirmed_at' => now()]);
    }

    public function reject(int $linkId, int $confirmedBy): bool
    {
        return (bool) DB::table(self::TABLE)
            ->where('id', $linkId)
            ->where('status', 'draft')
            ->update(['status' => 'rejected', 'confirmed_by' => $confirmedBy, 'confirmed_at' => now()]);
    }
}
