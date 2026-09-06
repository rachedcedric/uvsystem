<?php

namespace App\Filament\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;

class NotificationsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Centre de notifications';

    protected static ?string $navigationGroup = 'Système';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.notifications-page';

    public function getTableQuery(): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_id', Auth::id())
            ->where('notifiable_type', get_class(Auth::user()))
            ->latest();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\IconColumn::make('read_at')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-bell')
                    ->trueColor('success')
                    ->falseColor('primary')
                    ->getStateUsing(fn ($record) => $record->read_at !== null),

                Tables\Columns\TextColumn::make('icon')
                    ->label('')
                    ->getStateUsing(fn ($record) => $this->getNotificationIcon($record))
                    ->html(),

                Tables\Columns\TextColumn::make('data.title')
                    ->label('Titre')
                    ->searchable()
                    ->weight('bold')
                    ->wrap(),

                Tables\Columns\TextColumn::make('data.body')
                    ->label('Message')
                    ->searchable()
                    ->wrap()
                    ->limit(100)
                    ->color('gray'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->getStateUsing(fn ($record) => $this->getNotificationType($record))
                    ->badge()
                    ->color(fn ($record) => $this->getTypeColor($record)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->since()
                    ->description(fn ($record) => $record->created_at->format('d/m/Y H:i')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('read')
                    ->label('Statut')
                    ->placeholder('Toutes')
                    ->trueLabel('Lues')
                    ->falseLabel('Non lues')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('read_at'),
                        false: fn (Builder $query) => $query->whereNull('read_at'),
                    ),

                Tables\Filters\Filter::make('today')
                    ->label('Aujourd\'hui')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),

                Tables\Filters\Filter::make('this_week')
                    ->label('Cette semaine')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])),
            ])
            ->actions([
                Tables\Actions\Action::make('markAsRead')
                    ->label('Marquer lu')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => ! $record->read_at)
                    ->action(function ($record) {
                        $record->markAsRead();

                        Notification::make()
                            ->title('Notification marquée comme lue')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('markAsUnread')
                    ->label('Marquer non lu')
                    ->icon('heroicon-o-bell')
                    ->color('warning')
                    ->visible(fn ($record) => $record->read_at)
                    ->action(function ($record) {
                        $record->update(['read_at' => null]);

                        Notification::make()
                            ->title('Notification marquée comme non lue')
                            ->info()
                            ->send();
                    }),

                Tables\Actions\Action::make('goto')
                    ->label('Voir')
                    ->icon('heroicon-o-arrow-right')
                    ->color('primary')
                    ->url(fn ($record) => $record->data['url'] ?? null)
                    ->visible(fn ($record) => isset($record->data['url'])),

                Tables\Actions\DeleteAction::make()
                    ->label('Supprimer'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('markAllAsRead')
                    ->label('Marquer comme lues')
                    ->icon('heroicon-o-check-circle')
                    ->action(function ($records) {
                        $records->each->markAsRead();

                        Notification::make()
                            ->title(count($records).' notifications marquées comme lues')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteBulkAction::make()
                    ->label('Supprimer'),
            ])
            ->emptyStateHeading('Aucune notification')
            ->emptyStateDescription('Vous n\'avez pas encore de notifications.')
            ->emptyStateIcon('heroicon-o-bell-slash')
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('30s');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markAllRead')
                ->label('Tout marquer comme lu')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->action(function () {
                    Auth::user()->unreadNotifications->markAsRead();

                    Notification::make()
                        ->title('Toutes les notifications marquées comme lues')
                        ->success()
                        ->send();
                })
                ->visible(fn () => Auth::user()->unreadNotifications->count() > 0),

            Actions\Action::make('deleteOld')
                ->label('Supprimer anciennes')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Supprimer les anciennes notifications')
                ->modalDescription('Supprimer toutes les notifications de plus de 30 jours ?')
                ->action(function () {
                    $count = DatabaseNotification::query()
                        ->where('notifiable_id', Auth::id())
                        ->where('created_at', '<', now()->subDays(30))
                        ->delete();

                    Notification::make()
                        ->title("{$count} notifications supprimées")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('refresh')
                ->label('Actualiser')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->resetTable()),
        ];
    }

    private function getNotificationIcon($record): string
    {
        $type = $record->data['type'] ?? 'info';

        return match ($type) {
            'debt_alert', 'critical' => '🚨',
            'warning' => '⚠️',
            'payment_reminder' => '📢',
            'transaction' => '💳',
            'remboursement' => '💰',
            'success' => '✅',
            'error' => '❌',
            'report' => '📊',
            'backup' => '💾',
            default => '🔔',
        };
    }

    private function getNotificationType($record): string
    {
        $type = $record->data['type'] ?? 'info';

        return match ($type) {
            'debt_alert' => 'Alerte dette',
            'critical' => 'Critique',
            'warning' => 'Avertissement',
            'payment_reminder' => 'Rappel paiement',
            'transaction' => 'Transaction',
            'remboursement' => 'Remboursement',
            'success' => 'Succès',
            'error' => 'Erreur',
            'report' => 'Rapport',
            'backup' => 'Sauvegarde',
            default => 'Information',
        };
    }

    private function getTypeColor($record): string
    {
        $type = $record->data['type'] ?? 'info';

        return match ($type) {
            'debt_alert', 'critical', 'error' => 'danger',
            'warning', 'payment_reminder' => 'warning',
            'success', 'remboursement' => 'success',
            'transaction' => 'primary',
            default => 'gray',
        };
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Auth::user()?->unreadNotifications->count() ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
