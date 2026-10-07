<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SousDistributeurResource\Pages;
use App\Filament\Resources\SousDistributeurResource\RelationManagers;
use App\Models\SousDistributeur;
use App\Tenancy\TenantContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class SousDistributeurResource extends Resource
{
    protected static ?string $model = SousDistributeur::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Sous-Distributeurs';

    protected static ?string $navigationGroup = 'Gestion Agents';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'nom';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_agents');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->can('create_agents');
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->can('edit_agents');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->can('delete_agents');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations principales')
                    ->schema([
                        Forms\Components\Select::make('agent_id')
                            ->label('Agent parent')
                            ->relationship('agent', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('nom')
                            ->label('Nom complet')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('telephone')
                            ->label('Telephone')
                            ->tel()
                            ->required()
                            ->unique(ignoreRecord: true, modifyRuleUsing: TenantContext::scopeUnique())
                            ->maxLength(20),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('adresse')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('ville')
                            ->maxLength(100),

                        Forms\Components\Select::make('type')
                            ->options([
                                'standard' => 'Standard',
                                'premium' => 'Premium',
                                'vip' => 'VIP',
                            ])
                            ->default('standard')
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Parametres financiers')
                    ->schema([
                        Forms\Components\TextInput::make('plafond_dette')
                            ->label('Plafond de dette (FCFA)')
                            ->money(min: 0)
                            ->helperText('Laisser vide pour pas de limite'),

                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Taux de commission (%)')
                            ->numeric()
                            ->suffix('%')
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(100),

                        Forms\Components\Select::make('statut')
                            ->options([
                                'actif' => 'Actif',
                                'inactif' => 'Inactif',
                                'suspendu' => 'Suspendu',
                            ])
                            ->default('actif')
                            ->required(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('telephone')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'vip' => 'success',
                        'premium' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('solde.solde_restant')
                    ->label('Dette')
                    ->money('XOF')
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('plafond_dette')
                    ->label('Plafond')
                    ->money('XOF')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'actif' => 'success',
                        'suspendu' => 'warning',
                        'inactif' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Cree le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->relationship('agent', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'standard' => 'Standard',
                        'premium' => 'Premium',
                        'vip' => 'VIP',
                    ]),

                Tables\Filters\SelectFilter::make('statut')
                    ->options([
                        'actif' => 'Actif',
                        'inactif' => 'Inactif',
                        'suspendu' => 'Suspendu',
                    ]),

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
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TransactionsRelationManager::class,
            RelationManagers\RemboursementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSousDistributeurs::route('/'),
            'create' => Pages\CreateSousDistributeur::route('/create'),
            'view' => Pages\ViewSousDistributeur::route('/{record}'),
            'edit' => Pages\EditSousDistributeur::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['agent', 'solde']);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::actif()->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
