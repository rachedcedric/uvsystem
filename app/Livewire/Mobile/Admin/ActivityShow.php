<?php

namespace App\Livewire\Mobile\Admin;

use App\Models\Activity;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Détail de l\'activité')]
class ActivityShow extends Component
{
    public Activity $activity;

    public function mount(Activity $activity): void
    {
        abort_unless(auth()->user()->can('view_activity_log'), 403);

        $this->activity = $activity->load('causer');
    }

    public function render()
    {
        $props = $this->activity->properties?->toArray() ?? [];
        $changes = [];
        foreach (($props['attributes'] ?? []) as $key => $new) {
            $old = $props['old'][$key] ?? null;
            if (array_key_exists('old', $props) && $old == $new) {
                continue;
            }
            $changes[$key] = ['old' => $old, 'new' => $new];
        }

        return view('livewire.mobile.admin.activity-show', [
            'a' => $this->activity,
            'changes' => $changes,
            'extra' => collect($props)->except(['attributes', 'old'])->all(),
        ]);
    }
}
