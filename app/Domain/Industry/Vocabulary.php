<?php

declare(strict_types=1);

namespace App\Domain\Industry;

use Illuminate\Support\Facades\DB;

/**
 * The words a tenant uses, for text the API composes.
 *
 * The UI already reads terminology through useConfig(), but plenty of prose is
 * assembled server-side — attention titles, signal descriptions, empty-state
 * copy — and all of it said "employee" and "department" regardless of who was
 * reading. A hospital was told "18 department(s) without a manager" when it
 * calls them wards, and a bank read "employee" where its own systems say
 * officer. Small, and corrosive: a screen that does not use your words reads as
 * a report about somebody else, and the numbers on it get trusted accordingly.
 *
 * ONE QUERY PER TENANT PER REQUEST. Bound as scoped() alongside EntityResolver,
 * for the same reason: terminology is configuration and changes while the
 * application runs, so a process-lifetime cache would serve a stale label until
 * the worker recycled.
 *
 * IT FALLS BACK RATHER THAN THROWING, which is the opposite of EntityResolver
 * and deliberately so. An unmapped ENTITY is a correctness problem — reading the
 * wrong table returns another tenant's rows. An unmapped LABEL is a cosmetic
 * one: the generic word is wrong-ish, not dangerous, and refusing to render a
 * dashboard because nobody chose a synonym for "Role" would be absurd.
 */
final class Vocabulary
{
    /** @var array<string, array<string, array{singular: string, plural: string}>> */
    private array $cache = [];

    /** Used when a tenant has no row and the industry has no override. */
    private const FALLBACK = [
        'Person'           => ['Employee', 'Employees'],
        'OrganizationUnit' => ['Department', 'Departments'],
        'Organization'     => ['Organization', 'Organizations'],
        'Position'         => ['Role', 'Roles'],
        'Capability'       => ['Capability', 'Capabilities'],
        'Skill'            => ['Skill', 'Skills'],
        'Competency'       => ['Competency', 'Competencies'],
    ];

    /** The singular label, lowercased for use mid-sentence. */
    public function word(string $tenantId, string $entity): string
    {
        return strtolower($this->term($tenantId, $entity)['singular']);
    }

    /** The plural label, lowercased for use mid-sentence. */
    public function words(string $tenantId, string $entity): string
    {
        return strtolower($this->term($tenantId, $entity)['plural']);
    }

    /** The singular label as configured, for a heading or the start of a sentence. */
    public function label(string $tenantId, string $entity): string
    {
        return $this->term($tenantId, $entity)['singular'];
    }

    /** The plural label as configured. */
    public function labels(string $tenantId, string $entity): string
    {
        return $this->term($tenantId, $entity)['plural'];
    }

    /**
     * Singular or plural chosen by a count.
     *
     * Takes the count rather than a boolean so call sites read as the sentence
     * they produce: "18 wards", "1 ward".
     */
    public function countOf(string $tenantId, string $entity, int $count): string
    {
        return $count.' '.($count === 1 ? $this->word($tenantId, $entity) : $this->words($tenantId, $entity));
    }

    /** @return array{singular: string, plural: string} */
    private function term(string $tenantId, string $entity): array
    {
        $this->load($tenantId);

        return $this->cache[$tenantId][$entity] ?? [
            'singular' => self::FALLBACK[$entity][0] ?? $entity,
            'plural'   => self::FALLBACK[$entity][1] ?? $entity,
        ];
    }

    private function load(string $tenantId): void
    {
        if (isset($this->cache[$tenantId])) {
            return;
        }

        $this->cache[$tenantId] = [];

        try {
            $rows = DB::table('hpbrain_terminology')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->get();
        } catch (\Throwable) {
            // An unmigrated or unreachable terminology table must not take a
            // dashboard down. Every caller has a usable fallback.
            return;
        }

        foreach ($rows as $row) {
            $singular = (string) $row->display_name;

            $this->cache[$tenantId][(string) $row->entity_type] = [
                'singular' => $singular,
                'plural'   => ($row->plural_name ?? null) !== null && $row->plural_name !== ''
                    ? (string) $row->plural_name
                    : $singular.'s',
            ];
        }
    }
}
