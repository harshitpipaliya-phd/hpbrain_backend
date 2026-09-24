<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $tenantId = 'platform';

        $lightTheme = [
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $tenantId,
            'theme_key'    => 'light',
            'name'         => 'Light',
            'description'  => 'Default light theme',
            'colors'       => json_encode([
                'primary' => '#2563eb',
                'secondary' => '#64748b',
                'accent' => '#3b82f6',
                'background' => '#ffffff',
                'surface' => '#f8fafc',
                'text' => '#1e293b',
                'textSecondary' => '#64748b',
                'border' => '#e2e8f0',
                'error' => '#ef4444',
                'success' => '#22c55e',
                'warning' => '#f59e0b',
            ]),
            'typography'   => json_encode([
                'fontFamily' => 'Inter, sans-serif',
                'fontSizeBase' => '14px',
                'fontSizeSm' => '12px',
                'fontSizeLg' => '16px',
                'fontSizeXl' => '20px',
                'fontWeightNormal' => '400',
                'fontWeightMedium' => '500',
                'fontWeightBold' => '700',
            ]),
            'spacing'      => json_encode(['xs' => '4px', 'sm' => '8px', 'md' => '16px', 'lg' => '24px', 'xl' => '32px']),
            'borderRadius' => json_encode(['sm' => '4px', 'md' => '8px', 'lg' => '12px', 'full' => '9999px']),
            'shadows'      => json_encode(['sm' => '0 1px 2px rgba(0,0,0,0.05)', 'md' => '0 4px 6px rgba(0,0,0,0.1)', 'lg' => '0 10px 15px rgba(0,0,0,0.1)']),
            'is_dark'      => false,
            'is_default'   => true,
            'created_by'   => 'system',
            'created_date' => $now,
            'updated_date' => $now,
        ];

        $darkTheme = [
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $tenantId,
            'theme_key'    => 'dark',
            'name'         => 'Dark',
            'description'  => 'Default dark theme',
            'colors'       => json_encode([
                'primary' => '#3b82f6',
                'secondary' => '#94a3b8',
                'accent' => '#60a5fa',
                'background' => '#0f172a',
                'surface' => '#1e293b',
                'text' => '#f1f5f9',
                'textSecondary' => '#94a3b8',
                'border' => '#334155',
                'error' => '#ef4444',
                'success' => '#22c55e',
                'warning' => '#f59e0b',
            ]),
            'typography'   => json_encode([
                'fontFamily' => 'Inter, sans-serif',
                'fontSizeBase' => '14px',
                'fontSizeSm' => '12px',
                'fontSizeLg' => '16px',
                'fontSizeXl' => '20px',
                'fontWeightNormal' => '400',
                'fontWeightMedium' => '500',
                'fontWeightBold' => '700',
            ]),
            'spacing'      => json_encode(['xs' => '4px', 'sm' => '8px', 'md' => '16px', 'lg' => '24px', 'xl' => '32px']),
            'borderRadius' => json_encode(['sm' => '4px', 'md' => '8px', 'lg' => '12px', 'full' => '9999px']),
            'shadows'      => json_encode(['sm' => '0 1px 2px rgba(0,0,0,0.3)', 'md' => '0 4px 6px rgba(0,0,0,0.4)', 'lg' => '0 10px 15px rgba(0,0,0,0.5)']),
            'is_dark'      => true,
            'is_default'   => false,
            'created_by'   => 'system',
            'created_date' => $now,
            'updated_date' => $now,
        ];

        DB::table('hpbrain_themes')->insert([$lightTheme, $darkTheme]);
    }
}
