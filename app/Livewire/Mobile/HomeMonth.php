<?php

namespace App\Livewire\Mobile;

use App\Services\Mobile\MonthOverview;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Widgets « mois en cours » du tableau de bord du bureau : Revenu / Charges / Revenu net,
 * Performance mensuelle et Évolution du CA. Chaque bloc suit le droit de lecture correspondant.
 */
#[Lazy]
class HomeMonth extends Component
{
    public function placeholder()
    {
        return view('livewire.mobile.placeholder-rows', ['rows' => 3]);
    }

    public function render()
    {
        $user = auth()->user();
        $overview = app(MonthOverview::class);
        $showCharges = $user->can('view_charges');
        $showStats = $user->can('view_statistics');

        return view('livewire.mobile.home-month', [
            'showCharges' => $showCharges,
            'showStats' => $showStats,
            'revenue' => $showCharges ? $overview->revenueCharges() : null,
            'performance' => $showStats ? $overview->performance() : [],
            'daily' => $showStats ? $overview->dailySales() : [],
        ]);
    }
}
