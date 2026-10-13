<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AlertesWidget;
use App\Filament\Widgets\CaChartWidget;
use App\Filament\Widgets\MainKpisWidget;
use App\Filament\Widgets\MonthlyChartWidget;
use App\Filament\Widgets\MonthlyPerformanceWidget;
use App\Filament\Widgets\NotificationsRecentesWidget;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RealTimeActivityWidget;
use App\Filament\Widgets\RevenueChargesNetWidget;
use App\Filament\Widgets\TopAgentsWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class Dashboard extends BaseDashboard
{
    /**
     * Le panel enregistre (via discoverWidgets) tous les widgets du dossier
     * app/Filament/Widgets — y compris ceux utilisés uniquement sur d'autres
     * pages (ex: RemboursementsStatsWidget sur la liste des remboursements).
     * Cette page n'affiche qu'un sous-ensemble curé, dans l'ordre de
     * priorité métier, plutôt que la totalité du pool enregistré.
     *
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            QuickActionsWidget::class,
            MainKpisWidget::class,
            RevenueChargesNetWidget::class,
            MonthlyPerformanceWidget::class,
            CaChartWidget::class,
            TopAgentsWidget::class,
            MonthlyChartWidget::class,
            AlertesWidget::class,
            RealTimeActivityWidget::class,
            NotificationsRecentesWidget::class,
        ];
    }

    /** Une colonne sur mobile et tablette, trois sur grand écran (graphiques côte à côte). */
    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'xl' => 3];
    }

    public function getSubheading(): string|Htmlable|null
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return collect(['Bonjour '.Str::before((string) $user->name, ' '), $user->tenant?->name])->filter()->implode(' · ');
    }
}
