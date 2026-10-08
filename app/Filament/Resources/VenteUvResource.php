<?php

namespace App\Filament\Resources;

use App\Exports\VentesUvExport;
use App\Filament\Resources\VenteUvResource\Pages;
use App\Models\VenteUv;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class VenteUvResource extends Resource
{
    protected static ?string $model = VenteUv::class;

    // Caché du menu - Fonctionnalité pour partenaires externes (non utilisée actuellement)
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Ancienne ressource « partenaires » : sa table (vente_uvs) n'existe plus dans le schéma actuel, ses pages
     * plantaient en 500. Le code est conservé (modèles, exports et observateurs l'importent encore) mais l'accès
     * est fermé : réponse 403 au lieu d'une erreur serveur.
     */
    public static function canAccess(): bool
    {
        return false;
    }

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Ventes Partenaires';

    protected static ?string $modelLabel = 'Vente Partenaire';

    protected static ?string $pluralModelLabel = 'Ventes Partenaires';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations de Vente')
                    ->schema([
                        Forms\Components\Select::make('agent_partenaire_id')
                            ->label('Agent Partenaire')
                            ->relationship('agentPartenaire', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom')
                                    ->required(),
                                Forms\Components\TextInput::make('phone')
                                    ->label('Téléphone')
                                    ->tel()
                                    ->required(),
                                Forms\Components\TextInput::make('email')
                                    ->label('Email')
                                    ->email(),
                            ])
                            ->columnSpan(2),

                        Forms\Components\DateTimePicker::make('date_vente')
                            ->label('Date et heure de vente')
                            ->default(now())
                            ->required()
                            ->maxDate(now())
                            ->seconds(false)
                            ->native(false),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Détails Financiers')
                    ->schema([
                        Forms\Components\TextInput::make('quantity_uv')
                            ->label('Quantité UV')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                $montant = \App\Support\Money::toFloat($get('montant_total'));
                                if ($state > 0 && $montant > 0) {
                                    $prixUnitaire = $montant / $state;
                                    $set('_prix_unitaire_display', number_format($prixUnitaire, 2).' FCFA/UV');
                                }
                            })
                            ->suffix('UV'),

                        Forms\Components\TextInput::make('montant_total')
                            ->label('Montant Total')
                            ->required()
                            ->money(min: 0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                $quantity = $get('quantity_uv');
                                $montant = \App\Support\Money::toFloat($state);
                                if ($quantity > 0 && $montant > 0) {
                                    $prixUnitaire = $montant / $quantity;
                                    $set('_prix_unitaire_display', number_format($prixUnitaire, 2).' FCFA/UV');
                                }
                            }),

                        Forms\Components\Placeholder::make('_prix_unitaire_display')
                            ->label('Prix Unitaire')
                            ->content(fn (Forms\Get $get) => $get('_prix_unitaire_display') ?? 'Sera calculé automatiquement'),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes additionnelles')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
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
                    ->icon('heroicon-o-hashtag'),

                Tables\Columns\TextColumn::make('date_vente')
                    ->label('Date et heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->icon('heroicon-o-calendar'),

                Tables\Columns\TextColumn::make('agentPartenaire.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->description(fn (VenteUv $record) => $record->agentPartenaire->phone ?? '')
                    ->icon('heroicon-o-user-group'),

                Tables\Columns\TextColumn::make('quantity_uv')
                    ->label('Quantité UV')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->suffix(' UV'),

                Tables\Columns\TextColumn::make('montant_total')
                    ->label('Montant Total')
                    ->money('XOF', locale: 'fr')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->money('XOF', locale: 'fr')
                        ->label('Total')),

                Tables\Columns\TextColumn::make('prix_unitaire')
                    ->label('Prix/UV')
                    ->money('XOF', locale: 'fr')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agent_partenaire_id')
                    ->label('Agent')
                    ->relationship('agentPartenaire', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('date_vente')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('date_to')
                            ->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['date_from'], fn ($q, $date) => $q->whereDate('date_vente', '>=', $date))
                            ->when($data['date_to'], fn ($q, $date) => $q->whereDate('date_vente', '<=', $date));
                    }),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('Exporter Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn () => Excel::download(new VentesUvExport, 'ventes_uv_'.now()->format('Y-m-d').'.xlsx'))
                    ->requiresConfirmation()
                    ->modalHeading('Exporter les Ventes UV')
                    ->modalDescription('Télécharger toutes les ventes avec commissions et dettes au format Excel')
                    ->modalSubmitActionLabel('Télécharger'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date_vente', 'desc')
            ->emptyStateHeading('Aucune vente UV')
            ->emptyStateDescription('Commencez par enregistrer une vente UV')
            ->emptyStateIcon('heroicon-o-shopping-bag');
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
            'index' => Pages\ListVenteUvs::route('/'),
            'create' => Pages\CreateVenteUv::route('/create'),
            'edit' => Pages\EditVenteUv::route('/{record}/edit'),
        ];
    }
}
