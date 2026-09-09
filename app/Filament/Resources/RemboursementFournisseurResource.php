<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RemboursementFournisseurResource\Pages;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\RemboursementFournisseur;
use App\Services\FournisseurDebtService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Remboursements versés aux fournisseurs "Agent Premium" (qui approvisionnent
 * à crédit). Miroir de RemboursementResource (paiement d'un agent), mêmes
 * modes de paiement déjà enregistrés (PaymentMethod) — jamais une seconde
 * liste inventée. Calcul de dette délégué à FournisseurDebtService.
 */
class RemboursementFournisseurResource extends Resource
{
    protected static ?string $model = RemboursementFournisseur::class;

    // Filament dérive par défaut "remboursement-fournisseurs" (pluriel du
    // seul dernier mot) : forcé ici pour un slug grammaticalement correct.
    protected static ?string $slug = 'remboursements-fournisseurs';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Gestion Approvisionnement';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Remboursements Fournisseurs';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'Remboursement fournisseur';

    protected static ?string $pluralModelLabel = 'Remboursements Fournisseurs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Remboursement fournisseur')
                    ->description('Enregistrer un paiement versé à un fournisseur à crédit')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\Select::make('fournisseur_id')
                            ->label('Fournisseur')
                            ->relationship('fournisseur', 'name', fn (Builder $query) => $query->creditFournisseurs()->where('is_active', true))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('Seuls les fournisseurs "Agent Premium" actifs sont affichés (approvisionnement à crédit)'),

                        Forms\Components\Placeholder::make('_fournisseur_debt_display')
                            ->label('💰 Situation de la dette')
                            ->content(function (Get $get): HtmlString {
                                $fournisseurId = $get('fournisseur_id');
                                if (! $fournisseurId) {
                                    return new HtmlString('<span style="color: #6b7280;">-</span>');
                                }
                                $fournisseur = Fournisseur::find($fournisseurId);
                                if (! $fournisseur) {
                                    return new HtmlString('<span style="color: #6b7280;">-</span>');
                                }

                                $totaux = app(FournisseurDebtService::class)->soldeTotals($fournisseur);
                                $reste = $totaux['distribue'] - $totaux['rembourse'];
                                $resteColor = $reste > 0 ? '#dc2626' : '#22c55e';

                                return new HtmlString(
                                    '<div style="display:flex; flex-direction:column; gap:2px;">'
                                    .'<span>Dette totale : <strong>'.number_format($totaux['distribue'], 0, ',', ' ').' FCFA</strong></span>'
                                    .'<span>Déjà remboursé : <strong style="color:#22c55e;">'.number_format($totaux['rembourse'], 0, ',', ' ').' FCFA</strong></span>'
                                    .'<span>Reste à payer : <strong style="color:'.$resteColor.';">'.number_format($reste, 0, ',', ' ').' FCFA</strong></span>'
                                    .'</div>'
                                );
                            })
                            ->visible(fn (Get $get) => $get('fournisseur_id') !== null),

                        Forms\Components\TextInput::make('amount')
                            ->label('Montant du remboursement')
                            ->required()
                            ->autocomplete(false)
                            ->money(min: 1)
                            ->live(debounce: 500)
                            ->rules([
                                fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $fournisseurId = $get('fournisseur_id');
                                    if (! $fournisseurId) {
                                        return;
                                    }

                                    $fournisseur = Fournisseur::find($fournisseurId);
                                    if (! $fournisseur) {
                                        return;
                                    }

                                    $reste = app(FournisseurDebtService::class)->detteRestante($fournisseur);

                                    if (\App\Support\Money::toFloat($value) > $reste) {
                                        $fail('Le montant du remboursement ne peut pas dépasser le reste à payer.');
                                    }
                                },
                            ])
                            ->helperText(function (Get $get) {
                                if (! $get('fournisseur_id')) {
                                    return 'Sélectionnez d\'abord un fournisseur';
                                }
                                $fournisseur = Fournisseur::find($get('fournisseur_id'));
                                $reste = $fournisseur ? app(FournisseurDebtService::class)->detteRestante($fournisseur) : 0.0;

                                return '⚠️ Maximum: '.number_format($reste, 0, ',', ' ').' FCFA';
                            }),

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
                            ->default(fn () => app(\App\Services\ShortReferenceGenerator::class)->generate('R', 'remboursements_fournisseurs'))
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

                Tables\Columns\TextColumn::make('fournisseur.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-building-storefront')
                    ->description(fn (RemboursementFournisseur $record) => $record->fournisseur?->contact ?? ''),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant remboursé')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable()
                    ->color('success')
                    ->weight('bold')
                    ->icon('heroicon-m-arrow-trending-down')
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
                    ->icon('heroicon-m-calendar'),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(30)
                    ->tooltip(fn (RemboursementFournisseur $record) => $record->notes)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->striped()
            ->defaultSort('payment_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Mode de paiement')
                    ->options(fn () => PaymentMethod::getActiveOptions()),

                Tables\Filters\SelectFilter::make('fournisseur')
                    ->relationship('fournisseur', 'name', fn (Builder $query) => $query->creditFournisseurs())
                    ->label('Fournisseur')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('today')
                    ->label('Aujourd\'hui')
                    ->query(fn (Builder $query): Builder => $query->whereDate('payment_date', today()))
                    ->toggle(),

                Tables\Filters\Filter::make('this_month')
                    ->label('Ce mois')
                    ->query(fn (Builder $query): Builder => $query->whereMonth('payment_date', now()->month)->whereYear('payment_date', now()->year))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->label('Voir'),
                    Tables\Actions\EditAction::make()
                        ->label('Modifier'),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Supprimer la sélection'),
                ]),
            ])
            ->emptyStateHeading('Aucun remboursement fournisseur enregistré')
            ->emptyStateDescription('Créez un nouveau remboursement pour commencer le suivi des paiements.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nouveau remboursement'),
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
            'index' => Pages\ListRemboursementFournisseurs::route('/'),
            'create' => Pages\CreateRemboursementFournisseur::route('/create'),
            'edit' => Pages\EditRemboursementFournisseur::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'fournisseur.name'];
    }
}
