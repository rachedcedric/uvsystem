<?php

namespace App\Filament\Resources\AgentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RemboursementsRelationManager extends RelationManager
{
    protected static string $relationship = 'remboursements';

    protected static ?string $title = 'Remboursements';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('amount')
                    ->label('Montant (FCFA)')
                    ->required()
                    ->money(min: 1),
                Forms\Components\Select::make('payment_method')
                    ->label('Mode de paiement')
                    ->options([
                        'cash' => 'Espèces',
                        'mobile_money' => 'Mobile Money',
                        'bank_transfer' => 'Virement bancaire',
                        'cheque' => 'Chèque',
                    ])
                    ->required()
                    ->default('cash'),
                Forms\Components\DatePicker::make('payment_date')
                    ->label('Date de paiement')
                    ->required()
                    ->default(now())
                    ->maxDate(now()),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2),
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
                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('XOF')
                    ->color('success'),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'Espèces',
                        'mobile_money' => 'Mobile Money',
                        'bank_transfer' => 'Virement',
                        'cheque' => 'Chèque',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Date')
                    ->date('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Mode de paiement')
                    ->options([
                        'cash' => 'Espèces',
                        'mobile_money' => 'Mobile Money',
                        'bank_transfer' => 'Virement',
                        'cheque' => 'Chèque',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Ajouter Remboursement'),
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
            ->defaultSort('payment_date', 'desc');
    }
}
