<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommissionResource\Pages;
use App\Models\Commission;
use App\Services\CommissionService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CommissionResource extends Resource
{
    protected static ?string $model = Commission::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Commissions';

    protected static ?string $modelLabel = 'Commission';

    protected static ?string $pluralModelLabel = 'Commissions Globales';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 4;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Commission Globale du Jour')
                    ->icon('heroicon-o-currency-dollar')
                    ->schema([
                        Infolists\Components\TextEntry::make('date_commission')
                            ->label('Date')
                            ->date('d/m/Y')
                            ->icon('heroicon-o-calendar'),
                        Infolists\Components\TextEntry::make('nombre_transactions')
                            ->label('Nombre de transactions')
                            ->icon('heroicon-o-document-text'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Volumes et Montants')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Infolists\Components\TextEntry::make('volume_total_jour')
                            ->label('Volume Total du Jour')
                            ->money('XOF')
                            ->icon('heroicon-o-chart-bar'),
                        Infolists\Components\TextEntry::make('commission_globale')
                            ->label('Commission Globale')
                            ->money('XOF')
                            ->weight('bold')
                            ->color('primary'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Palier Appliqué')
                    ->icon('heroicon-o-chart-bar')
                    ->schema([
                        Infolists\Components\TextEntry::make('seuil_min')
                            ->label('Seuil Minimum')
                            ->money('XOF'),
                        Infolists\Components\TextEntry::make('seuil_max')
                            ->label('Seuil Maximum')
                            ->money('XOF'),
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Notes')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('date_commission')
                    ->label('📅 Date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('volume_total_jour')
                    ->label('📦 Volume Total')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable()
                    ->color('info'),

                Tables\Columns\TextColumn::make('commission_globale')
                    ->label('💰 Commission')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Large),

                Tables\Columns\TextColumn::make('nombre_transactions')
                    ->label('📋 Transactions')
                    ->sortable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('palier_info')
                    ->label('📊 Palier')
                    ->getStateUsing(fn ($record) => number_format($record->seuil_min, 0, ',', ' ').' - '.number_format($record->seuil_max, 0, ',', ' ').' F')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Small)
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->striped()
            ->filters([
                Tables\Filters\Filter::make('date_commission')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('Du')
                            ->default(now()->startOfMonth()),
                        Forms\Components\DatePicker::make('date_to')
                            ->label('Au')
                            ->default(now()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['date_from'], fn ($q, $date) => $q->whereDate('date_commission', '>=', $date))
                            ->when($data['date_to'], fn ($q, $date) => $q->whereDate('date_commission', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['date_from'] ?? null) {
                            $indicators['date_from'] = 'Du '.Carbon::parse($data['date_from'])->format('d/m/Y');
                        }
                        if ($data['date_to'] ?? null) {
                            $indicators['date_to'] = 'Au '.Carbon::parse($data['date_to'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),

                Tables\Filters\Filter::make('today')
                    ->label("🗓️ Aujourd'hui")
                    ->query(fn (Builder $query) => $query->whereDate('date_commission', today()))
                    ->toggle(),

                Tables\Filters\Filter::make('this_week')
                    ->label('�� Cette semaine')
                    ->query(fn (Builder $query) => $query->whereBetween('date_commission', [now()->startOfWeek(), now()->endOfWeek()]))
                    ->toggle(),

                Tables\Filters\Filter::make('this_month')
                    ->label('📆 Ce mois')
                    ->query(fn (Builder $query) => $query->thisMonth())
                    ->toggle(),

                Tables\Filters\Filter::make('high_commissions')
                    ->label('💎 Grosses commissions (>50k)')
                    ->query(fn (Builder $query) => $query->where('commission_globale', '>', 50000))
                    ->toggle(),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->icon('heroicon-o-eye')
                    ->color('info'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('calculer_commission')
                    ->label('Calculer Commission du Jour')
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Calculer la commission du jour')
                    ->modalDescription('Cette action va calculer la commission globale basée sur le volume de ventes de la période J-1 18h à J 18h.')
                    ->action(function () {
                        $service = app(CommissionService::class);
                        $stats = $service->calculerCommissionsPourDate(Carbon::today());

                        if (! empty($stats['erreurs'])) {
                            Notification::make()
                                ->title('Erreur')
                                ->body(implode(', ', $stats['erreurs']))
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($stats['deja_calcule']) {
                            Notification::make()
                                ->title('Déjà calculé')
                                ->body('La commission du '.$stats['date_commission'].' est déjà enregistrée.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Commission calculée')
                            ->body(sprintf(
                                'Volume: %s FCFA → Commission: %s FCFA',
                                number_format($stats['volume_total'], 0, ',', ' '),
                                number_format($stats['commission_globale'], 0, ',', ' ')
                            ))
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('date_commission', 'desc')
            ->emptyStateHeading('Aucune commission')
            ->emptyStateDescription('Les commissions sont calculées automatiquement à 18h chaque jour basées sur le volume de ventes.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissions::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->where('is_global', true); // Uniquement les commissions globales
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::thisMonth()->where('is_global', true)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
