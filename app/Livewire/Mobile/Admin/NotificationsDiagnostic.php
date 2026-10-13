<?php

namespace App\Livewire\Mobile\Admin;

use App\Models\PushToken;
use App\Notifications\TestNotification;
use App\Services\FirebaseMessaging;
use App\Services\NotificationDiagnostics;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Diagnostic des notifications : état de chaque maillon + tests de bout en bout. */
#[Layout('components.layouts.mobile')]
#[Title('Diagnostic des notifications')]
class NotificationsDiagnostic extends Component
{
    /** @var list<array{ok:bool, text:string}> */
    public array $results = [];

    public function mount(): void
    {
        abort_unless(self::allowed(), 403);
    }

    public static function allowed(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['master', 'admin']);
    }

    public function sendTest(): void
    {
        abort_unless(self::allowed(), 403);
        $user = auth()->user();
        $this->results = [];

        // 1. Cloche (base de données) : doit apparaître dans « Notifications » et déclencher le son.
        $user->notifyNow(new TestNotification(push: false));
        $this->results[] = ['ok' => true, 'text' => 'Notification ajoutée à la cloche.'];

        // 2. Push : un envoi par appareil, avec le résultat de chacun.
        $devices = PushToken::where('user_id', $user->id)->get();
        if ($devices->isEmpty()) {
            $this->results[] = ['ok' => false, 'text' => 'Aucun appareil enregistré pour votre compte : push non testé.'];

            return;
        }

        $payload = (new TestNotification)->pushPayload();
        foreach ($devices as $device) {
            try {
                app(FirebaseMessaging::class)->send($device, $payload);
                $this->results[] = ['ok' => true, 'text' => 'Push envoyé à l\'appareil #'.$device->id.' (arrivée sur le téléphone sous quelques secondes).'];
            } catch (\Throwable $e) {
                $this->results[] = ['ok' => false, 'text' => 'Push refusé pour l\'appareil #'.$device->id.' : '.Str::limit($e->getMessage(), 160)];
            }
        }
    }

    public function sendTestMail(): void
    {
        abort_unless(self::allowed(), 403);
        $user = auth()->user();

        try {
            Mail::raw('Ceci est un e-mail de test envoyé depuis '.config('branding.name').".\nS'il arrive dans votre boîte, l'envoi d'e-mails fonctionne.", function ($message) use ($user) {
                $message->to($user->email)->subject('Test e-mail — '.config('branding.name'));
            });
            $this->results = [['ok' => true, 'text' => 'E-mail de test envoyé à '.$user->email.'. Vérifiez votre boîte (et les courriers indésirables).']];
        } catch (\Throwable $e) {
            $this->results = [['ok' => false, 'text' => 'Envoi impossible : '.Str::limit($e->getMessage(), 200)]];
        }
    }

    public function render()
    {
        $checks = app(NotificationDiagnostics::class)->checks(auth()->user());

        return view('livewire.mobile.admin.notifications-diagnostic', [
            'checks' => $checks,
            'summary' => app(NotificationDiagnostics::class)->summary($checks),
        ]);
    }
}
