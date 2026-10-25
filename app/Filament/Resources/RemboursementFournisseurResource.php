<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RemboursementFournisseurResource\Pages;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\RemboursementFournisseur;
use App\Services\FournisseurDebtService;
use App\Services\ShortReferenceGenerator;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

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

                                $s = app(FournisseurDebtService::class)->situation($fournisseur);
                                $color = match ($s['statut']) {
                                    FournisseurDebtService::STATUT_DETTE => '#dc2626',
                                    FournisseurDebtService::STATUT_DECOUVERT => '#16a34a',
                                    default => '#6b7280',
                                };
                                $money = fn (float $n) => number_format($n, 0, ',', ' ').' FCFA';

                                return new HtmlString(
                                    '<div style="display:flex; flex-direction:column; gap:2px;">'
                                    .'<span>Dette totale : <strong>'.$money($s['total_credit']).'</strong></span>'
                                    .'<span>Déjà remboursé : <strong style="color:#16a34a;">'.$money($s['total_rembourse']).'</strong></span>'
                                    .'<span>Solde net : <strong style="color:'.$color.';">'.$money($s['solde']).'</strong> ('.e($s['statut_label']).')</span>'
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
                            ->helperText(function (Get $get) {
                                $fournisseur = $get('fournisseur_id') ? Fournisseur::find($get('fournisseur_id')) : null;
                                if (! $fournisseur) {
                                    return 'Sélectionnez d\'abord un fournisseur';
                                }

                                $solde = app(FournisseurDebtService::class)->detteRestante($fournisseur);
                                $apres = $solde - Money::toFloat($get('amount'));
                                $money = fn (float $n) => number_format($n, 0, ',', ' ').' FCFA';

                                if (Money::toFloat($get('amount')) <= 0) {
                                    return 'Solde actuel : '.$money($solde).'. Un montant supérieur à la dette est enregistré comme découvert (avance).';
                                }

                                return match (FournisseurDebtService::statutPour($apres)) {
                                    FournisseurDebtService::STATUT_DECOUVERT => 'Après ce paiement : découvert de '.$money($apres).' (avance, compensée par les prochains approvisionnements à crédit).',
                                    FournisseurDebtService::STATUT_DETTE => 'Après ce paiement : reste à payer '.$money($apres).'.',
                                    default => 'Après ce paiement : compte soldé.',
                                };
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
                            ->default(fn () => app(ShortReferenceGenerator::class)->generate('R', 'remboursements_fournisseurs'))
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fournisseur', 'cancelledBy:id,name']))
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
                    ->color(fn (RemboursementFournisseur $record) => $record->isCancelled() ? 'gray' : 'success')
                    ->extraAttributes(fn (RemboursementFournisseur $record) => $record->isCancelled() ? ['style' => 'text-decoration: line-through'] : [])
                    ->weight('bold')
                    ->icon('heroicon-m-arrow-trending-down')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('statut')
                    ->label('Statut')
                    ->badge()
                    ->getStateUsing(fn (RemboursementFournisseur $record) => $record->isCancelled() ? 'Annulé' : 'Validé')
                    ->color(fn (string $state) => $state === 'Annulé' ? 'gray' : 'success')
                    ->description(fn (RemboursementFournisseur $record) => $record->isCancelled()
                        ? 'Le '.$record->cancelled_at->format('d/m/Y H:i').($record->cancelledBy ? ' par '.$record->cancelledBy->name : '').' — '.$record->cancellation_reason
                        : null)
                    ->wrap(),

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
                Tables\Filters\SelectFilter::make('statut')
                    ->label('Statut')
                    ->options(['valide' => 'Validés', 'annule' => 'Annulés'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'valide' => $query->whereNull('cancelled_at'),
                        'annule' => $query->whereNotNull('cancelled_at'),
                        default => $query,
                    }),

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
                        ->label('Voir les détails'),
                    Tables\Actions\EditAction::make()
                        ->label('Modifier'),
                    self::cancelAction(),
                ])->tooltip('Actions'),
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

    /**
     * « Annuler le remboursement » : confirmation avec le récapitulatif de l'opération et un motif obligatoire, puis
     * FournisseurDebtService::cancel() (atomique, audité, jamais deux fois). Visible seulement si la policy l'autorise.
     */
    public static function cancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel')
            ->label('Annuler le remboursement')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (RemboursementFournisseur $record) => auth()->user()?->can('cancel', $record) ?? false)
            ->modalHeading('Annuler ce remboursement ?')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalDescription(fn (RemboursementFournisseur $record) => new HtmlString(
                'Remboursement <strong>'.e($record->reference).'</strong> de <strong>'.number_format((float) $record->amount, 0, ',', ' ').' FCFA</strong>'
                .' versé à <strong>'.e($record->fournisseur?->name ?? '—').'</strong> le '.$record->payment_date?->format('d/m/Y à H:i').'.<br>'
                .'L\'opération restera dans l\'historique avec le statut « Annulé » et ne comptera plus dans le solde du fournisseur.'
            ))
            ->modalSubmitActionLabel('Confirmer l\'annulation')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Motif de l\'annulation')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (RemboursementFournisseur $record, array $data, Tables\Actions\Action $action) {
                abort_unless(auth()->user()?->can('cancel', $record), 403);

                try {
                    app(FournisseurDebtService::class)->cancel($record, $data['reason'], auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Annulation impossible')->body(collect($e->errors())->flatten()->implode(' '))->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Remboursement annulé')->body('Le solde du fournisseur a été recalculé.')->send();
            });
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
