<?php

namespace App\Filament\Platform\Widgets;

use App\Filament\Platform\Resources\SubscriptionChangeRequestResource;
use App\Filament\Platform\Resources\TenantResource;
use App\Filament\Platform\Resources\TenantSubscriptionResource;
use App\Services\Subscriptions\TenantHealthMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getStats(): array
    {
        $metrics = app(TenantHealthMetrics::class)->snapshot();

        return [
            Stat::make('Quotas atteints', $metrics['quota_reached'])
                ->description("{$metrics['user_limit_reached']} utilisateurs · {$metrics['agent_limit_reached']} agents")
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($metrics['quota_reached'] > 0 ? 'warning' : 'success')
                ->url(TenantResource::getUrl()),
            Stat::make('Demandes à traiter', $metrics['pending_changes'])
                ->description('changements d’offre en attente')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color($metrics['pending_changes'] > 0 ? 'warning' : 'success')
                ->url(SubscriptionChangeRequestResource::getUrl()),
            Stat::make('Paiements en attente', $metrics['pending_payments'])
                ->description('renouvellements et changements')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($metrics['pending_payments'] > 0 ? 'warning' : 'success')
                ->url(TenantSubscriptionResource::getUrl()),
            Stat::make('Tenants sans Master', $metrics['without_master'])
                ->description('comptes à sécuriser')
                ->descriptionIcon('heroicon-m-user-minus')
                ->color($metrics['without_master'] > 0 ? 'danger' : 'success')
                ->url(TenantResource::getUrl()),
        ];
    }
}
