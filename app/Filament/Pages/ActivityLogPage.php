<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Journal d\'activité';

    protected static ?string $title = 'Journal d\'activité';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.activity-log-page';

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->latest())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('log_name')
                    ->label('Type')
                    ->badge()
                    ->colors([
                        'primary' => 'transactions',
                        'success' => 'remboursements',
                        'warning' => 'default',
                    ]),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Entité')
                    ->formatStateUsing(fn ($state) => class_basename($state ?? ''))
                    ->badge(),
                Tables\Columns\TextColumn::make('subject_id')
                    ->label('ID'),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Utilisateur')
                    ->placeholder('Système'),
                Tables\Columns\TextColumn::make('event')
                    ->label('Action')
                    ->badge()
                    ->colors([
                        'success' => 'created',
                        'warning' => 'updated',
                        'danger' => 'deleted',
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Type')
                    ->options([
                        'transactions' => 'Transactions',
                        'remboursements' => 'Remboursements',
                        'default' => 'Autres',
                    ]),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Action')
                    ->options([
                        'created' => 'Création',
                        'updated' => 'Modification',
                        'deleted' => 'Suppression',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('Détails')
                    ->icon('heroicon-o-eye')
                    ->modalContent(fn (Activity $record) => view('filament.pages.activity-details', ['activity' => $record]))
                    ->modalHeading('Détails de l\'activité')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
