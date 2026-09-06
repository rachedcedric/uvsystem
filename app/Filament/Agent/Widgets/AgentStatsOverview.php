<?php

namespace App\Filament\Agent\Widgets;

use App\Models\Agent;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AgentStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = Auth::user();
        $agent = Agent::where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        if (! $agent) {
            return [
                Stat::make('Erreur', 'Compte agent non lié')
                    ->description('Contactez l\'administrateur')
                    ->color('danger'),
            ];
        }

        $solde = $agent->solde;
        $dette = $solde?->solde_restant ?? 0;
        $plafond = $agent->debt_limit;
        $ratio = $plafond && $plafond > 0 ? round($dette / $plafond * 100, 1) : 0;

        return [
            Stat::make('Dette actuelle', number_format($dette, 0, ',', ' ').' FCFA')
                ->description($plafond ? 'Plafond: '.number_format($plafond, 0, ',', ' ').' FCFA' : 'Sans plafond')
                ->color($ratio > 80 ? 'danger' : ($ratio > 50 ? 'warning' : 'success'))
                ->icon('heroicon-o-banknotes'),

            Stat::make('Total UV reçus', number_format($solde?->total_uv_distribues ?? 0, 0, ',', ' ').' FCFA')
                ->description('Depuis le début')
                ->color('info')
                ->icon('heroicon-o-inbox-arrow-down'),

            Stat::make('Total remboursé', number_format($solde?->total_rembourse ?? 0, 0, ',', ' ').' FCFA')
                ->description('Tous vos paiements')
                ->color('success')
                ->icon('heroicon-o-arrow-trending-up'),

            Stat::make('Crédit disponible', $plafond
                ? number_format(max(0, $plafond - $dette), 0, ',', ' ').' FCFA'
                : 'Illimité')
                ->description($ratio > 0 ? "Utilisé: {$ratio}%" : 'Aucune dette')
                ->color($ratio > 80 ? 'danger' : 'success')
                ->icon('heroicon-o-credit-card'),
        ];
    }
}
