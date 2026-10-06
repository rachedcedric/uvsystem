<?php

namespace App\Livewire\Mobile;

use App\Services\NotificationService;
use Livewire\Component;

/**
 * Surveille les nouvelles notifications (cloche) toutes les 30 secondes, sur
 * tous les écrans : joue le son d'alerte et affiche une bannière quand une
 * nouvelle notification arrive, et pose le badge sur l'onglet « Plus ».
 *
 * Pour les comptes master/admin, relance aussi les rappels de dette du cycle
 * en cours (filet de sécurité si le cron de l'hébergeur ne tourne pas ; une
 * seule exécution par cycle de 2 h grâce au verrou du service).
 */
class NotificationWatcher extends Component
{
    /** @var list<string> Identifiants des notifications non lues déjà connues. */
    public array $known = [];

    public function mount(): void
    {
        $this->known = $this->unreadIds();
    }

    /** @return list<string> */
    private function unreadIds(): array
    {
        return auth()->user()?->unreadNotifications()->latest()->limit(50)->pluck('id')->all() ?? [];
    }

    public function check(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        if ($user->hasAnyRole(['master', 'admin'])) {
            try {
                app(NotificationService::class)->checkAndSendDebtRemindersOncePerCycle();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $unread = $user->unreadNotifications()->latest()->limit(50)->get();
        $new = $unread->reject(fn ($n) => in_array($n->id, $this->known, true));
        $this->known = $unread->pluck('id')->all();

        if ($new->isNotEmpty()) {
            $first = $new->first();
            $data = $first->data;

            $this->dispatch('m-notify',
                count: $new->count(),
                title: (string) ($data['title'] ?? 'Nouvelle notification'),
                url: route('m.notifications'),
            );
        }
    }

    public function render()
    {
        $count = auth()->user()?->unreadNotifications()->count() ?? 0;

        return view('livewire.mobile.notification-watcher', ['count' => $count]);
    }
}
