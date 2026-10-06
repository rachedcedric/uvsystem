<?php

namespace App\Filament\Resources;

use App\Tenancy\TenantContext;
use App\Filament\Resources\ChargeCategorieResource\Pages;
use App\Models\ChargeCategorie;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ChargeCategorieResource extends Resource
{
    protected static ?string $model = ChargeCategorie::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Catégories de charges';

    protected static ?string $modelLabel = 'Catégorie de charge';

    protected static ?string $pluralModelLabel = 'Catégories de charges';

    protected static ?string $navigationGroup = 'Gestion des Charges';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('edit_charges');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations de la catégorie')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->unique(ignoreRecord: true, modifyRuleUsing: TenantContext::scopeUnique())
                            ->maxLength(50)
                            ->alphaDash()
                            ->helperText('Identifiant unique (ex: loyer, internet)')
                            ->placeholder('loyer'),

                        Forms\Components\TextInput::make('name')
                            ->label('Nom affiché')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Loyer'),

                        Forms\Components\TextInput::make('description')
                            ->label('Description')
                            ->maxLength(255)
                            ->placeholder('Description optionnelle'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Ordre d\'affichage')
                            ->numeric()
                            ->default(0),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true)
                            ->helperText('Désactiver pour masquer cette catégorie sans la supprimer'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width('50px'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('charges_count')
                    ->label('Charges liées')
                    ->counts('charges')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->placeholder('Toutes')
                    ->trueLabel('Actives uniquement')
                    ->falseLabel('Inactives uniquement'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (ChargeCategorie $record) {
                        if ($record->charges()->count() > 0) {
                            throw new \Exception('Cette catégorie est utilisée par des charges. Désactivez-la plutôt.');
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('sort_order');
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
            'index' => Pages\ListChargeCategories::route('/'),
            'create' => Pages\CreateChargeCategorie::route('/create'),
            'edit' => Pages\EditChargeCategorie::route('/{record}/edit'),
        ];
    }
}
