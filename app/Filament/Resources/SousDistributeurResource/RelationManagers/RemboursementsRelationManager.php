<?php

namespace App\Filament\Resources\SousDistributeurResource\RelationManagers;

use App\Models\SousDistributeurRemboursement;
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
                Forms\Components\Hidden::make('agent_id')
                    ->default(fn () => $this->ownerRecord->agent_id),

                Forms\Components\TextInput::make('amount')
                    ->label('Montant (FCFA)')
                    ->required()
                    ->money(min: 1),

                Forms\Components\Select::make('payment_method')
                    ->label('Mode de paiement')
                    ->options([
                        'especes' => 'Especes',
                        'mobile_money' => 'Mobile Money',
                        'virement' => 'Virement bancaire',
                        'cheque' => 'Cheque',
                    ])
                    ->default('especes')
                    ->required(),

                Forms\Components\DateTimePicker::make('payment_date')
                    ->label('Date et heure de paiement')
                    ->default(now())
                    ->required()
                    ->maxDate(now())
                    ->seconds(false)
                    ->native(false),

                Forms\Components\Hidden::make('reference')
                    ->default(fn () => SousDistributeurRemboursement::generateReference()),

                Forms\Components\Textarea::make('notes')
                    ->rows(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('XOF')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Mode')
                    ->badge(),

                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Date et heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Mode de paiement')
                    ->options([
                        'especes' => 'Especes',
                        'mobile_money' => 'Mobile Money',
                        'virement' => 'Virement',
                        'cheque' => 'Cheque',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nouveau remboursement'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
