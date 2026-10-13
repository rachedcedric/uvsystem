<?php

namespace App\Notifications;

use App\Notifications\Concerns\RequiresPlanFeature;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

class SmsNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $message,
        protected ?string $type = null
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    use RequiresPlanFeature;

    protected function requiredFeature(): string
    {
        return 'notifications_dettes';
    }

    public function via(object $notifiable): array
    {
        if (! $this->planAllows($notifiable)) {
            return [];
        }

        // Only send SMS if enabled
        if (! config('services.sms.enabled', false)) {
            return [];
        }

        return [TwilioChannel::class];
    }

    /**
     * Get the Twilio SMS representation of the notification.
     */
    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        return (new TwilioSmsMessage)
            ->content($this->message);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type ?? 'sms',
            'message' => $this->message,
        ];
    }
}
