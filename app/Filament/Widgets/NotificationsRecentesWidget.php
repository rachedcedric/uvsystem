<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\NotificationsPage;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;

class NotificationsRecentesWidget extends BaseWidget
{
    protected static ?string $heading = '🔔 Notifications Récentes';

    protected static ?int $sort = 14;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '15s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DatabaseNotification::query()
                    ->where('notifiable_id', Auth::id())
                    ->where('notifiable_type', get_class(Auth::user()))
                    ->latest()
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('data.title')
                    ->label('Notification')
                    ->wrap()
                    ->icon(fn ($record) => $record->read_at ? 'heroicon-o-check' : 'heroicon-o-bell')
                    ->color(fn ($record) => $record->read_at ? 'gray' : 'primary'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->since()
                    ->color('gray')
                    ->size('sm'),
            ])
            ->actions([
                Tables\Actions\Action::make('markAsRead')
                    ->label('')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->size('sm')
                    ->visible(fn ($record) => ! $record->read_at)
                    ->action(fn ($record) => $record->markAsRead()),

                Tables\Actions\Action::make('view')
                    ->label('')
                    ->icon('heroicon-o-arrow-right')
                    ->color('primary')
                    ->size('sm')
                    ->url(fn ($record) => $record->data['url'] ?? null)
                    ->visible(fn ($record) => isset($record->data['url'])),
            ])
            ->contentGrid(['md' => 1])
            ->emptyStateHeading('Aucune notification')
            ->emptyStateDescription('Vous n\'avez pas de notifications.')
            ->emptyStateIcon('heroicon-o-bell-slash')
            ->paginated(false)
            ->headerActions([
                Tables\Actions\Action::make('markAllRead')
                    ->label('Tout marquer lu')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->size('sm')
                    ->action(function () {
                        Auth::user()->unreadNotifications->markAsRead();

                        \Filament\Notifications\Notification::make()
                            ->title('Notifications lues')
                            ->success()
                            ->send();
                    })
                    ->visible(fn () => Auth::user()->unreadNotifications->count() > 0),

                Tables\Actions\Action::make('viewAll')
                    ->label('Voir tout')
                    ->icon('heroicon-o-arrow-right')
                    ->color('primary')
                    ->size('sm')
                    ->url(NotificationsPage::getUrl()),
            ]);
    }
}
