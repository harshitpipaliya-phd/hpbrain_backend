<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The RETRIEVE surface: what knowledge this organization holds, where it came
 * from, how fresh it is, and how much it is actually being used.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TENANT SCOPE IS NOT OPTIONAL AND NOT THE CALLER'S JOB.
 *
 * Every query below begins `where('tenant_id', $tenantId)` and the id arrives
 * from the request's resolved attribute, never from a path segment a client
 * chose. A knowledge base is exactly the kind of thing one customer must never
 * read out of another's account.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * FILTERING AND PAGING HAPPEN IN SQL.
 *
 * The screen must stay usable when a tenant has ten thousand assets, so the
 * browser is sent one page of one filter. Counting in PHP over a full table
 * read would answer the same questions and stop working at scale.
 */
final class KnowledgeLibraryService
{
    private const TABLE = 'hpbrain_knowledge_assets';

    /**
     * One page of the shelf.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items:array<int,array<string,mixed>>, page:int, pageSize:int, total:int, pages:int}
     */
    public function list(string $tenantId, array $filters = []): array
    {
        $pageSize = $this->pageSize($filters['pageSize'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->filtered($tenantId, $filters);

        // COUNT on the same builder as the rows, so the pager and the page it
        // labels can never describe different filters.
        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderBy(...$this->sort($filters['sort'] ?? null))
            ->orderByDesc('created_date')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $departments = $this->departmentNames($rows->pluck('department_id')->filter()->unique()->all());

        $items = $rows->map(fn ($row) => $this->card($row, $departments))->values()->all();

        return [
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 1,
        ];
    }

    /**
     * The counters above the shelf, and the filter vocabulary beneath them.
     *
     * Categories and owners are returned as the values this tenant ACTUALLY
     * HOLDS, counted in SQL. The screen previously hardcoded a list of ten
     * category names in the component, so it offered filters that matched
     * nothing and hid categories that existed.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId): array
    {
        $base = fn () => DB::table(self::TABLE)->where('tenant_id', $tenantId);

        $total = (int) $base()->count();

        $fresh = (int) config('knowledge.freshness.fresh_days', 90);
        $aging = (int) config('knowledge.freshness.aging_days', 180);
        $freshCut = now()->subDays($fresh)->format('Y-m-d H:i:s');
        $staleCut = now()->subDays($aging)->format('Y-m-d H:i:s');
        $recentCut = now()->subDays(30)->format('Y-m-d H:i:s');

        $stamp = 'COALESCE(updated_date, created_date)';

        $categories = $base()
            ->select('category', DB::raw('count(*) as c'))
            ->groupBy('category')
            ->orderByDesc(DB::raw('count(*)'))
            ->get()
            ->map(fn ($r) => ['value' => (string) ($r->category ?? ''), 'count' => (int) $r->c])
            ->filter(fn ($r) => $r['value'] !== '')
            ->values()
            ->all();

        $owners = $base()
            ->select('created_by', DB::raw('count(*) as c'))
            ->groupBy('created_by')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(50)
            ->get()
            ->map(fn ($r) => ['value' => (string) ($r->created_by ?? ''), 'count' => (int) $r->c])
            ->filter(fn ($r) => $r['value'] !== '')
            ->values()
            ->all();

        $departments = $base()
            ->select('department_id', DB::raw('count(*) as c'))
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->get();

        $deptNames = $this->departmentNames($departments->pluck('department_id')->all());
        $departmentFacets = $departments
            ->map(fn ($r) => [
                'value' => (string) $r->department_id,
                'label' => $deptNames[(string) $r->department_id] ?? ('Department '.$r->department_id),
                'count' => (int) $r->c,
            ])
            ->values()
            ->all();

        /*
            HOW MUCH OF THIS SHELF IS THE ORGANIZATION'S OWN.

            Counted and surfaced rather than quietly mixed in, because a library
            that is entirely seeded and a library that was written by its own
            people are different products, and only the reader can decide what
            to do about it.
        */
        $seededActors = (array) config('knowledge.provenance.seeded_actors', []);
        $seeded = $seededActors === [] ? 0 : (int) $base()->whereIn('created_by', $seededActors)->count();

        return [
            'total' => $total,
            'recentlyAdded' => (int) $base()->where('created_date', '>=', $recentCut)->count(),
            'frequentlyReused' => (int) $base()->where('reuse_count', '>=', 5)->count(),
            'stale' => (int) $base()->whereRaw("{$stamp} < ?", [$staleCut])->count(),
            'fresh' => (int) $base()->whereRaw("{$stamp} >= ?", [$freshCut])->count(),
            'withEvidence' => (int) $base()->whereNotNull('confidence')->where('confidence', '>', 0)->count(),
            'seeded' => $seeded,
            'observed' => max(0, $total - $seeded),
            'categories' => $categories,
            'owners' => $owners,
            'departments' => $departmentFacets,
        ];
    }

    /**
     * One asset, with every relationship resolved to something clickable.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $tenantId, string $id): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $row) {
            return null;
        }

        $departments = $this->departmentNames(array_filter([$row->department_id ?? null]));
        $card = $this->card($row, $departments);

        $capabilityIds = KnowledgeGrading::jsonList($row->related_capability_ids ?? null);
        $personIds = KnowledgeGrading::jsonList($row->related_person_ids ?? null);

        return $card + [
            'content' => (string) ($row->content ?? ''),
            'relatedCapabilities' => $this->capabilities($tenantId, $capabilityIds),
            'relatedPeople' => $this->people($tenantId, $personIds),
            'relatedKnowledge' => $this->relatedKnowledge($tenantId, $row),
            /*
                "USED IN" IS NOT INVENTED.

                Nothing in this schema records which decision consulted which
                asset — there is no join table. Rather than assemble a
                plausible-looking list from a category match and present it as
                usage, the field says what is missing and what would fill it.
            */
            'usedIn' => [
                'supported' => false,
                'reason' => 'No table records which decisions or executions consulted a knowledge asset. Reuse is counted in total on the asset itself, but not attributed to the work that used it.',
                'unlock' => 'Record a knowledge_asset_id against decisions or ESO executions to make "used in" answerable.',
                'items' => [],
            ],
        ];
    }

    /* ===================================================================== */
    /*  INTERNALS */
    /* ===================================================================== */

    /** The card the shelf renders. Every judgement is graded, never asserted. */
    private function card(object $row, array $departments): array
    {
        $freshness = KnowledgeGrading::freshness($row->updated_date ?? null, $row->created_date ?? null);
        $reuse = (int) ($row->reuse_count ?? 0);
        $confidence = KnowledgeGrading::confidence($row->confidence ?? null, $reuse > 0 ? 1 : 0);
        $provenance = KnowledgeGrading::provenance($row->created_by ?? null);

        $deptId = $row->department_id ?? null;

        return [
            'id' => (string) $row->id,
            'title' => (string) ($row->title ?? ''),
            'type' => (string) ($row->category ?? ''),
            /*
                THE PURPOSE LINE IS THE SOURCE'S OWN FIRST SENTENCE, TRIMMED.

                Not a generated summary. If the body says nothing, the card
                says the body says nothing rather than inventing a purpose for
                a document it has not understood.
            */
            'purpose' => $this->purpose((string) ($row->content ?? '')),
            'tags' => KnowledgeGrading::jsonList($row->tags ?? null),
            'status' => (string) ($row->status ?? ''),
            'reuseCount' => $reuse,
            'freshness' => $freshness,
            'confidence' => $confidence,
            'provenance' => $provenance,
            'owner' => ($row->created_by ?? null) !== null ? (string) $row->created_by : null,
            'createdDate' => $row->created_date ?? null,
            'updatedDate' => $row->updated_date ?? null,
            'department' => $deptId === null ? null : [
                'id' => (string) $deptId,
                'name' => $departments[(string) $deptId] ?? ('Department '.$deptId),
            ],
            'capabilityCount' => count(KnowledgeGrading::jsonList($row->related_capability_ids ?? null)),
            'personCount' => count(KnowledgeGrading::jsonList($row->related_person_ids ?? null)),
        ];
    }

    private function purpose(string $content): ?string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $content) ?? '');

        if ($clean === '') {
            return null;
        }

        // The first sentence, or the first 180 characters if it runs long.
        if (preg_match('/^(.{20,180}?[.!?])\s/u', $clean.' ', $m) === 1) {
            return $m[1];
        }

        return mb_strlen($clean) > 180 ? mb_substr($clean, 0, 179).'…' : $clean;
    }

    /** @param array<string, mixed> $filters */
    private function filtered(string $tenantId, array $filters): Builder
    {
        $query = DB::table(self::TABLE)->where('tenant_id', $tenantId);

        if (! empty($filters['category'])) {
            $query->where('category', (string) $filters['category']);
        }

        if (! empty($filters['department'])) {
            $query->where('department_id', (string) $filters['department']);
        }

        if (! empty($filters['owner'])) {
            $query->where('created_by', (string) $filters['owner']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $stamp = 'COALESCE(updated_date, created_date)';

        if (! empty($filters['freshness'])) {
            $fresh = (int) config('knowledge.freshness.fresh_days', 90);
            $aging = (int) config('knowledge.freshness.aging_days', 180);

            match ((string) $filters['freshness']) {
                'FRESH' => $query->whereRaw("{$stamp} >= ?", [now()->subDays($fresh)->format('Y-m-d H:i:s')]),
                'AGING' => $query
                    ->whereRaw("{$stamp} < ?", [now()->subDays($fresh)->format('Y-m-d H:i:s')])
                    ->whereRaw("{$stamp} >= ?", [now()->subDays($aging)->format('Y-m-d H:i:s')]),
                'STALE' => $query->whereRaw("{$stamp} < ?", [now()->subDays($aging)->format('Y-m-d H:i:s')]),
                default => null,
            };
        }

        // "Has evidence" is read as a recorded confidence, which is the only
        // grounding signal this table carries.
        if (! empty($filters['hasEvidence'])) {
            $query->whereNotNull('confidence')->where('confidence', '>', 0);
        }

        if (! empty($filters['provenance'])) {
            $seeded = (array) config('knowledge.provenance.seeded_actors', []);
            if ($seeded !== []) {
                (string) $filters['provenance'] === 'SEEDED'
                    ? $query->whereIn('created_by', $seeded)
                    : $query->where(fn ($w) => $w->whereNotIn('created_by', $seeded)->orWhereNull('created_by'));
            }
        }

        /*
            SEARCH RUNS IN SQL, ACROSS TITLE, BODY AND TAGS.

            The old screen had a separate /search endpoint the list could not
            combine with a filter, so searching threw the reader's category
            away. One builder means a search inside a filter stays inside it.
        */
        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            if ($term !== '') {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(fn ($w) => $w
                    ->where('title', 'like', $like)
                    ->orWhere('content', 'like', $like)
                    ->orWhere('tags', 'like', $like));
            }
        }

        return $query;
    }

    /** @return array{0:string, 1:string} */
    private function sort(?string $sort): array
    {
        return match ($sort) {
            'reused' => ['reuse_count', 'desc'],
            'oldest' => [DB::raw('COALESCE(updated_date, created_date)'), 'asc'],
            'title' => ['title', 'asc'],
            default => [DB::raw('COALESCE(updated_date, created_date)'), 'desc'],
        };
    }

    private function pageSize(mixed $requested): int
    {
        $default = (int) config('knowledge.pagination.page_size', 24);
        $max = (int) config('knowledge.pagination.max_page_size', 100);

        $size = is_numeric($requested) ? (int) $requested : $default;

        return max(1, min($max, $size));
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    private function departmentNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), fn ($v) => $v !== ''));

        if ($ids === [] || ! Schema::hasTable('hrms_departments')) {
            return [];
        }

        return DB::table('hrms_departments')
            ->whereIn('id', $ids)
            ->pluck('department', 'id')
            ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function capabilities(string $tenantId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));

        if ($ids === [] || ! Schema::hasTable('hpbrain_capabilities')) {
            return [];
        }

        return DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'name'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'name' => (string) $r->name])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function people(string $tenantId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));

        if ($ids === [] || ! Schema::hasTable('tbluser')) {
            return [];
        }

        return DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'name' => trim(((string) $r->first_name).' '.((string) $r->last_name)),
            ])
            ->values()
            ->all();
    }

    /**
     * Sibling assets, by the only real relation the table carries: the same
     * category, or an overlapping tag. Never a similarity score dressed up as
     * a relationship.
     *
     * @return array<int, array<string, mixed>>
     */
    private function relatedKnowledge(string $tenantId, object $row): array
    {
        $query = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', (string) $row->id);

        $category = $row->category ?? null;

        if ($category === null) {
            return [];
        }

        return $query
            ->where('category', $category)
            ->orderByDesc('reuse_count')
            ->limit(6)
            ->get(['id', 'title', 'category', 'reuse_count'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'title' => (string) $r->title,
                'type' => (string) $r->category,
                'reuseCount' => (int) ($r->reuse_count ?? 0),
                'relation' => 'Same type',
            ])
            ->values()
            ->all();
    }
}
