<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RemboursementResource\Pages;
use App\Models\Agent;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Services\ShortReferenceGenerator;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class RemboursementResource extends Resource
{
    protected static ?string $model = Remboursement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'Remboursement';

    protected static ?string $pluralModelLabel = 'Remboursements';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations du remboursement')
                    ->description('Enregistrer un paiement d\'un agent')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\Select::make('agent_id')
                            ->label('Agent')
                            ->relationship('agent', 'name', fn (Builder $query) => $query->where('status', 'active')
                            )
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->name.' - Dette: '.number_format($record->current_debt ?? 0, 0, ',', ' ').' FCFA'
                            )
                            ->required()
                            ->searchable(['name', 'phone'])
                            ->preload()
                            ->live()
                            ->helperText('Seuls les agents actifs sont affichés'),

                        Forms\Components\Placeholder::make('_agent_debt_display')
                            ->label('💰 Situation de la dette')
                            ->content(function (Get $get): HtmlString {
                                $agentId = $get('agent_id');
                                if (! $agentId) {
                                    return new HtmlString('<span style="color: #6b7280;">-</span>');
                                }
                                $agent = Agent::with('solde')->find($agentId);
                                if (! $agent) {
                                    return new HtmlString('<span style="color: #6b7280;">-</span>');
                                }

                                // Même source de vérité que la page Dettes et
                                // les rappels automatiques : AgentSolde pour
                                // le cumul, Agent::current_debt pour le reste.
                                $initial = (float) ($agent->solde->total_uv_distribues ?? $agent->current_debt);
                                $rembourse = (float) ($agent->solde->total_rembourse ?? 0);
                                $reste = (float) $agent->current_debt;
                                $resteColor = $reste > 0 ? '#dc2626' : '#22c55e';

                                return new HtmlString(
                                    '<div style="display:flex; flex-direction:column; gap:2px;">'
                                    .'<span>Dette initiale : <strong>'.number_format($initial, 0, ',', ' ').' FCFA</strong></span>'
                                    .'<span>Déjà remboursé : <strong style="color:#22c55e;">'.number_format($rembourse, 0, ',', ' ').' FCFA</strong></span>'
                                    .'<span>Reste à payer : <strong style="color:'.$resteColor.';">'.number_format($reste, 0, ',', ' ').' FCFA</strong></span>'
                                    .'</div>'
                                );
                            })
                            ->visible(fn (Get $get) => $get('agent_id') !== null),

                        Forms\Components\TextInput::make('amount')
                            ->label('Montant du remboursement')
                            ->required()
                            ->autocomplete(false)
                            ->money(min: 1)
                            ->live(debounce: 500)
                            ->rules([
                                fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $agentId = $get('agent_id');
                                    if (! $agentId) {
                                        return;
                                    }

                                    // Toujours recalculé en direct depuis les transactions
                                    // (debtAsOf()) plutôt que lu depuis current_debt (mis en
                                    // cache, peut être temporairement désynchronisé) ou depuis
                                    // un champ caché : un agent présélectionné (bouton
                                    // "Encaisser", ou conservé d'un remboursement au suivant)
                                    // ne déclenche pas afterStateUpdated, donc un champ caché
                                    // resterait à 0 et bloquerait à tort tout montant — y
                                    // compris un remboursement intégral exactement égal au
                                    // reste à payer.
                                    $currentDebt = Agent::find($agentId)?->debtAsOf() ?? 0.0;

                                    if (Money::toFloat($value) > $currentDebt) {
                                        $fail('Le montant du remboursement ne peut pas dépasser le reste à payer.');
                                    }
                                },
                            ])
                            ->helperText(function (Get $get) {
                                if (! $get('agent_id')) {
                                    return 'Sélectionnez d\'abord un agent';
                                }
                                $agent = Agent::find($get('agent_id'));
                                $debt = $agent?->debtAsOf() ?? 0.0;

                                return '⚠️ Maximum: '.number_format($debt, 0, ',', ' ').' FCFA';
                            }),

                        Forms\Components\Placeholder::make('_new_debt')
                            ->label('📊 Nouvelle dette après remboursement')
                            ->content(function (Get $get): HtmlString {
                                $agentId = $get('agent_id');
                                if (! $agentId) {
                                    return new HtmlString('<span style="color: #6b7280;">-</span>');
                                }
                                $agent = Agent::find($agentId);
                                $debt = $agent?->current_debt ?? 0;
                                $amount = Money::toFloat($get('amount'));
                                $newDebt = max(0, $debt - $amount);
                                $color = $newDebt > 0 ? 'color: #f59e0b;' : 'color: #22c55e; font-weight: bold;';
                                $icon = $newDebt == 0 ? '✅' : '📉';

                                return new HtmlString("<span style='{$color}'>{$icon} ".number_format($newDebt, 0, ',', ' ').' FCFA</span>');
                            })
                            ->visible(fn (Get $get) => $get('agent_id') !== null && Money::toFloat($get('amount')) > 0),

                        Forms\Components\Select::make('payment_method')
                            ->label('Méthode de paiement')
                            ->options(fn () => PaymentMethod::getActiveOptions())
                            ->required()
                            ->default('cash')
                            ->native(false),

                        Forms\Components\TextInput::make('reference')
                            ->label('Référence')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->default(fn () => app(ShortReferenceGenerator::class)->generate('R', 'remboursements'))
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\DateTimePicker::make('payment_date')
                            ->label('Date de paiement')
                            ->required()
                            ->default(now())
                            ->maxDate(now())
                            ->seconds(false)
                            ->native(false),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notes')
                            ->placeholder('Informations complémentaires...')
                            ->columnSpanFull()
                            ->rows(2),
                    ])
                    ->columns(2),
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
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-user')
                    ->description(fn (Remboursement $record) => $record->agent?->phone ?? ''),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant remboursé')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable()
                    ->color('success')
                    ->weight('bold')
                    ->icon('heroicon-m-arrow-trending-down')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('agent.current_debt')
                    ->label('Dette restante')
                    ->formatStateUsing(fn ($state) => number_format($state ?? 0, 0, ',', ' ').' FCFA')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->icon(fn ($state) => $state > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(function (string $state): string {
                        $method = PaymentMethod::where('code', $state)->first();

                        return $method ? ($method->icon.' '.$method->name) : $state;
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'cash' => 'success',
                        'mobile_money' => 'info',
                        'bank_transfer' => 'primary',
                        'cheque' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Date paiement')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->icon('heroicon-m-calendar')
                    ->description(fn (Remboursement $record) => $record->payment_date?->diffForHumans() ?? ''),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(30)
                    ->tooltip(fn (Remboursement $record) => $record->notes)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->striped()
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Mode de paiement')
                    ->options(fn () => PaymentMethod::getActiveOptions()),

                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name')
                    ->label('Agent')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('today')
                    ->label('Aujourd\'hui')
                    ->query(fn (Builder $query): Builder => $query->whereDate('payment_date', today()))
                    ->toggle(),

                Tables\Filters\Filter::make('this_week')
                    ->label('Cette semaine')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('payment_date', [now()->startOfWeek(), now()->endOfWeek()]))
                    ->toggle(),

                Tables\Filters\Filter::make('this_month')
                    ->label('Ce mois')
                    ->query(fn (Builder $query): Builder => $query->whereMonth('payment_date', now()->month)->whereYear('payment_date', now()->year))
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
                            ->when($data['min_amount'], fn ($q, $min) => $q->where('amount', '>=', Money::toFloat($min)))
                            ->when($data['max_amount'], fn ($q, $max) => $q->where('amount', '<=', Money::toFloat($max)));
                    }),
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->label('Voir'),
                    Tables\Actions\EditAction::make()
                        ->label('Modifier'),
                    Tables\Actions\Action::make('printReceipt')
                        ->label('Imprimer reçu')
                        ->icon('heroicon-o-printer')
                        ->color('info')
                        ->url(fn (Remboursement $record) => route('remboursement.receipt', $record), shouldOpenInNewTab: true),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Supprimer la sélection'),
                ]),
            ])
            ->emptyStateHeading('Aucun remboursement enregistré')
            ->emptyStateDescription('Créez un nouveau remboursement pour commencer le suivi des paiements.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nouveau remboursement')
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
            'index' => Pages\ListRemboursements::route('/'),
            'create' => Pages\CreateRemboursement::route('/create'),
            'edit' => Pages\EditRemboursement::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereDate('payment_date', today())->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'agent.name', 'agent.phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return "Remboursement {$record->reference}";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Agent' => $record->agent?->name ?? '-',
            'Montant' => number_format($record->amount, 0, ',', ' ').' FCFA',
            'Date' => $record->payment_date?->format('d/m/Y') ?? '-',
        ];
    }
}
