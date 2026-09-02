<?php

declare(strict_types=1);

namespace App\Domain\Eso;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The tenant's executable objects, indexed by the gap kinds their authors said
 * they close.
 *
 * WHY THIS EXISTS. RecommendationEngine shipped every recommendation with
 * `esoId: null` and a fixed note reading "No executable object definitions
 * exist for this organization". That sentence was hardcoded, so it was printed
 * unchanged to organizations that had four published ESOs — a false statement
 * about the reader's own data, on the screen whose whole job is to be trusted.
 * It also meant Invariant 3 could never be satisfied by the model: an action
 * the organization could genuinely run was still presented as unbindable.
 *
 * MATCHING IS A LOOKUP, NOT AN INFERENCE. An ESO matches a recommendation when
 * the ESO's own `gap_types` column names that recommendation's gap kind. That
 * is a binding an author wrote down on the definition; this class only reads
 * it back. There is deliberately no fuzzy fallback — no title similarity, no
 * "closest category", no matching on the esoType word — because a suggested
 * match that turns out to be wrong is worse than no match at all when the
 * button next to it says Run.
 *
 * WHEN NOTHING MATCHES, THE NOTE STATES WHICH IS TRUE: that the catalogue is
 * empty, or that it holds N definitions and none of them declares this gap
 * type. Those are different problems with different fixes — author an ESO,
 * versus tag an existing one — and collapsing them into one sentence is what
 * made the original note wrong.
 */
final class EsoCatalogue
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $byGapType
     */
    private function __construct(
        private readonly array $byGapType,
        private readonly int $total,
        private readonly int $inService,
    ) {
    }

    /** An empty catalogue — used where no tenant context is available. */
    public static function empty(): self
    {
        return new self([], 0, 0);
    }

    /**
     * Read one tenant's definitions.
     *
     * Failures are swallowed into an empty catalogue on purpose: this runs
     * inside the intelligence composition, and a missing table on a partially
     * migrated environment must not blank an entire intelligence read. An empty
     * catalogue produces the honest "no definitions" note, which is what an
     * unreadable catalogue also means for the reader.
     */
    public static function forTenant(string $tenantId): self
    {
        try {
            if (! Schema::hasTable('hpbrain_eso_definitions')) {
                return self::empty();
            }

            $rows = DB::table('hpbrain_eso_definitions')
                ->where('tenant_id', $tenantId)
                ->get(['id', 'eso_code', 'name', 'status', 'gap_types', 'objective', 'kasba_node_type', 'trigger_description']);
        } catch (Throwable) {
            return self::empty();
        }

        $byGapType = [];
        $inService = 0;

        foreach ($rows as $row) {
            $runnable = EsoStatus::inService($row->status ?? null);

            if ($runnable) {
                $inService++;
            }

            foreach (self::gapTypes($row->gap_types ?? null) as $gapType) {
                $byGapType[$gapType][] = [
                    'id' => (string) $row->id,
                    'esoCode' => (string) ($row->eso_code ?? ''),
                    'name' => (string) ($row->name ?? ''),
                    'status' => (string) ($row->status ?? ''),
                    'inService' => $runnable,
                    'purpose' => $row->trigger_description === null ? null : (string) $row->trigger_description,
                ];
            }
        }

        return new self($byGapType, $rows->count(), $inService);
    }

    /**
     * The binding for one recommendation, ready to be merged into its payload.
     *
     * An in-service definition is preferred over a withdrawn one carrying the
     * same tag, because offering Run against a retired ESO is a dead button.
     * A withdrawn match is still reported — it tells the reader the capability
     * was authored and then taken out of service, which is a finding of its own.
     *
     * @return array{esoId: string|null, esoCode: string|null, esoName: string|null, esoRunnable: bool, esoNote: string}
     */
    public function bindingFor(?string $gapKind, string $esoType): array
    {
        $candidates = $gapKind === null ? [] : ($this->byGapType[self::normalize($gapKind)] ?? []);

        $chosen = null;

        foreach ($candidates as $candidate) {
            if ($candidate['inService']) {
                $chosen = $candidate;
                break;
            }

            $chosen ??= $candidate;
        }

        if ($chosen === null) {
            return [
                'esoId' => null,
                'esoCode' => null,
                'esoName' => null,
                'esoRunnable' => false,
                'esoNote' => $this->unboundNote($gapKind, $esoType),
            ];
        }

        return [
            'esoId' => $chosen['id'],
            'esoCode' => $chosen['esoCode'],
            'esoName' => $chosen['name'],
            'esoRunnable' => $chosen['inService'],
            'esoNote' => $chosen['inService']
                ? 'Bound to '.$chosen['name'].' ('.$chosen['esoCode'].'), which declares this finding in its gap types. It can be viewed and run from the ESO Library.'
                : 'The only ESO declaring this finding, '.$chosen['name'].' ('.$chosen['esoCode'].'), is in the status "'.$chosen['status'].'" and is not currently runnable.',
        ];
    }

    public function total(): int
    {
        return $this->total;
    }

    public function inServiceCount(): int
    {
        return $this->inService;
    }

    private function unboundNote(?string $gapKind, string $esoType): string
    {
        if ($this->total === 0) {
            return 'No executable object definitions exist for this organization, so this cannot yet be bound to something runnable. The execution type it needs is stated instead.';
        }

        $catalogue = $this->total.' executable object'.($this->total === 1 ? '' : 's').' exist'.($this->total === 1 ? 's' : '').' in this organization';

        if ($gapKind === null) {
            return $catalogue.', but this action does not come from a classified finding, so it cannot be matched to one by gap type. It needs a '.$esoType.' capability.';
        }

        return $catalogue.', but none of them declares "'.$gapKind.'" in its gap types, so nothing here is bound to a runnable object. Tagging an existing ESO with that gap type, or authoring one, would bind it. It needs a '.$esoType.' capability.';
    }

    /** @return array<int, string> */
    private static function gapTypes(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $entry) {
            if (is_scalar($entry) && trim((string) $entry) !== '') {
                $out[] = self::normalize((string) $entry);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Gap kinds are snake_case identifiers in the detector and are hand-typed
     * into the ESO's gap_types column. Case and separator differences are a
     * transcription artefact, not a different tag, so they are normalised away
     * — but nothing else is. Two genuinely different words never collapse.
     */
    private static function normalize(string $value): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($value)));
    }
}
