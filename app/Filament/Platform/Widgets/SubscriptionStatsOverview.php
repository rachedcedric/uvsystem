<?php

namespace App\Filament\Platform\Widgets;

use App\Services\Subscriptions\SubscriptionMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SubscriptionStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getStats(): array
    {
        $m = app(SubscriptionMetrics::class)->snapshot();
        $fcfa = fn (int $v) => number_format($v, 0, ',', ' ').' FCFA';

        return [
            Stat::make('Tenants', $m['tenants'])->description($m['without_subscription'] > 0 ? "{$m['without_subscription']} sans abonnement" : 'tous ont un abonnement'),
            Stat::make('En essai', $m['trialing'])->description("{$m['trials_expiring_soon']} expirent sous 7 jours")->color('info'),
            Stat::make('Actifs', $m['active'])->description("{$m['expiring_soon']} expirent sous 7 jours")->color('success'),
            Stat::make('Expirés', $m['expired'])->description("{$m['cancelled']} annulés")->color('danger'),
            Stat::make('Suspendus', $m['suspended'])->color('warning'),
            Stat::make('MRR estimé', $fcfa($m['mrr']))->description('revenu récurrent estimé, non encaissé'),
            Stat::make('ARR estimé', $fcfa($m['arr']))->description('MRR × 12 (annuel : prix annuel)'),
        ];
    }
}
