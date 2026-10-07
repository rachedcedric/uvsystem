<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Services\AnalyticsService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

class AgentScoringWidget extends BaseWidget
{
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '🏆 Scoring des Agents';

    protected static ?string $pollingInterval = '60s';

    // Ne pas afficher sur le dashboard principal - trop lourd
    protected static bool $isDiscovered = false;

    private ?Collection $scoringData = null;

    protected function getScoring(): Collection
    {
        if ($this->scoringData === null) {
            $this->scoringData = app(AnalyticsService::class)->getAgentScoring();
        }

        return $this->scoringData;
    }

    protected function getAgentScore(int $agentId): ?array
    {
        return $this->getScoring()->firstWhere('id', $agentId);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                // Utiliser la récupération des agents pour l'utiliser en closure lors du tri en mémoire
                return Agent::query()->whereIn('id', $this->getScoring()->pluck('id'));
            })
            ->columns([
                Tables\Columns\TextColumn::make('rank')
                    ->label('#')
                    ->getStateUsing(function ($record) {
                        $scoring = $this->getScoring();
                        $index = $scoring->search(fn ($s) => $s['id'] === $record->id);

                        return $index !== false ? $index + 1 : '-';
                    })
                    ->badge()
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'warning', // Gold
                        2 => 'gray', // Silver
                        3 => 'danger', // Bronze (utilisé comme third ici pour l'exemple visuel)
                        default => 'gray',
                    })
                    ->width('50px')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Agent')
                    ->searchable()
                    ->weight('bold')
                    ->icon('heroicon-o-user'),

                Tables\Columns\TextColumn::make('score')
                    ->label('Score Global')
                    ->getStateUsing(fn ($record) => $this->getAgentScore($record->id)['score'] ?? 0
                    )
                    ->formatStateUsing(fn ($state) => "{$state}/100")
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 80 => 'success',
                        $state >= 60 => 'info',
                        $state >= 40 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderByRaw('FIELD(id, '.$this->getScoring()->pluck('id')->implode(',').') '.($direction === 'asc' ? 'DESC' : 'ASC'))
                    ),

                Tables\Columns\TextColumn::make('grade')
                    ->label('Grade')
                    ->getStateUsing(fn ($record) => $this->getAgentScore($record->id)['grade'] ?? 'E'
                    )
                    ->badge()
                    ->size('lg')
                    ->color(fn ($state) => match ($state) {
                        'A+', 'A' => 'success',
                        'B+', 'B' => 'info',
                        'C+', 'C' => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('volume')
                    ->label('📦 Volume')
                    ->getStateUsing(fn ($record) => $this->getAgentScore($record->id)['details']['volume'] ?? 0
                    )
                    ->formatStateUsing(fn ($state) => "{$state}/25")
                    ->color(fn ($state) => $state >= 15 ? 'success' : ($state >= 10 ? 'warning' : 'danger')),

                Tables\Columns\TextColumn::make('regularite')
                    ->label('📅 Régularité')
                    ->getStateUsing(fn ($record) => $this->getAgentScore($record->id)['details']['regularite'] ?? 0
                    )
                    ->formatStateUsing(fn ($state) => "{$state}/20")
                    ->color(fn ($state) => $state >= 12 ? 'success' : ($state >= 8 ? 'warning' : 'danger')),

                Tables\Columns\TextColumn::make('paiement')
                    ->label('💰 Paiement')
                    ->getStateUsing(fn ($record) => $this->getAgentScore($record->id)['details']['paiement'] ?? 0
                    )
                    ->formatStateUsing(fn ($state) => "{$state}/30")
                    ->color(fn ($state) => $state >= 20 ? 'success' : ($state >= 12 ? 'warning' : 'danger')),

                Tables\Columns\TextColumn::make('dette')
                    ->label('📊 Dette')
                    ->getStateUsing(fn ($record) => $this->getAgentScore($record->id)['details']['dette'] ?? 0
                    )
                    ->formatStateUsing(fn ($state) => "{$state}/25")
                    ->color(fn ($state) => $state >= 15 ? 'success' : ($state >= 10 ? 'warning' : 'danger')),

                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Dette Actuelle')
                    ->money('XOF')
                    ->color('danger'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Détails')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->size('sm')
                    ->url(fn ($record) => "/master/agents/{$record->id}"),
            ])
            ->striped()
            ->paginated(false)
            ->emptyStateHeading('Aucun agent actif')
            ->emptyStateDescription('Créez des agents pour voir leur scoring.')
            ->emptyStateIcon('heroicon-o-user-group')
            ->defaultSort(fn ($query) => $query->orderByRaw('FIELD(id, '.$this->getScoring()->pluck('id')->implode(',').')'));
    }
}
