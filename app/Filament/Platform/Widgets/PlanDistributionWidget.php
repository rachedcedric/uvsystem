<?php

namespace App\Filament\Platform\Widgets;

use App\Services\Subscriptions\SubscriptionMetrics;
use Filament\Widgets\ChartWidget;

class PlanDistributionWidget extends ChartWidget
{
    protected static ?string $heading = 'Répartition par offre';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $byPlan = app(SubscriptionMetrics::class)->snapshot()['by_plan'];

        return [
            'datasets' => [['label' => 'Tenants', 'data' => array_column($byPlan, 'count')]],
            'labels' => array_keys($byPlan),
        ];
    }
}
