<?php

declare(strict_types=1);

namespace App\Domain\Eso;

/**
 * Which lifecycle states of an ESO definition mean "in service".
 *
 * THIS EXISTS BECAUSE THE VOCABULARY IS NOT ONE WORD. Two writers already put
 * different values in hpbrain_eso_definitions.status: FiberValleyDemoSeeder
 * writes 'published', DeriveLionsIntelligence writes 'active', and the migration
 * defaults to 'draft'. Code that compared status to the literal 'active'
 * therefore reported an organization with four published, runnable ESOs as
 * having none — the KPI on the library screen read 0 of 4.
 *
 * The set is stated once here rather than inlined at each comparison, so the
 * catalogue count, the readiness gate and the execution gate can never disagree
 * about whether a given ESO is in service.
 *
 * UNKNOWN STATUSES ARE NOT IN SERVICE. A state nobody has declared runnable is
 * refused rather than assumed, and the readiness payload names the status it
 * refused, so an ESO with a new lifecycle word shows a specific reason instead
 * of silently disappearing.
 */
final class EsoStatus
{
    /** Statuses that mean the definition is authored, approved and usable. */
    public const IN_SERVICE = ['active', 'published', 'approved', 'released'];

    /** Statuses that mean the definition exists but must not be run, and why. */
    public const WITHDRAWN = [
        'draft'      => 'still a draft — it has not been published for use',
        'retired'    => 'retired — it is kept for the record but is no longer run',
        'deprecated' => 'deprecated — a replacement is expected to be used instead',
        'superseded' => 'superseded by a newer version',
        'archived'   => 'archived',
        'disabled'   => 'disabled',
        'inactive'   => 'inactive',
    ];

    public static function inService(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::IN_SERVICE, true);
    }

    /** Why this status is not runnable, in a sentence a non-technical reader can act on. */
    public static function explain(?string $status): string
    {
        $normalized = strtolower(trim((string) $status));

        if ($normalized === '') {
            return 'This ESO records no lifecycle status, so it has not been published for use.';
        }

        return 'This ESO is '.(self::WITHDRAWN[$normalized] ?? "in the status '{$normalized}', which is not a published state").'.';
    }
}
