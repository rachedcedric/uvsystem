<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AlertesWidget;
use App\Filament\Widgets\CaChartWidget;
use App\Filament\Widgets\MainKpisWidget;
use App\Filament\Widgets\MonthlyChartWidget;
use App\Filament\Widgets\MonthlyPerformanceWidget;
use App\Filament\Widgets\NotificationsRecentesWidget;
use App\Filament\Widgets\RealTimeActivityWidget;
use App\Filament\Widgets\RevenueChargesNetWidget;
use App\Filament\Widgets\TopAgentsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * Le panel enregistre (via discoverWidgets) tous les widgets du dossier
     * app/Filament/Widgets — y compris ceux utilisés uniquement sur d'autres
     * pages (ex: RemboursementsStatsWidget sur la liste des remboursements).
     * Cette page n'affiche qu'un sous-ensemble curé, dans l'ordre de
     * priorité métier, plutôt que la totalité du pool enregistré.
     *
     * @return array<class-string<\Filament\Widgets\Widget>>
     */
    public function getWidgets(): array
    {
        return [
            MainKpisWidget::class,
            RevenueChargesNetWidget::class,
            RealTimeActivityWidget::class,
            CaChartWidget::class,
            MonthlyPerformanceWidget::class,
            MonthlyChartWidget::class,
            AlertesWidget::class,
            TopAgentsWidget::class,
            NotificationsRecentesWidget::class,
        ];
    }
}
