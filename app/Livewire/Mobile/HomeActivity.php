<?php

namespace App\Livewire\Mobile;

use App\Services\Mobile\HomeDashboard;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class HomeActivity extends Component
{
    public function placeholder()
    {
        return view('livewire.mobile.placeholder-rows', ['rows' => 4]);
    }

    public function render()
    {
        return view('livewire.mobile.home-activity', [
            'items' => app(HomeDashboard::class)->recentActivity(),
        ]);
    }
}
