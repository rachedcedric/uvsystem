<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentPartenaireResource\Pages;
use App\Models\AgentPartenaire;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AgentPartenaireResource extends Resource
{
    protected static ?string $model = AgentPartenaire::class;

    // Même correspondance que l'écran mobile (Crud\Resources\AgentsPartenaires) : la ressource n'avait aucun contrôle.
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view_agents');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('create_agents');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('edit_agents');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('delete_agents');
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->can('delete_agents');
    }

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Agents Partenaires';

    protected static ?string $modelLabel = 'Agent Partenaire';

    protected static ?string $pluralModelLabel = 'Agents Partenaires';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations de l\'Agent')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Ex: Société ACME')
                            ->columnSpan(2),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Coordonnées')
                    ->schema([
                        Forms\Components\TextInput::make('phone')
                            ->label('Numéro de téléphone')
                            ->tel()
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Ex: +225 07 XX XX XX XX')
                            ->prefixIcon('heroicon-o-phone'),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('Ex: contact@exemple.com')
                            ->prefixIcon('heroicon-o-envelope'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Limites')
                    ->schema([
                        Forms\Components\TextInput::make('plafond_dette')
                            ->label('Plafond de dette')
                            ->money(min: 0)
                            ->placeholder('Laisser vide pour illimité'),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-building-office')
                    ->description(fn ($record) => $record->email),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable()
                    ->icon('heroicon-o-phone')
                    ->copyable()
                    ->copyMessage('Numéro copié'),

                Tables\Columns\TextColumn::make('dette')
                    ->label('Dette')
                    ->money('XOF', locale: 'fr')
                    ->default('0 FCFA')
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->weight(fn ($state) => $state > 0 ? 'bold' : 'normal')
                    ->icon(fn ($state) => $state > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueLabel('Actifs uniquement')
                    ->falseLabel('Inactifs uniquement')
                    ->native(false),

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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucun agent partenaire')
            ->emptyStateDescription('Commencez par ajouter un agent avec qui vous travaillez.')
            ->emptyStateIcon('heroicon-o-user-group');
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
            'index' => Pages\ListAgentPartenaires::route('/'),
            'create' => Pages\CreateAgentPartenaire::route('/create'),
            'edit' => Pages\EditAgentPartenaire::route('/{record}/edit'),
        ];
    }
}
