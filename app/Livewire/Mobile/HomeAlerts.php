<?php

namespace App\Livewire\Mobile;

use App\Services\Mobile\HomeDashboard;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class HomeAlerts extends Component
{
    public function placeholder()
    {
        return view('livewire.mobile.placeholder-rows', ['rows' => 2]);
    }

    public function render()
    {
        return view('livewire.mobile.home-alerts', [
            'alerts' => app(HomeDashboard::class)->debtAlerts(),
        ]);
    }
}
