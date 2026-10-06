<?php

namespace App\Livewire\Mobile\Analytics;

use App\Models\Agent;
use App\Services\AnalyticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Score de l\'agent')]
class ScoringAgent extends Component
{
    public Agent $agent;

    public function mount(Agent $agent): void
    {
        abort_unless(auth()->user()->can('view_statistics') || auth()->user()->can('view_agents'), 403);

        $this->agent = $agent;
    }

    public function render()
    {
        return view('livewire.mobile.analytics.scoring-agent', [
            'scoring' => app(AnalyticsService::class)->getAgentScoring()->firstWhere('id', $this->agent->id),
            'criteria' => [
                ['key' => 'volume', 'label' => 'Volume', 'max' => 25, 'desc' => 'Transactions des 3 derniers mois'],
                ['key' => 'regularite', 'label' => 'Régularité', 'max' => 20, 'desc' => 'Mois actifs sur 6 mois'],
                ['key' => 'paiement', 'label' => 'Paiement', 'max' => 30, 'desc' => 'Ratio remboursé / acheté'],
                ['key' => 'dette', 'label' => 'Dette', 'max' => 25, 'desc' => 'Utilisation du plafond'],
            ],
        ]);
    }
}
