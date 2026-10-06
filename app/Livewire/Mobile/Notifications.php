<?php

namespace App\Livewire\Mobile;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Notifications de la cloche (notifications Filament stockées en base,
 * les mêmes que dans le panneau complet : rappels de dette, exports...).
 */
#[Layout('components.layouts.mobile')]
#[Title('Notifications')]
class Notifications extends Component
{
    public int $limit = 30;

    public function loadMore(): void
    {
        $this->limit += 30;
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function open(string $id)
    {
        $notification = auth()->user()->notifications()->whereKey($id)->first();

        if (! $notification) {
            return null;
        }

        $notification->markAsRead();
        $url = collect($notification->data['actions'] ?? [])->pluck('url')->filter()->first();

        return $url ? redirect()->to(\App\Support\Mobile\AdminUrl::toMobile($url)) : null;
    }

    public function render()
    {
        $user = auth()->user();
        $rows = $user->notifications()->limit($this->limit + 1)->get();

        return view('livewire.mobile.notifications', [
            'items' => $rows->take($this->limit),
            'hasMore' => $rows->count() > $this->limit,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }
}
