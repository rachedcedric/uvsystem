<?php

namespace App\Livewire\Mobile;

use App\Services\Mobile\HomeDashboard;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class HomeTopAgents extends Component
{
    public function placeholder()
    {
        return view('livewire.mobile.placeholder-rows', ['rows' => 3]);
    }

    public function render()
    {
        return view('livewire.mobile.home-top-agents', [
            'agents' => app(HomeDashboard::class)->topAgentsOfMonth(),
        ]);
    }
}
