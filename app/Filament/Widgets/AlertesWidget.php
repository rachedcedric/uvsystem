<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;

class AlertesWidget extends BaseWidget
{
    protected static ?string $heading = 'Alertes et actions requises';

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Agent::query()
                    ->where('status', 'active')
                    ->where('current_debt', '>', 0)
                    ->where('debt_limit', '>', 0)
                    ->whereRaw('(current_debt / debt_limit * 100) >= 70')
                    ->orderByRaw('(current_debt / debt_limit) DESC')
                    // Dernier paiement chargé en UNE requête avec la liste (évite 2 requêtes par ligne).
                    ->withMax('remboursements as last_payment_at', 'created_at')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('priority')
                    ->label('Niveau')
                    ->badge()
                    ->getStateUsing(function (Agent $record): string {
                        $ratio = $record->debt_limit > 0
                            ? ($record->current_debt / $record->debt_limit) * 100
                            : 0;

                        return match (true) {
                            $ratio >= 100 => 'Critique',
                            $ratio >= 90 => 'Élevé',
                            $ratio >= 80 => 'Attention',
                            default => 'À surveiller',
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Critique' => 'danger',
                        'Élevé' => 'warning',
                        'Attention' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('name')
                    ->label('Agent')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->copyable()
                    ->icon('heroicon-o-phone'),

                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Dette')
                    ->money('XOF')
                    ->color('danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('debt_ratio')
                    ->label('Ratio')
                    ->getStateUsing(fn (Agent $record): string => $record->debt_limit > 0
                            ? number_format(($record->current_debt / $record->debt_limit) * 100, 0).'%'
                            : 'N/A'
                    )
                    ->badge()
                    ->color(fn (Agent $record): string => $record->debt_limit > 0 && ($record->current_debt / $record->debt_limit) >= 1
                            ? 'danger'
                            : (($record->current_debt / $record->debt_limit) >= 0.9 ? 'warning' : 'success')
                    ),

                Tables\Columns\TextColumn::make('days_since_last_payment')
                    ->label('Dernier paiement')
                    ->getStateUsing(function (Agent $record): string {
                        if (! $record->last_payment_at) {
                            return 'Jamais';
                        }

                        $days = (int) Carbon::parse($record->last_payment_at)->diffInDays(now());
                        if ($days == 0) {
                            return "Aujourd'hui";
                        }
                        if ($days == 1) {
                            return 'Hier';
                        }

                        return "Il y a {$days}j";
                    })
                    ->color(fn (Agent $record): string => $record->last_payment_at && Carbon::parse($record->last_payment_at)->gte(now()->subDays(7))
                            ? 'success'
                            : 'warning'
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('sendReminder')
                    ->label('Rappel')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->size('sm')
                    ->form([
                        \Filament\Forms\Components\Select::make('channel')
                            ->label('Canal')
                            ->options([
                                'sms' => 'SMS',
                                'email' => 'E-mail',
                                'both' => 'SMS et e-mail',
                            ])
                            ->default('sms')
                            ->required(),
                    ])
                    ->modalHeading('Envoyer un rappel')
                    ->modalDescription(fn (Agent $record): string => "Rappel à {$record->name} - Dette: ".
                        number_format($record->current_debt, 0, ',', ' ').' FCFA'
                    )
                    ->action(function (Agent $record, array $data) {
                        $channel = $data['channel'];
                        $smsService = app(\App\Services\SmsService::class);
                        $sentChannels = [];

                        try {
                            // Envoi SMS
                            if (in_array($channel, ['sms', 'both'])) {
                                $smsMessage = "Bonjour {$record->name}, vous avez une dette de ".
                                    number_format($record->current_debt, 0, ',', ' ').' FCFA. Merci de régulariser. - '.config('branding.name');

                                if ($smsService->sendToAgent($record, $smsMessage)) {
                                    $sentChannels[] = 'SMS';
                                }
                            }

                            // Envoi Email
                            if (in_array($channel, ['email', 'both']) && $record->email) {
                                $record->notify(new \App\Notifications\PaymentReminderNotification($record));
                                $sentChannels[] = 'Email';
                            }

                            if (count($sentChannels) > 0) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Rappel envoyé')
                                    ->body(implode(' + ', $sentChannels)." à {$record->name}")
                                    ->success()
                                    ->send();
                            } else {
                                \Filament\Notifications\Notification::make()
                                    ->title('Échec')
                                    ->body("Vérifiez la config SMS ou l'email de l'agent")
                                    ->warning()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Erreur')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('view')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->size('sm')
                    ->url(fn (Agent $record): string => "/master/agents/{$record->id}"),
            ])
            ->emptyStateHeading('Aucune alerte')
            ->emptyStateDescription('Tous les agents sont en règle.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return Agent::query()
            ->where('status', 'active')
            ->where('current_debt', '>', 0)
            ->where('debt_limit', '>', 0)
            ->whereRaw('(current_debt / debt_limit * 100) >= 70')
            ->exists();
    }
}
