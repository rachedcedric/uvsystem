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

    protected static ?string $maxHeight = '280px';

    /** Un anneau n'a pas besoin d'axes : on les masque (sinon l'échelle 0-1 occupe tout le graphique). */
    protected function getOptions(): array
    {
        return [
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $byPlan = app(SubscriptionMetrics::class)->snapshot()['by_plan'];

        return [
            'datasets' => [[
                'label' => 'Tenants',
                'data' => array_column($byPlan, 'count'),
                'backgroundColor' => ['#168447', '#28a75c', '#0b2f21', '#d9f36a', '#7fbf9a', '#4b5f55'],
            ]],
            'labels' => array_keys($byPlan),
        ];
    }
}
