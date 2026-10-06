<?php

namespace App\Notifications\Channels;

use App\Contracts\PushNotification;
use App\Models\PushToken;
use App\Models\User;
use App\Services\FirebaseMessaging;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class FirebasePushChannel
{
    public function __construct(private readonly FirebaseMessaging $messaging) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof PushNotification) {
            return;
        }

        $tokens = PushToken::query()->where('user_id', $notifiable->id)->get();

        foreach ($tokens as $token) {
            try {
                $this->messaging->send($token, $notification->pushPayload());
            } catch (Throwable $exception) {
                Log::error('Échec d’envoi de notification push Firebase', [
                    'user_id' => $notifiable->id,
                    'push_token_id' => $token->id,
                    'notification' => $notification::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
