<?php

namespace App\Livewire\Mobile;

use App\Services\Mobile\HomeDashboard;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class HomeChart extends Component
{
    public function placeholder()
    {
        return view('livewire.mobile.placeholder-rows', ['rows' => 0]);
    }

    public function render()
    {
        return view('livewire.mobile.home-chart', [
            'days' => app(HomeDashboard::class)->dailyRevenue(),
        ]);
    }
}
