<?php

namespace App\Filament\Pages;

use App\Models\Agent;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
    // forcé ici pour correspondre exactement à /admin/dettes.
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
     * @return array{count: int, totalInitial: float, totalRembourse: float, totalReste: float}
     */
    public function getSummary(): array
    {
        $agents = static::agentsEndettesQuery()->with('solde')->get();

        return [
            'count' => $agents->count(),
            'totalInitial' => (float) $agents->sum(fn (Agent $a) => (float) ($a->solde?->total_uv_distribues ?? $a->current_debt)),
            'totalRembourse' => (float) $agents->sum(fn (Agent $a) => (float) ($a->solde?->total_rembourse ?? 0)),
            'totalReste' => (float) $agents->sum('current_debt'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // Base large (tout agent actif ayant déjà eu une dette un
                // jour), le filtre "Statut" ci-dessous restreint par défaut
                // aux dettes actives — cf. exigence "par défaut, privilégier
                // les dettes actives" sans empêcher de consulter le reste.
                Agent::query()
                    ->active()
                    ->whereHas('transactions', fn (Builder $q) => $q->where('transaction_type', 'distribution'))
                    ->withMax(['transactions as last_debt_at' => fn (Builder $q) => $q->where('transaction_type', 'distribution')], 'transaction_date')
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

                Tables\Columns\TextColumn::make('solde.total_uv_distribues')
                    ->label('Dette')
                    ->formatStateUsing(fn ($state, Agent $record) => number_format((float) ($state ?? $record->current_debt), 0, ',', ' ').' FCFA')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('solde.total_rembourse')
                    ->label('Remboursé')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' FCFA')
                    ->color('success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Reste à payer')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' FCFA')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('last_debt_at')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_debt_at')
                    ->label('Heure')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('debt_status')
                    ->label('Statut')
                    ->badge()
                    ->getStateUsing(fn (Agent $record) => static::statusLabel($record))
                    ->color(fn (Agent $record) => match (static::statusLabel($record)) {
                        'Soldée' => 'success',
                        'Partiellement remboursée' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('last_debt_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('debt_status')
                    ->label('Statut')
                    ->default('active')
                    ->options([
                        'active' => 'Actives (en cours + partielles)',
                        'en_cours' => 'En cours',
                        'partiel' => 'Partiellement remboursées',
                        'soldee' => 'Soldées',
                        'all' => 'Toutes',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? 'active') {
                            'en_cours' => $query->where('current_debt', '>', 0)
                                ->whereHas('solde', fn (Builder $q) => $q->where('total_rembourse', '<=', 0)),
                            'partiel' => $query->where('current_debt', '>', 0)
                                ->whereHas('solde', fn (Builder $q) => $q->where('total_rembourse', '>', 0)),
                            'soldee' => $query->where('current_debt', '<=', 0),
                            'all' => $query,
                            default => $query->where('current_debt', '>', 0),
                        };
                    }),

                Tables\Filters\Filter::make('last_debt_at')
                    ->label('Date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Du'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date) => $q->whereHas('transactions', fn (Builder $t) => $t->where('transaction_type', 'distribution')->whereDate('transaction_date', '>=', $date))
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, $date) => $q->whereHas('transactions', fn (Builder $t) => $t->where('transaction_type', 'distribution')->whereDate('transaction_date', '<=', $date))
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('encaisser')
                    ->label('Encaisser')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->url(fn (Agent $record) => "/admin/remboursements/create?agent_id={$record->id}")
                    ->visible(fn (Agent $record) => $record->current_debt > 0 && Auth::user()?->can('create_remboursements')),
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

        return (float) ($agent->solde?->total_rembourse ?? 0) > 0
            ? 'Partiellement remboursée'
            : 'En cours';
    }
}
