<?php

namespace App\Filament\Pages;

use App\Models\Agent;
use App\Notifications\DebtReminderNotification;
use App\Services\NotificationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class NotificationsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $slug = 'notifications';

    protected static ?string $title = 'Rappels des dettes';

    protected static ?string $navigationGroup = 'Notifications du tenant';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.notifications-page';

    public function getTableQuery(): Builder
    {
        return Agent::query()
            ->active()
            ->withDebt()
            ->with('solde');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Agent')
                    ->searchable(['name', 'phone'])
                    ->weight('bold')
                    ->description(fn (Agent $record) => $record->phone ?: 'Téléphone non renseigné'),

                Tables\Columns\TextColumn::make('solde.total_uv_distribues')
                    ->label('Dette initiale')
                    ->formatStateUsing(fn ($state, Agent $record) => $this->formatMoney((float) ($state ?? $record->current_debt)))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('solde.total_rembourse')
                    ->label('Remboursé')
                    ->formatStateUsing(fn ($state) => $this->formatMoney((float) $state))
                    ->color('success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Reste à payer')
                    ->formatStateUsing(fn ($state) => $this->formatMoney((float) $state))
                    ->weight('bold')
                    ->color('danger')
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('debt_status')
                    ->label('Statut')
                    ->getStateUsing(fn (Agent $record) => DettesPage::statusLabel($record))
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('last_reminder')
                    ->label('Dernier rappel')
                    ->getStateUsing(fn (Agent $record) => $this->lastReminderAt($record))
                    ->formatStateUsing(fn ($state) => $state?->translatedFormat('d/m/Y à H:i') ?? 'Pas encore envoyé')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('next_reminder')
                    ->label('Prochain rappel')
                    ->getStateUsing(fn () => $this->nextReminderAt())
                    ->formatStateUsing(fn ($state) => $state->translatedFormat('d/m/Y à H:i'))
                    ->icon('heroicon-o-clock')
                    ->color('primary'),
            ])
            ->actions([
                Tables\Actions\Action::make('encaisser')
                    ->label('Encaisser')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->url(fn (Agent $record) => "/master/remboursements/create?agent_id={$record->id}")
                    ->visible(fn () => Auth::user()?->can('create_remboursements')),
            ])
            ->emptyStateHeading('Aucune dette active')
            ->emptyStateDescription('Tous les agents sont à jour. Aucun rappel ne sera envoyé.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->defaultSort('current_debt', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->poll('30s');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sendNow')
                ->label('Envoyer les rappels maintenant')
                ->icon('heroicon-o-bell-alert')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Envoyer les rappels de dettes')
                ->modalDescription('Un rappel sera créé pour chaque agent endetté qui n\'a pas encore été notifié pendant le cycle de 2 heures en cours.')
                ->action(function () {
                    $result = app(NotificationService::class)->checkAndSendDebtReminders();

                    Notification::make()
                        ->title("{$result['sent']} rappel(s) envoyé(s)")
                        ->body("{$result['skipped_duplicate']} agent(s) déjà notifié(s) pendant ce cycle.")
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

    private function lastReminderAt(Agent $agent): ?\Illuminate\Support\Carbon
    {
        $createdAt = \Illuminate\Notifications\DatabaseNotification::query()
            ->where('type', DebtReminderNotification::class)
            ->where('data->agent_id', $agent->id)
            ->latest()
            ->value('created_at');

        return $createdAt ? \Illuminate\Support\Carbon::parse($createdAt) : null;
    }

    private function nextReminderAt(): \Illuminate\Support\Carbon
    {
        $next = now()->copy()->startOfHour();
        $next->hour += $next->hour % 2 === 0 ? 2 : 1;

        return $next;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
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
