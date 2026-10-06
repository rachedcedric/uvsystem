<?php

namespace App\Filament\Widgets;

use App\Services\AnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ScoringOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '60s';

    // Ne pas afficher sur le dashboard principal, uniquement sur la page Scoring
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $analytics = app(AnalyticsService::class);
        $scoring = $analytics->getAgentScoring();

        if ($scoring->isEmpty()) {
            return [
                Stat::make('Score Moyen', '-')
                    ->description('Aucun agent')
                    ->color('gray'),
            ];
        }

        $avgScore = round($scoring->avg('score'), 1);
        $bestAgent = $scoring->first();
        $worstAgent = $scoring->last();

        // Compter les grades
        $gradeA = $scoring->filter(fn ($s) => in_array($s['grade'], ['A+', 'A']))->count();
        $gradeB = $scoring->filter(fn ($s) => in_array($s['grade'], ['B+', 'B']))->count();
        $gradeC = $scoring->filter(fn ($s) => in_array($s['grade'], ['C+', 'C']))->count();
        $gradeDE = $scoring->filter(fn ($s) => in_array($s['grade'], ['D', 'E']))->count();

        return [
            Stat::make('📊 Score Moyen', "{$avgScore}/100")
                ->description($this->getScoreDescription($avgScore))
                ->descriptionIcon($avgScore >= 60 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($avgScore >= 60 ? 'success' : ($avgScore >= 40 ? 'warning' : 'danger')),

            Stat::make('🥇 Meilleur Agent', $bestAgent['name'])
                ->description("Score: {$bestAgent['score']}/100 ({$bestAgent['grade']})")
                ->descriptionIcon('heroicon-m-trophy')
                ->color('success'),

            Stat::make('🟢 Grades A', $gradeA)
                ->description('Excellents agents')
                ->descriptionIcon('heroicon-m-star')
                ->color($gradeA > 0 ? 'success' : 'gray'),

            Stat::make('🔵 Grades B', $gradeB)
                ->description('Bons agents')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($gradeB > 0 ? 'info' : 'gray'),

            Stat::make('🟡 Grades C', $gradeC)
                ->description('Agents moyens')
                ->descriptionIcon('heroicon-m-minus-circle')
                ->color($gradeC > 0 ? 'warning' : 'gray'),

            Stat::make('🔴 Grades D/E', $gradeDE)
                ->description('Agents à améliorer')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($gradeDE > 0 ? 'danger' : 'gray'),
        ];
    }

    private function getScoreDescription(float $avgScore): string
    {
        return match (true) {
            $avgScore >= 80 => 'Excellent réseau !',
            $avgScore >= 60 => 'Bon niveau global',
            $avgScore >= 40 => 'À améliorer',
            default => 'Besoin d\'attention',
        };
    }
}
