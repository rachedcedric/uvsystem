<?php

namespace App\Filament\Resources\AgentResource\RelationManagers;

use App\Models\Agent;
use App\Models\Remboursement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

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
                // Verrou sur l'agent + transaction : RemboursementObserver
                // recalcule ensuite AgentSolde/current_debt à partir d'un
                // état garanti non concurrent (même garantie que
                // EditRemboursement::handleRecordUpdate()).
                Tables\Actions\EditAction::make()
                    ->using(function (Remboursement $record, array $data): Remboursement {
                        return DB::transaction(function () use ($record, $data) {
                            if ($record->agent_id) {
                                Agent::whereKey($record->agent_id)->lockForUpdate()->first();
                            }

                            $record->update($data);

                            return $record;
                        });
                    }),
                Tables\Actions\DeleteAction::make()
                    ->using(function (Remboursement $record): void {
                        DB::transaction(function () use ($record) {
                            if ($record->agent_id) {
                                Agent::whereKey($record->agent_id)->lockForUpdate()->first();
                            }

                            $record->delete();
                        });
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('payment_date', 'desc');
    }
}
