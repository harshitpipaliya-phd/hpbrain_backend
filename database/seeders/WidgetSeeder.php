<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WidgetSeeder extends Seeder
{
    public function run(): void
    {
        $widgets = [
            ['widget_key' => 'signal_summary', 'name' => 'Signal Summary', 'description' => 'Summary of recent signals', 'category' => 'intelligence', 'component_type' => 'SignalSummaryWidget'],
            ['widget_key' => 'decision_pipeline', 'name' => 'Decision Pipeline', 'description' => 'Decision pipeline overview', 'category' => 'decisions', 'component_type' => 'DecisionPipelineWidget'],
            ['widget_key' => 'capability_heatmap', 'name' => 'Capability Heatmap', 'description' => 'Capability heatmap visualization', 'category' => 'capabilities', 'component_type' => 'CapabilityHeatmapWidget'],
            ['widget_key' => 'team_performance', 'name' => 'Team Performance', 'description' => 'Team performance metrics', 'category' => 'people', 'component_type' => 'TeamPerformanceWidget'],
            ['widget_key' => 'analytics_chart', 'name' => 'Analytics Chart', 'description' => 'General analytics chart', 'category' => 'analytics', 'component_type' => 'AnalyticsChartWidget'],
            ['widget_key' => 'task_monitor', 'name' => 'Task Monitor', 'description' => 'Task execution monitor', 'category' => 'operations', 'component_type' => 'TaskMonitorWidget'],
        ];

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $tenantId = 'platform';

        foreach ($widgets as $widget) {
            DB::table('hpbrain_dashboard_widgets')->insert([
                'id'             => Uuid::uuid4()->toString(),
                'tenant_id'      => $tenantId,
                'widget_key'     => $widget['widget_key'],
                'name'           => $widget['name'],
                'description'    => $widget['description'],
                'category'       => $widget['category'],
                'component_type' => $widget['component_type'],
                'config_schema'  => json_encode([]),
                'default_config' => json_encode([]),
                'icon'           => 'widgets',
                'is_system'      => true,
                'created_by'     => 'system',
                'created_date'   => $now,
                'updated_date'   => $now,
            ]);
        }
    }
}
