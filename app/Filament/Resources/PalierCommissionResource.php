<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PalierCommissionResource\Pages;
use App\Models\PalierCommission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PalierCommissionResource extends Resource
{
    protected static ?string $model = PalierCommission::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Paliers Commission';

    protected static ?string $modelLabel = 'Palier de commission';

    protected static ?string $pluralModelLabel = 'Paliers de commission';

    protected static ?string $navigationGroup = 'Paramètres du tenant';

    protected static ?int $navigationSort = 101;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_system_settings');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->can('edit_system_settings');
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->can('edit_system_settings');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->can('edit_system_settings');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuration du palier')
                    ->description('La commission est calculée sur le TOTAL JOURNALIER des ventes UV (jusqu\'à 18h)')
                    ->schema([
                        Forms\Components\TextInput::make('seuil_min')
                            ->label('Seuil minimum (FCFA)')
                            ->required()
                            ->money(min: 0)
                            ->helperText('Montant minimum du total journalier'),

                        Forms\Components\TextInput::make('seuil_max')
                            ->label('Seuil maximum (FCFA)')
                            ->required()
                            ->money(min: 1)
                            ->helperText('Montant maximum du total journalier'),

                        Forms\Components\TextInput::make('commission')
                            ->label('Commission (FCFA)')
                            ->required()
                            ->money(min: 0)
                            ->helperText('Montant fixe de la commission pour ce palier'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true)
                            ->helperText('Désactiver pour ignorer ce palier'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('seuil_min')
                    ->label('Seuil Min')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('seuil_max')
                    ->label('Seuil Max')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('commission')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->color('success')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('seuil_min', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->placeholder('Tous')
                    ->trueLabel('Actifs uniquement')
                    ->falseLabel('Inactifs uniquement'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListPalierCommissions::route('/'),
            'create' => Pages\CreatePalierCommission::route('/create'),
            'edit' => Pages\EditPalierCommission::route('/{record}/edit'),
        ];
    }
}
