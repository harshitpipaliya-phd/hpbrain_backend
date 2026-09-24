<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Industry\IndustryPack;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * One template per industry, carrying that industry's own vocabulary.
 *
 * The previous version gave all ten industries the SAME terminology —
 * Employee, Department, Role — which meant the template layer existed but
 * configured nothing. A hospital and a bank onboarded identically, and the only
 * industry-specific thing in the system was the word "Template" in a name.
 *
 * Terminology now comes from IndustryPack, so a ward is a ward and a branch is a
 * branch, and there is exactly one place to change it.
 *
 * WHAT IS STILL EMPTY, AND WHY IT IS LEFT EMPTY. navigation, dashboards,
 * branding and workflows are written as empty structures rather than invented
 * defaults. There is no industry-neutral truth about what a hospital's default
 * dashboard should contain, and shipping a plausible-looking guess would mean
 * every tenant inherits a layout nobody chose while appearing to have been
 * configured. An empty structure is visibly unconfigured; a fabricated one is
 * not. assessment_model is likewise left null so AssessmentModelResolver falls
 * through to the platform default — an industry that genuinely does not assess
 * on five dimensions should say so explicitly, not by accident.
 */
final class IndustryTemplateSeeder extends Seeder
{
    private const PLATFORM = 'platform';

    public function run(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $existing = DB::table('hpbrain_industry_templates')
            ->where('tenant_id', self::PLATFORM)
            ->pluck('industry_code')
            ->all();
        $existing = array_flip(array_map('strval', $existing));

        $rows = [];

        foreach (IndustryPack::industries() as $code) {
            if (isset($existing[$code])) {
                continue;
            }

            $label = IndustryPack::label($code);

            $rows[] = [
                'id'            => Uuid::uuid4()->toString(),
                'tenant_id'     => self::PLATFORM,
                'industry_code' => $code,
                'template_name' => $label.' Template',
                'description'   => 'Default configuration for '.$label.'.',
                'terminology'   => json_encode(IndustryPack::terminology($code)),
                'modules'       => json_encode(['intelligence', 'capabilities', 'decisions', 'analytics']),
                'navigation'    => json_encode([]),
                'dashboards'    => json_encode([]),
                'branding'      => json_encode([]),
                'workflows'     => json_encode([]),
                'integrations'  => json_encode([]),
                'is_system'     => false,
                'is_active'     => true,
                'created_by'    => 'system',
                'created_date'  => $now,
                'updated_date'  => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('hpbrain_industry_templates')->insert($chunk);
        }

        $this->command?->info(
            'IndustryTemplateSeeder: '.count($rows).' written, '.count($existing).' left untouched.'
        );
    }
}
