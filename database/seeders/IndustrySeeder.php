<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Industry\IndustryPack;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The industries the platform knows, derived from the packs rather than listed.
 *
 * This used to hold its own hardcoded array, and it drifted. It omitted telecom
 * entirely, so the live telecommunications operator could not have been
 * classified even if something had tried; and the sibling template seeder listed
 * a k12_education that this one spelled differently. Reading
 * IndustryPack::industries() makes that class of drift impossible: an industry
 * exists exactly when a pack defines what its organizations are measured on.
 * An industry with no capabilities behind it is a name, not a configuration.
 *
 * IT NEVER OVERWRITES an existing row. An operator may have renamed an industry
 * or retired it, and that edit outranks the default that seeded it.
 */
final class IndustrySeeder extends Seeder
{
    private const PLATFORM = 'platform';

    /**
     * The one presentation detail kept here rather than in the pack, because a
     * pack describes what an industry DOES and an icon does not.
     *
     * Anything without an entry falls back rather than failing — a new pack
     * should not have to think about iconography to exist.
     */
    private const ICONS = [
        'healthcare'       => 'local_hospital',
        'k12_education'    => 'school',
        'higher_education' => 'account_balance',
        'corporate'        => 'business',
        'manufacturing'    => 'precision_manufacturing',
        'retail'           => 'shopping_cart',
        'government'       => 'gavel',
        'bfsi'             => 'account_balance_wallet',
        'ngo'              => 'volunteer_activism',
        'technology'       => 'computer',
        'telecom'          => 'cell_tower',
    ];

    public function run(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $existing = DB::table('hpbrain_industries')
            ->where('tenant_id', self::PLATFORM)
            ->pluck('code')
            ->all();
        $existing = array_flip(array_map('strval', $existing));

        $rows = [];
        $sort = 0;

        foreach (IndustryPack::industries() as $code) {
            $sort++;

            if (isset($existing[$code])) {
                continue;
            }

            $rows[] = [
                'id'           => Uuid::uuid4()->toString(),
                'tenant_id'    => self::PLATFORM,
                'code'         => $code,
                'name'         => IndustryPack::label($code),
                'description'  => 'Ships '.count(IndustryPack::capabilities($code))
                    .' starting capabilities and its own terminology.',
                'icon'         => self::ICONS[$code] ?? 'business',
                'sort_order'   => $sort,
                'status'       => 'active',
                'created_by'   => 'system',
                'created_date' => $now,
                'updated_date' => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('hpbrain_industries')->insert($chunk);
        }

        $this->command?->info(
            'IndustrySeeder: '.count($rows).' written, '.count($existing).' left untouched.'
        );
    }
}
