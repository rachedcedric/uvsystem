<?php

namespace App\Filament\Resources\SousDistributeurResource\RelationManagers;

use App\Models\SousDistributeurTransaction;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transactions';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('agent_id')
                    ->default(fn () => $this->ownerRecord->agent_id),

                Forms\Components\TextInput::make('quantity_uv')
                    ->label('Quantite UV')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $prixUnitaire = Money::toFloat($get('prix_unitaire'));
                        $set('amount', $state * $prixUnitaire);
                    }),

                Forms\Components\TextInput::make('prix_unitaire')
                    ->label('Prix unitaire (FCFA)')
                    ->required()
                    ->money(min: 0)
                    ->default(2000)
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $quantity = $get('quantity_uv') ?? 0;
                        $set('amount', $quantity * Money::toFloat($state));
                    }),

                Forms\Components\TextInput::make('amount')
                    ->label('Montant total (FCFA)')
                    ->required()
                    ->money(min: -999999999999)
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\Select::make('type')
                    ->options([
                        'distribution' => 'Distribution',
                        'retour' => 'Retour',
                    ])
                    ->default('distribution')
                    ->required(),

                Forms\Components\DateTimePicker::make('transaction_date')
                    ->label('Date et heure')
                    ->default(now())
                    ->required()
                    ->maxDate(now())
                    ->seconds(false)
                    ->native(false),

                Forms\Components\Hidden::make('reference')
                    ->default(fn () => SousDistributeurTransaction::generateReference()),

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

                Tables\Columns\TextColumn::make('quantity_uv')
                    ->label('UV')
                    ->numeric(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('XOF'),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'distribution' => 'success',
                        'retour' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Date et heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'distribution' => 'Distribution',
                        'retour' => 'Retour',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nouvelle transaction'),
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
            ->defaultSort('transaction_date', 'desc');
    }
}
