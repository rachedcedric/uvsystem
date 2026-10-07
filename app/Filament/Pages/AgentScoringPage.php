<?php

namespace App\Filament\Pages;

use App\Models\Agent;
use App\Services\AnalyticsService;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class AgentScoringPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.agent-scoring';

    protected static ?string $navigationLabel = 'Scoring Agents';

    protected static ?string $title = '🏆 Scoring & Performance des Agents';

    protected static ?string $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 10;

    public ?int $selectedAgentId = null;

    public function mount(): void
    {
        app(\App\Services\Subscriptions\SubscriptionEntitlements::class)->abortUnlessFeature('scoring_agents');
    }

    private ?Collection $scoringData = null;

    protected function getScoring(): Collection
    {
        if ($this->scoringData === null) {
            $this->scoringData = app(AnalyticsService::class)->getAgentScoring();
        }

        return $this->scoringData;
    }

    public function table(Table $table): Table
    {
        $scoring = $this->getScoring();

        return $table
            ->query(Agent::query()->whereIn('id', $scoring->pluck('id')))
            ->defaultSort(fn ($query) => $query->orderByRaw('FIELD(id, '.$scoring->pluck('id')->implode(',').')'))
            ->columns([
                Tables\Columns\TextColumn::make('rank')
                    ->label('#')
                    ->getStateUsing(function ($record) use ($scoring) {
                        $index = $scoring->search(fn ($s) => $s['id'] === $record->id);

                        return $index !== false ? $index + 1 : '-';
                    })
                    ->badge()
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'warning',
                        2 => 'gray',
                        3 => 'danger',
                        default => 'gray',
                    })
                    ->width('50px')
                    ->alignCenter()
                    ->sortable(false),

                Tables\Columns\TextColumn::make('name')
                    ->label('Agent')
                    ->searchable()
                    ->weight(FontWeight::Bold)
                    ->icon('heroicon-o-user'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->copyable()
                    ->icon('heroicon-o-phone'),

                Tables\Columns\TextColumn::make('score')
                    ->label('Score Global')
                    ->getStateUsing(function ($record) use ($scoring) {
                        $s = $scoring->firstWhere('id', $record->id);

                        return $s ? $s['score'] : 0;
                    })
                    ->formatStateUsing(fn ($state) => "{$state}/100")
                    ->badge()
                    ->size('lg')
                    ->color(fn ($state) => match (true) {
                        $state >= 80 => 'success',
                        $state >= 60 => 'info',
                        $state >= 40 => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('grade')
                    ->label('Grade')
                    ->getStateUsing(function ($record) use ($scoring) {
                        $s = $scoring->firstWhere('id', $record->id);

                        return $s ? $s['grade'] : 'E';
                    })
                    ->badge()
                    ->size('lg')
                    ->color(fn ($state) => match ($state) {
                        'A+' => 'success',
                        'A' => 'success',
                        'B+' => 'info',
                        'B' => 'info',
                        'C+' => 'warning',
                        'C' => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\ViewColumn::make('details')
                    ->label('Détails du Score')
                    ->view('filament.tables.columns.score-breakdown')
                    ->getStateUsing(function ($record) use ($scoring) {
                        return $scoring->firstWhere('id', $record->id);
                    }),

                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Dette')
                    ->money('XOF')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('recommendations')
                    ->label('Recommandations')
                    ->getStateUsing(function ($record) use ($scoring) {
                        $s = $scoring->firstWhere('id', $record->id);
                        if (! $s || empty($s['recommendations'])) {
                            return '✅ Aucune';
                        }

                        return count($s['recommendations']).' action(s)';
                    })
                    ->badge()
                    ->color(fn ($state) => $state === '✅ Aucune' ? 'success' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('viewDetails')
                    ->label('Voir détails')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->modalHeading(fn ($record) => "Scoring de {$record->name}")
                    ->modalContent(function ($record) use ($scoring) {
                        $s = $scoring->firstWhere('id', $record->id);

                        return view('filament.modals.agent-scoring-details', [
                            'agent' => $record,
                            'scoring' => $s,
                        ]);
                    })
                    ->modalWidth('2xl'),

                Tables\Actions\Action::make('viewAgent')
                    ->label('Fiche agent')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record) => "/master/agents/{$record->id}"),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('grade')
                    ->label('Grade')
                    ->options([
                        'excellent' => '🟢 A+ & A (Excellent)',
                        'good' => '🔵 B+ & B (Bon)',
                        'average' => '🟡 C+ & C (Moyen)',
                        'poor' => '🔴 D & E (Faible)',
                    ])
                    ->query(function ($query, array $data) use ($scoring) {
                        if (! $data['value']) {
                            return $query;
                        }

                        $grades = match ($data['value']) {
                            'excellent' => ['A+', 'A'],
                            'good' => ['B+', 'B'],
                            'average' => ['C+', 'C'],
                            'poor' => ['D', 'E'],
                            default => [],
                        };

                        $agentIds = $scoring->filter(fn ($s) => in_array($s['grade'], $grades))->pluck('id');

                        return $query->whereIn('id', $agentIds);
                    }),
            ])
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\ScoringOverviewWidget::class,
        ];
    }
}
