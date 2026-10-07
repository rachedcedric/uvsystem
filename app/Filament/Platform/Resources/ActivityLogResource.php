<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\ActivityLogResource\Pages;
use App\Models\Activity;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Journal plateforme';

    protected static ?string $navigationGroup = 'Système';

    protected static ?string $modelLabel = 'activité plateforme';

    protected static ?string $pluralModelLabel = 'activités plateforme';

    protected static ?int $navigationSort = 80;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

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

    public static function getEloquentQuery(): Builder
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        return Activity::query()
            ->withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->with('causer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('log_name')
                    ->label('Module')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Action')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Événement')
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Super Admin')
                    ->placeholder('Système'),
                Tables\Columns\TextColumn::make('properties')
                    ->label('Détails')
                    ->formatStateUsing(fn ($state): string => $state
                        ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : '—')
                    ->limit(80)
                    ->tooltip(fn ($state): ?string => $state
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
