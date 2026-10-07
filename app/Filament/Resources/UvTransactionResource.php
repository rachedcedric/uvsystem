<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UvTransactionResource\Pages;
use App\Models\Agent;
use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class UvTransactionResource extends Resource
{
    protected static ?string $model = UvTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'Vente UV';

    protected static ?string $pluralModelLabel = 'Ventes UV';

    protected static ?string $navigationLabel = 'Ventes UV';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Transaction UV')
                    ->schema([
                        Forms\Components\Select::make('agent_id')
                            ->relationship('agent', 'name', fn (Builder $query) => $query->where('status', 'active'))
                            ->label('Agent')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                if ($state) {
                                    $agent = Agent::with('solde')->find($state);
                                    if ($agent) {
                                        $service = new UvDistributionService;
                                        $credit = $service->getAvailableCredit($agent);
                                        $set('_available_credit', $credit === PHP_FLOAT_MAX ? 'Illimité' : number_format($credit, 0, ',', ' ').' FCFA');
                                        $set('_current_debt', number_format($service->getCurrentDebt($agent), 0, ',', ' ').' FCFA');
                                    }
                                }
                            }),
                        Forms\Components\Placeholder::make('_agent_info')
                            ->label('💰 Situation financière de l\'agent')
                            ->content(function (Get $get): HtmlString {
                                $agentId = $get('agent_id');
                                if (! $agentId) {
                                    return new HtmlString('<span style="color: #6b7280;">Sélectionnez un agent</span>');
                                }

                                $agent = Agent::find($agentId);
                                if (! $agent) {
                                    return new HtmlString('<span style="color: #6b7280;">Agent introuvable</span>');
                                }

                                $service = new UvDistributionService;
                                $debt = $agent->current_debt ?? 0;
                                $credit = $service->getAvailableCredit($agent);
                                $creditText = $credit === PHP_FLOAT_MAX ? 'Illimité' : number_format($credit, 0, ',', ' ').' FCFA';

                                $debtColor = $debt > 0 ? '#dc2626' : '#22c55e';
                                $creditColor = $credit > 0 || $credit === PHP_FLOAT_MAX ? '#22c55e' : '#f59e0b';

                                return new HtmlString(
                                    "<div style='display: flex; gap: 20px;'>".
                                    "<span>📊 Dette: <strong style='color: {$debtColor};'>".number_format($debt, 0, ',', ' ').' FCFA</strong></span>'.
                                    "<span>💳 Crédit disponible: <strong style='color: {$creditColor};'>{$creditText}</strong></span>".
                                    '</div>'
                                );
                            })
                            ->visible(fn (Get $get) => $get('agent_id') !== null),
                        Forms\Components\TextInput::make('amount')
                            ->label('Montant (FCFA)')
                            ->required()
                            ->autocomplete(false)
                            ->money(min: 1)
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                if ($state && $get('transaction_type') === 'distribution') {
                                    $masterService = new MasterSoldeService;
                                    $available = $masterService->getAvailableBalance();
                                    $set('_master_solde', number_format($available, 0, ',', ' ').' FCFA disponible');
                                }
                            })
                            ->rules([
                                fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $transactionType = $get('transaction_type');

                                    if ($transactionType !== 'distribution') {
                                        return;
                                    }

                                    // Vérifier le solde Master (Money::toFloat car $value
                                    // est encore la saisie formatée avec espaces à ce stade)
                                    $amount = \App\Support\Money::toFloat($value);
                                    $masterService = new MasterSoldeService;
                                    if (! $masterService->hasSufficientBalance($amount)) {
                                        $available = $masterService->getAvailableBalance();
                                        $fail('Solde insuffisant. Disponible: '.number_format($available, 0, ',', ' ')." FCFA. Effectuez d'abord un approvisionnement.");

                                        return;
                                    }

                                    // Vérifier le crédit agent
                                    $agentId = $get('agent_id');
                                    if (! $agentId) {
                                        return;
                                    }

                                    $agent = Agent::with('solde')->find($agentId);
                                    if (! $agent) {
                                        return;
                                    }

                                    $service = new UvDistributionService;
                                    if (! $service->canDistribute($agent, $amount)) {
                                        $availableCredit = $service->getAvailableCredit($agent);
                                        $formattedCredit = $availableCredit === PHP_FLOAT_MAX ? 'Illimité' : number_format($availableCredit, 0, ',', ' ').' FCFA';
                                        $fail("Ce montant dépasse le crédit disponible ({$formattedCredit}).");
                                    }
                                },
                            ]),

                        Forms\Components\Placeholder::make('_master_solde')
                            ->label('Solde Master')
                            ->content(function (Get $get): string {
                                $masterService = new MasterSoldeService;
                                $available = $masterService->getAvailableBalance();
                                $color = $available < 100000 ? '🔴' : ($available < 500000 ? '🟠' : '🟢');

                                return "{$color} ".number_format($available, 0, ',', ' ').' FCFA disponible';
                            })
                            ->visible(fn (Get $get) => $get('transaction_type') === 'distribution'),

                        Forms\Components\Radio::make('transaction_type')
                            ->label('Type de transaction')
                            // "Retour" n'est plus proposé à la création (le
                            // service UvDistributionService::processReturn()
                            // et tout le calcul de dette/solde associé
                            // restent intacts) : seule l'option ne s'affiche
                            // plus dans /master/uv-transactions/create. Une
                            // transaction "retour" historique reste éditable
                            // normalement, l'option réapparaît alors ici.
                            ->options(fn (?UvTransaction $record): array => $record?->transaction_type === 'retour'
                                ? [
                                    'distribution' => '📤 Distribution (Vente UV à l\'agent)',
                                    'retour' => '📥 Retour (Reprise UV de l\'agent)',
                                ]
                                : [
                                    'distribution' => '📤 Distribution (Vente UV à l\'agent)',
                                ])
                            ->descriptions([
                                'distribution' => 'L\'agent reçoit des UV et sa dette augmente',
                                'retour' => 'L\'agent retourne des UV et sa dette diminue',
                            ])
                            ->default('distribution')
                            ->required()
                            ->live()
                            ->inline(false)
                            ->columnSpanFull(),

                        Forms\Components\DateTimePicker::make('transaction_date')
                            ->label('Date de transaction')
                            ->required()
                            ->default(now())
                            ->maxDate(now())
                            ->seconds(false),
                    ])
                    ->columns(2),
                Forms\Components\Hidden::make('_available_credit'),
                Forms\Components\Hidden::make('_current_debt'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Détails de la transaction')
                    ->schema([
                        Infolists\Components\TextEntry::make('reference')
                            ->label('Référence')
                            ->icon('heroicon-o-hashtag'),

                        Infolists\Components\TextEntry::make('agent.name')
                            ->label('Agent'),

                        Infolists\Components\TextEntry::make('transaction_type')
                            ->label('Type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'distribution' => 'Distribution',
                                'retour' => 'Retour',
                                default => $state,
                            })
                            ->color(fn (string $state): string => $state === 'distribution' ? 'primary' : 'success'),

                        Infolists\Components\TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'completed' => 'Terminée',
                                'cancelled' => 'Annulée',
                                default => $state,
                            })
                            ->color(fn (string $state): string => $state === 'cancelled' ? 'danger' : 'success'),

                        Infolists\Components\TextEntry::make('amount')
                            ->label('Montant')
                            ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                            ->weight('bold'),

                        Infolists\Components\TextEntry::make('solde_uv_avant')
                            ->label('Solde Avant')
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 0, ',', ' ').' FCFA' : 'Non disponible'),

                        Infolists\Components\TextEntry::make('solde_uv_apres')
                            ->label('Solde Après')
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 0, ',', ' ').' FCFA' : 'Non disponible')
                            ->weight('bold')
                            ->color('primary'),

                        Infolists\Components\TextEntry::make('transaction_date')
                            ->label('Date')
                            ->date('d/m/Y'),

                        Infolists\Components\TextEntry::make('transaction_date')
                            ->label('Heure')
                            ->time('H:i:s'),

                        Infolists\Components\TextEntry::make('fournisseur.name')
                            ->label('Fournisseur')
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable()
                    ->weight('bold')
                    ->color(fn (UvTransaction $record) => $record->transaction_type === 'distribution' ? 'danger' : 'success')
                    ->icon(fn (UvTransaction $record) => $record->transaction_type === 'distribution'
                        ? 'heroicon-m-arrow-up-right'
                        : 'heroicon-m-arrow-down-left')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('solde_uv_apres')
                    ->label('Solde Après')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 0, ',', ' ').' FCFA' : 'Non disponible')
                    ->weight('bold')
                    ->color(fn ($state) => $state !== null ? 'primary' : 'warning')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('agent.current_debt')
                    ->label('Dette Agent')
                    ->formatStateUsing(fn ($state) => number_format($state ?? 0, 0, ',', ' ').' FCFA')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->icon(fn ($state) => $state > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'distribution' => '📤 Distribution',
                        'retour' => '📥 Retour',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'distribution' => 'primary',
                        'retour' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('fournisseur.name')
                    ->label('Fournisseur')
                    ->placeholder('—')
                    ->icon('heroicon-m-building-storefront')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Date et heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->icon('heroicon-m-calendar'),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(25)
                    ->tooltip(fn (UvTransaction $record) => $record->notes)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->striped()
            ->defaultSort('transaction_date', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->filters([
                Tables\Filters\SelectFilter::make('agent_id')
                    ->relationship('agent', 'name')
                    ->label('Agent')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('transaction_type')
                    ->label('Type')
                    ->options([
                        'distribution' => '📤 Distribution',
                        'retour' => '📥 Retour',
                    ]),

                Tables\Filters\Filter::make('today')
                    ->label('Aujourd\'hui')
                    ->query(fn (Builder $query): Builder => $query->whereDate('transaction_date', today()))
                    ->toggle(),

                Tables\Filters\Filter::make('this_week')
                    ->label('Cette semaine')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('transaction_date', [now()->startOfWeek(), now()->endOfWeek()]))
                    ->toggle(),

                Tables\Filters\Filter::make('this_month')
                    ->label('Ce mois')
                    ->query(fn (Builder $query): Builder => $query->whereMonth('transaction_date', now()->month)->whereYear('transaction_date', now()->year))
                    ->toggle(),

                Tables\Filters\Filter::make('amount_range')
                    ->label('Montant')
                    ->form([
                        Forms\Components\TextInput::make('min_amount')
                            ->label('Montant min')
                            ->money(),
                        Forms\Components\TextInput::make('max_amount')
                            ->label('Montant max')
                            ->money(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['min_amount'], fn ($q, $min) => $q->where('amount', '>=', \App\Support\Money::toFloat($min)))
                            ->when($data['max_amount'], fn ($q, $max) => $q->where('amount', '<=', \App\Support\Money::toFloat($max)));
                    }),

                Tables\Filters\Filter::make('transaction_date')
                    ->label('Période')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $query, $date): Builder => $query->whereDate('transaction_date', '>=', $date))
                            ->when($data['until'], fn (Builder $query, $date): Builder => $query->whereDate('transaction_date', '<=', $date));
                    })
                    ->columns(2),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->label('Voir'),
                    Tables\Actions\EditAction::make()
                        ->label('Modifier'),
                    Tables\Actions\Action::make('duplicate')
                        ->label('Dupliquer')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('gray')
                        ->action(function (UvTransaction $record) {
                            $service = new UvDistributionService;

                            try {
                                if ($record->transaction_type === 'retour') {
                                    $new = $service->processReturn($record->agent, (float) $record->amount, now(), $record->notes);
                                } else {
                                    $new = $service->distribute($record->agent, (float) $record->amount, $record->fournisseur, now(), $record->notes);
                                }

                                Notification::make()
                                    ->title('Transaction dupliquée')
                                    ->body('Référence: '.$new->reference)
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Duplication impossible')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Tables\Actions\Action::make('cancel')
                        ->label('Annuler la vente')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Annuler cette vente ?')
                        ->modalDescription('Les UV correspondants seront restitués au Solde UV Disponible. Cette action est réservée aux ventes déjà débitées dont les UV n\'ont finalement pas été distribués.')
                        ->visible(fn (UvTransaction $record) => $record->transaction_type === 'distribution' && $record->status !== 'cancelled')
                        ->action(function (UvTransaction $record) {
                            try {
                                (new UvDistributionService)->cancel($record);

                                Notification::make()
                                    ->title('Vente annulée')
                                    ->body('Le montant a été recrédité au Solde UV Disponible.')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Annulation impossible')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Supprimer'),
                    Tables\Actions\ForceDeleteBulkAction::make()
                        ->label('Supprimer définitivement'),
                    Tables\Actions\RestoreBulkAction::make()
                        ->label('Restaurer'),
                ]),
            ])
            ->emptyStateHeading('Aucune vente UV enregistrée')
            ->emptyStateDescription('Créez une nouvelle vente UV pour commencer.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nouvelle vente UV')
                    ->icon('heroicon-o-plus'),
            ])
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUvTransactions::route('/'),
            'create' => Pages\CreateUvTransaction::route('/create'),
            'edit' => Pages\EditUvTransaction::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['agent', 'fournisseur']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'agent.name'];
    }
}
