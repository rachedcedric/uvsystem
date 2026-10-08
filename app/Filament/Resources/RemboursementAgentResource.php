<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RemboursementAgentResource\Pages;
use App\Models\RemboursementAgent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RemboursementAgentResource extends Resource
{
    protected static ?string $model = RemboursementAgent::class;

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Ancienne ressource « partenaires » : sa table (remboursement_agents) n'existe plus dans le schéma actuel, ses pages
     * plantaient en 500. Le code est conservé (modèles, exports et observateurs l'importent encore) mais l'accès
     * est fermé : réponse 403 au lieu d'une erreur serveur.
     */
    public static function canAccess(): bool
    {
        return false;
    }

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Remboursements (Partenaires)';

    protected static ?string $modelLabel = 'Remboursement Partenaire';

    protected static ?string $pluralModelLabel = 'Remboursements Partenaires';

    protected static ?string $navigationGroup = 'Gestion des Ventes';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations de Remboursement')
                    ->schema([
                        Forms\Components\Select::make('agent_partenaire_id')
                            ->label('Agent Partenaire')
                            ->relationship('agentPartenaire', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $agent = \App\Models\AgentPartenaire::find($state);
                                    if ($agent) {
                                        $set('_dette_actuelle', number_format($agent->dette, 0, ',', ' ').' FCFA');
                                        $set('_dette_montant', $agent->dette);
                                    } else {
                                        $set('_dette_actuelle', '0 FCFA (Aucune dette)');
                                        $set('_dette_montant', 0);
                                    }
                                }
                            })
                            ->columnSpan(2),

                        Forms\Components\DateTimePicker::make('date_remboursement')
                            ->label('Date et heure de remboursement')
                            ->default(now())
                            ->required()
                            ->maxDate(now())
                            ->seconds(false)
                            ->native(false),

                        Forms\Components\Placeholder::make('_dette_actuelle')
                            ->label('Dette Actuelle')
                            ->content(fn (Forms\Get $get) => $get('_dette_actuelle') ?? 'Sélectionnez un agent')
                            ->columnSpan(3),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Détails du Paiement')
                    ->schema([
                        Forms\Components\TextInput::make('montant')
                            ->label('Montant à rembourser')
                            ->required()
                            ->money(min: 0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                $detteActuelle = \App\Support\Money::toFloat($get('_dette_montant'));
                                $montant = \App\Support\Money::toFloat($state);
                                if ($montant > $detteActuelle) {
                                    $set('_validation_message', '⚠️ Le montant dépasse la dette actuelle !');
                                } elseif ($montant == $detteActuelle) {
                                    $set('_validation_message', '✅ Dette sera soldée complètement');
                                } else {
                                    $restant = $detteActuelle - $montant;
                                    $set('_validation_message', '📊 Reste à payer: '.number_format($restant, 0, ',', ' ').' FCFA');
                                }
                            }),

                        Forms\Components\Select::make('moyen_paiement')
                            ->label('Moyen de paiement')
                            ->options([
                                'CASH' => '💵 CASH (Espèces)',
                                'WAVE' => '📱 WAVE',
                                'OM' => '📱 Orange Money (OM)',
                            ])
                            ->required()
                            ->default('CASH')
                            ->native(false),

                        Forms\Components\Placeholder::make('_validation_message')
                            ->label('Validation')
                            ->content(fn (Forms\Get $get) => $get('_validation_message') ?? 'Saisissez le montant')
                            ->columnSpan(2),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes additionnelles')
                            ->rows(3)
                            ->placeholder('Informations complémentaires sur le remboursement...')
                            ->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->collapsible(),

                // Hidden fields pour validation
                Forms\Components\Hidden::make('_dette_montant'),
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

                Tables\Columns\TextColumn::make('date_remboursement')
                    ->label('Date et heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->icon('heroicon-o-calendar'),

                Tables\Columns\TextColumn::make('agentPartenaire.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->description(fn (RemboursementAgent $record) => $record->agentPartenaire->phone ?? '')
                    ->icon('heroicon-o-user-group'),

                Tables\Columns\TextColumn::make('montant')
                    ->label('Montant Remboursé')
                    ->money('XOF', locale: 'fr')
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->money('XOF', locale: 'fr')
                        ->label('Total')),

                Tables\Columns\TextColumn::make('moyen_paiement')
                    ->label('Moyen')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'CASH' => 'success',
                        'WAVE' => 'info',
                        'OM' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'CASH' => '💵 CASH',
                        'WAVE' => '📱 WAVE',
                        'OM' => '📱 OM',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('agentPartenaire.detteAgent.dette_restante')
                    ->label('Dette Restante')
                    ->money('XOF', locale: 'fr')
                    ->default('0 FCFA')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->icon(fn ($state) => $state > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
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

                Tables\Filters\SelectFilter::make('moyen_paiement')
                    ->label('Moyen de paiement')
                    ->options([
                        'CASH' => 'CASH',
                        'WAVE' => 'WAVE',
                        'OM' => 'Orange Money',
                    ]),

                Tables\Filters\Filter::make('date_remboursement')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('date_to')
                            ->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['date_from'], fn ($q, $date) => $q->whereDate('date_remboursement', '>=', $date))
                            ->when($data['date_to'], fn ($q, $date) => $q->whereDate('date_remboursement', '<=', $date));
                    }),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date_remboursement', 'desc')
            ->emptyStateHeading('Aucun remboursement')
            ->emptyStateDescription('Commencez par enregistrer un remboursement')
            ->emptyStateIcon('heroicon-o-banknotes');
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
            'index' => Pages\ListRemboursementAgents::route('/'),
            'create' => Pages\CreateRemboursementAgent::route('/create'),
            'edit' => Pages\EditRemboursementAgent::route('/{record}/edit'),
        ];
    }
}
