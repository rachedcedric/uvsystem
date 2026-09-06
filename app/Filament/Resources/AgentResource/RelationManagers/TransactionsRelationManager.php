<?php

namespace App\Filament\Resources\AgentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transactions UV';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('fournisseur_id')
                    ->relationship('fournisseur', 'name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('amount')
                    ->label('Montant (FCFA)')
                    ->required()
                    ->money(min: 1),
                Forms\Components\Select::make('transaction_type')
                    ->label('Type')
                    ->options([
                        'distribution' => 'Distribution',
                        'retour' => 'Retour',
                    ])
                    ->default('distribution')
                    ->required(),
                Forms\Components\DatePicker::make('transaction_date')
                    ->label('Date')
                    ->required()
                    ->default(now())
                    ->maxDate(now()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fournisseur.name')
                    ->label('Fournisseur')
                    ->placeholder('N/A'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('XOF'),
                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'distribution' => 'primary',
                        'retour' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Date')
                    ->date('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('transaction_type')
                    ->options([
                        'distribution' => 'Distribution',
                        'retour' => 'Retour',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Ajouter Transaction'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
