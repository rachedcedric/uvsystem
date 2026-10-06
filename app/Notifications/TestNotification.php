<?php

namespace App\Notifications;

use App\Contracts\PushNotification as PushNotificationContract;
use App\Notifications\Channels\FirebasePushChannel;
use Illuminate\Notifications\Notification;

/** Notification de test (cloche + push) lancée depuis l'écran « Diagnostic des notifications ». */
class TestNotification extends Notification implements PushNotificationContract
{
    /** @param bool $push false : cloche seulement (le push est alors envoyé et suivi à part). */
    public function __construct(private readonly bool $push = true) {}

    public function via(object $notifiable): array
    {
        return $this->push ? ['database', FirebasePushChannel::class] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => '🔔 Test de notification',
            'body' => 'Si vous voyez ceci dans la cloche, les notifications fonctionnent.',
            'icon' => 'heroicon-o-bell',
            'iconColor' => 'success',
            'color' => 'success',
            'status' => 'success',
            'duration' => 'persistent',
            'view' => null,
            'viewData' => [],
            'actions' => [],
            'url' => '/m/notifications',
            'type' => 'success',
            'notification_kind' => 'test',
        ];
    }

    public function pushPayload(): array
    {
        return [
            'title' => 'Test de notification',
            'body' => 'Les notifications push fonctionnent sur cet appareil.',
            'url' => '/m/notifications',
            'type' => 'test',
        ];
    }
}
