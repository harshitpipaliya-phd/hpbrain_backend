<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DashboardWidgetRepository;
use Illuminate\Support\Facades\DB;

final class DashboardBuilder
{
    public function __construct(
        private readonly DashboardWidgetRepository $widgetRepo,
    ) {
    }

    public function build(string $tenantId, string $orgId, string $dashboardKey, array $userContext): ?array
    {
        $dashboard = DB::table('hpbrain_dashboards')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($orgId, $dashboardKey) {
                $q->where('org_id', $orgId)->where('dashboard_key', $dashboardKey);
            })
            ->orWhere(function ($q) use ($tenantId, $dashboardKey) {
                $q->where('tenant_id', $tenantId)->whereNull('org_id')->where('dashboard_key', $dashboardKey);
            })
            ->first();

        if (!$dashboard) {
            return null;
        }

        $dashboard = (array) $dashboard;

        $layoutData = $dashboard['layout'] ?? [];
        if (is_string($layoutData)) {
            $layoutData = json_decode($layoutData, true) ?: [];
        }

        $layout = $this->resolveLayout($tenantId, $dashboard['id'], $layoutData);

        $dashboard['layout'] = $layout;
        $dashboard['widgets'] = $this->resolveWidgets($tenantId, $layout);

        return $dashboard;
    }

    private function resolveLayout(string $tenantId, string $dashboardId, array $layout): array
    {
        $dbLayout = DB::table('hpbrain_dashboard_layouts')
            ->where('tenant_id', $tenantId)
            ->where('dashboard_id', $dashboardId)
            ->first();

        if ($dbLayout) {
            $layoutData = (array) $dbLayout;
            $layoutData['widgets'] = json_decode($layoutData['widgets'] ?? '[]', true) ?: [];
            return $layoutData;
        }

        return [
            'layout_type'  => $layout['layout_type'] ?? 'grid',
            'grid_columns' => $layout['grid_columns'] ?? 12,
            'grid_rows'    => $layout['grid_rows'] ?? 12,
            'widgets'      => $layout['widgets'] ?? [],
        ];
    }

    private function resolveWidgets(string $tenantId, array $layout): array
    {
        $widgets = $layout['widgets'] ?? [];
        $resolved = [];

        foreach ($widgets as $widget) {
            $widgetKey = $widget['widget_key'] ?? null;
            $definition = $widgetKey ? $this->widgetRepo->findByKey($tenantId, $widgetKey) : null;

            $resolved[] = [
                'widget_key'   => $widgetKey,
                'config'       => $widget['config'] ?? [],
                'definition'   => $definition,
                'layout'       => $widget['layout'] ?? null,
            ];
        }

        return $resolved;
    }
}
