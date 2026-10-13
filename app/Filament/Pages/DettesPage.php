<?php

namespace App\Filament\Pages;

use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Vue "Dettes" : il n'existe pas d'entité Dette individualisée dans ce
 * projet (voir DetteAgent/dettes_agents, table inutilisée liée à
 * AgentPartenaire, un concept différent et non exploité). La dette réelle
 * est un solde global par agent (Agent::current_debt, alimenté par
 * UvTransaction de type distribution et soldé par Remboursement) : c'est
 * exactement la même source de vérité que la page de création de
 * remboursement et que les rappels automatiques ("Notifications Récentes").
 * Une ligne de ce tableau = un agent, pas une vente individuelle.
 */
class DettesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Dettes';

    // Filament dérive par défaut un slug "dettes-page" du nom de la classe :
    // forcé ici pour correspondre exactement à /master/dettes.
    protected static ?string $slug = 'dettes';

    protected static ?string $title = 'Dettes en cours';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.dettes-page';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_remboursements');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::agentsEndettesQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Agents actifs ayant une dette non soldée (reste dû > 0) : la même
     * requête sert de base au tableau, au badge de menu et aux rappels
     * automatiques (NotificationService::checkAndSendDebtReminders()).
     */
    public static function agentsEndettesQuery(): Builder
    {
        return Agent::query()->active()->withDebt();
    }

    /**
     * Résumé (tuiles en haut de page), toujours calculé sur les dettes
     * actives réelles, indépendamment du filtre courant du tableau.
     *
     * Statique (au lieu d'une méthode d'instance) pour être réutilisable
     * telle quelle par DettesGlobalStatsWidget, sans dupliquer le calcul.
     *
     * @return array{count: int, debtToday: float, repaidToday: float, outstanding: float}
     */
    public static function getSummary(): array
    {
        $today = today();
        $now = now();

        return [
            'count' => static::agentsEndettesQuery()->count(),
            'debtToday' => (float) UvTransaction::query()
                ->where('transaction_type', 'distribution')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('transaction_date', [$today, $now])
                ->whereHas('agent', fn (Builder $query) => $query->active())
                ->sum('amount'),
            'repaidToday' => (float) Remboursement::query()
                ->whereBetween('payment_date', [$today, $now])
                ->whereHas('agent', fn (Builder $query) => $query->active())
                ->sum('amount'),
            'outstanding' => (float) static::agentsEndettesQuery()->sum('current_debt'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                static::agentsEndettesQuery()
                    ->withSum([
                        'transactions as debt_today' => fn (Builder $query) => $query
                            ->where('transaction_type', 'distribution')
                            ->where('status', '!=', 'cancelled')
                            ->whereBetween('transaction_date', [today(), now()]),
                    ], 'amount')
                    ->withSum([
                        'remboursements as repaid_today' => fn (Builder $query) => $query
                            ->whereBetween('payment_date', [today(), now()]),
                    ], 'amount')
                    ->withMax([
                        'transactions as last_debt_today_at' => fn (Builder $query) => $query
                            ->where('transaction_type', 'distribution')
                            ->where('status', '!=', 'cancelled')
                            ->whereBetween('transaction_date', [today(), now()]),
                    ], 'transaction_date')
                    ->withMax([
                        'remboursements as last_repayment_today_at' => fn (Builder $query) => $query
                            ->whereBetween('payment_date', [today(), now()]),
                    ], 'payment_date')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Agent')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhereHas('transactions', fn (Builder $t) => $t->where('reference', 'like', "%{$search}%"));
                        });
                    })
                    ->weight('bold')
                    ->description(fn (Agent $record) => $record->phone ?? ''),

                Tables\Columns\TextColumn::make('debt_today')
                    ->label('Dette du jour')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' FCFA')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('repaid_today')
                    ->label('Remboursement du jour')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' FCFA')
                    ->color('success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Dette non remboursée')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' FCFA')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('last_activity_today_at')
                    ->label('Dernière activité du jour')
                    ->getStateUsing(fn (Agent $record) => collect([
                        $record->last_debt_today_at,
                        $record->last_repayment_today_at,
                    ])->filter()->max())
                    ->formatStateUsing(fn ($state) => $state
                        ? Carbon::parse($state)->translatedFormat('d/m/Y').' '.Carbon::parse($state)->format('H:i')
                        : '—'),

                Tables\Columns\TextColumn::make('debt_status')
                    ->label('Statut')
                    ->badge()
                    ->getStateUsing(fn (Agent $record) => static::statusLabel($record))
                    ->color(fn (Agent $record) => match (static::statusLabel($record)) {
                        'Soldée' => 'success',
                        'En cours' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('current_debt', 'desc')
            ->actions([
                Tables\Actions\Action::make('encaisser')
                    ->label('Encaisser')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->url(fn (Agent $record) => "/master/remboursements/create?agent_id={$record->id}")
                    ->visible(fn (Agent $record) => $record->current_debt > 0 && Auth::user()?->can('create_remboursements')),

                Tables\Actions\Action::make('details')
                    ->label('Détails')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Agent $record) => AgentDebtDetailPage::getUrl(['agent' => $record->id])),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->poll('30s')
            ->emptyStateHeading('Aucune dette active')
            ->emptyStateDescription('Tous les agents sont à jour.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function statusLabel(Agent $agent): string
    {
        if ((float) $agent->current_debt <= 0) {
            return 'Soldée';
        }

        return 'En cours';
    }
}
