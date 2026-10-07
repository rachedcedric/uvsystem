<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\Activity;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Journal d\'activité';

    protected static ?string $modelLabel = 'Activité';

    protected static ?string $pluralModelLabel = 'Activités';

    protected static ?string $navigationGroup = 'Gestion du tenant';

    protected static ?int $navigationSort = 99;

    // Read-only resource
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('log_name')
                    ->label('Module')
                    ->badge()
                    ->colors([
                        'primary' => 'default',
                        'success' => 'fournisseurs',
                        'warning' => 'approvisionnements',
                        'info' => 'agents',
                    ])
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Action')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Type d\'objet')
                    ->formatStateUsing(fn ($state) => class_basename($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('subject_id')
                    ->label('ID')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Utilisateur')
                    ->searchable()
                    ->sortable()
                    ->default('Système'),

                Tables\Columns\TextColumn::make('properties')
                    ->label('Détails')
                    ->formatStateUsing(function ($state) {
                        if (! $state) {
                            return '-';
                        }

                        $changes = [];
                        if (isset($state['attributes']) && isset($state['old'])) {
                            foreach ($state['attributes'] as $key => $new) {
                                $old = $state['old'][$key] ?? null;
                                if ($old != $new) {
                                    $changes[] = "{$key}: {$old} → {$new}";
                                }
                            }
                        }

                        return $changes ? implode(', ', $changes) : 'Voir détails';
                    })
                    ->limit(50)
                    ->tooltip(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->icon('heroicon-o-clock'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Module')
                    ->options([
                        'fournisseurs' => 'Fournisseurs',
                        'approvisionnements' => 'Approvisionnements',
                        'agents' => 'Agents',
                        'remboursements' => 'Remboursements',
                        'uv_transactions' => 'Transactions UV',
                    ]),

                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('Utilisateur')
                    ->options(function () {
                        return \App\Models\User::pluck('name', 'id')->toArray();
                    })
                    ->searchable(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('date_to')
                            ->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
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
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
