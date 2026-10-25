<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FournisseurResource\Pages;
use App\Models\Fournisseur;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FournisseurResource extends Resource
{
    protected static ?string $model = Fournisseur::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Fournisseurs';

    protected static ?string $modelLabel = 'Fournisseur';

    protected static ?string $pluralModelLabel = 'Fournisseurs';

    protected static ?string $navigationGroup = 'Gestion Approvisionnement';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations du Fournisseur')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom du fournisseur')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Select::make('type')
                            ->label('Type de fournisseur')
                            ->options(Fournisseur::SELECTABLE_TYPES)
                            ->required()
                            ->native(false)
                            ->default('BANQUE'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Coordonnées')
                    ->schema([
                        Forms\Components\TextInput::make('contact')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('address')
                            ->label('Adresse')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

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
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'AGENT_PREMIUM' => 'success',
                        'BANQUE' => 'primary',
                        'AGENT' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Fournisseur::TYPE_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('contact')
                    ->label('Téléphone')
                    ->searchable()
                    ->icon('heroicon-o-phone'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->icon('heroicon-o-envelope')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('approvisionnements_count')
                    ->label('Approvisions.')
                    ->counts('approvisionnements')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options(Fournisseur::TYPE_LABELS),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueLabel('Actifs uniquement')
                    ->falseLabel('Inactifs uniquement')
                    ->native(false),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Voir la fiche'),
                    Tables\Actions\EditAction::make()->label('Modifier'),
                    Tables\Actions\DeleteAction::make()->label('Supprimer'),
                ])->tooltip('Actions'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListFournisseurs::route('/'),
            'create' => Pages\CreateFournisseur::route('/create'),
            'view' => Pages\ViewFournisseur::route('/{record}'),
            'edit' => Pages\EditFournisseur::route('/{record}/edit'),
        ];
    }
}
