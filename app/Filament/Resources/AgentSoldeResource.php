<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentSoldeResource\Pages;
use App\Models\Agent;
use App\Models\AgentSolde;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentSoldeResource extends Resource
{
    protected static ?string $model = AgentSolde::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Soldes Agents';

    protected static ?string $modelLabel = 'Solde Agent';

    protected static ?string $pluralModelLabel = 'Soldes Agents';

    protected static ?string $navigationGroup = 'Gestion Financière';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations Agent')
                    ->description('Sélectionnez l\'agent et configurez son solde')
                    ->schema([
                        Forms\Components\Select::make('agent_id')
                            ->label('Agent')
                            ->relationship('agent', 'name')
                            ->searchable(['name', 'phone'])
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $agent = Agent::find($state);
                                    if ($agent) {
                                        // Charger les valeurs actuelles si elles existent
                                        $solde = $agent->solde;
                                        if ($solde) {
                                            $set('total_uv_distribues', $solde->total_uv_distribues);
                                            $set('total_rembourse', $solde->total_rembourse);
                                            $set('solde_restant', $solde->solde_restant);
                                        } else {
                                            // Calculer automatiquement si pas de solde existant
                                            $totalDistributed = $agent->transactions()
                                                ->where('transaction_type', 'distribution')
                                                ->sum('amount');
                                            $totalReturned = $agent->transactions()
                                                ->where('transaction_type', 'retour')
                                                ->sum('amount');
                                            $totalRepaid = $agent->remboursements()->sum('amount');

                                            $set('total_uv_distribues', $totalDistributed);
                                            $set('total_rembourse', $totalRepaid);
                                            $set('solde_restant', $totalDistributed - $totalReturned - $totalRepaid);
                                        }
                                    }
                                }
                            })
                            ->helperText('Les valeurs seront pré-remplies automatiquement si le solde existe'),
                    ]),

                Forms\Components\Section::make('Détails Financiers')
                    ->description('Montants en FCFA - Modifiables manuellement')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('total_uv_distribues')
                            ->label('Total UV Distribuées')
                            ->required()
                            ->money(min: 0)
                            ->default(0.00)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                $totalDist = \App\Support\Money::toFloat($state);
                                $totalRemb = \App\Support\Money::toFloat($get('total_rembourse'));
                                $set('solde_restant', $totalDist - $totalRemb);
                            })
                            ->helperText('Montant total des UV distribuées'),

                        Forms\Components\TextInput::make('total_rembourse')
                            ->label('Total Remboursé')
                            ->required()
                            ->money(min: 0)
                            ->default(0.00)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                $totalDist = \App\Support\Money::toFloat($get('total_uv_distribues'));
                                $totalRemb = \App\Support\Money::toFloat($state);
                                $set('solde_restant', $totalDist - $totalRemb);
                            })
                            ->helperText('Montant total des remboursements'),

                        Forms\Components\TextInput::make('solde_restant')
                            ->label('Solde Restant (Dette)')
                            ->required()
                            // Champ calculé automatiquement (distribué - remboursé) :
                            // pas de borne minimale, un trop-perçu peut théoriquement
                            // le rendre négatif et ce n'est pas à l'utilisateur (champ
                            // readOnly) de corriger une validation dessus.
                            ->money(min: -999999999999)
                            ->default(0.00)
                            ->readOnly()
                            ->helperText('Calculé automatiquement')
                            ->extraAttributes(['class' => 'font-bold']),
                    ]),

                Forms\Components\Section::make('Actions')
                    ->schema([
                        Forms\Components\Placeholder::make('info')
                            ->label('')
                            ->content('💡 **Note importante**: Les soldes sont normalement mis à jour automatiquement par le système lors des transactions et remboursements. Utilisez cette création manuelle uniquement pour corriger des incohérences ou initialiser des données historiques.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->description(fn (AgentSolde $record): string => $record->agent->phone ?? ''),

                Tables\Columns\TextColumn::make('total_uv_distribues')
                    ->label('Total Distribué')
                    ->money('XOF', locale: 'fr')
                    ->sortable()
                    ->alignEnd()
                    ->color('info'),

                Tables\Columns\TextColumn::make('total_rembourse')
                    ->label('Total Remboursé')
                    ->money('XOF', locale: 'fr')
                    ->sortable()
                    ->alignEnd()
                    ->color('success'),

                Tables\Columns\TextColumn::make('solde_restant')
                    ->label('Dette Restante')
                    ->money('XOF', locale: 'fr')
                    ->sortable()
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->icon(fn ($state) => $state > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Dernière MAJ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable()
                    ->description(fn (AgentSolde $record): string => 'Créé le '.$record->created_at->format('d/m/Y')),
            ])
            ->defaultSort('solde_restant', 'desc')
            ->filters([
                Tables\Filters\Filter::make('avec_dette')
                    ->label('Avec dette uniquement')
                    ->query(fn (Builder $query): Builder => $query->where('solde_restant', '>', 0)),

                Tables\Filters\Filter::make('dette_critique')
                    ->label('Dette > 100,000 FCFA')
                    ->query(fn (Builder $query): Builder => $query->where('solde_restant', '>', 100000)),
            ])
            ->actions([
                Tables\Actions\Action::make('recalculate')
                    ->label('Recalculer')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Recalculer le solde')
                    ->modalDescription('Cette action recalculera automatiquement le solde en fonction des transactions et remboursements réels.')
                    ->action(function (AgentSolde $record) {
                        AgentSolde::recalculate($record->agent);

                        Notification::make()
                            ->title('Solde recalculé avec succès')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Supprimer ce solde')
                    ->modalDescription('⚠️ Attention : Le solde sera recréé automatiquement lors de la prochaine transaction. Êtes-vous sûr ?'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('recalculate_all')
                        ->label('Recalculer tous les soldes')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                AgentSolde::recalculate($record->agent);
                            }

                            Notification::make()
                                ->title(count($records).' soldes recalculés')
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun solde trouvé')
            ->emptyStateDescription('Les soldes sont créés automatiquement lors des transactions. Vous pouvez aussi en créer manuellement.')
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
            'index' => Pages\ListAgentSoldes::route('/'),
            'create' => Pages\CreateAgentSolde::route('/create'),
            'edit' => Pages\EditAgentSolde::route('/{record}/edit'),
        ];
    }
}
