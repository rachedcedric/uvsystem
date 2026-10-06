<?php

namespace App\Livewire\Mobile\Dettes;

use App\Filament\Pages\AgentDebtDetailPage;
use App\Models\Agent;
use App\Services\Mobile\AgentHistory;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Détail de la dette')]
class AgentDetail extends Component
{
    public Agent $agent;

    public int $limit = 20;

    public function mount(Agent $agent): void
    {
        abort_unless(auth()->user()->can('view_remboursements'), 403);
        Gate::authorize('view', $agent);

        $this->agent = $agent;
    }

    public function loadMore(): void
    {
        $this->limit += 20;
    }

    public function render()
    {
        $agent = $this->agent->fresh(['solde']);
        $total = AgentHistory::count($agent);

        return view('livewire.mobile.dettes.agent-detail', [
            'a' => $agent,
            'summary' => AgentDebtDetailPage::summaryFor($agent),
            'history' => AgentHistory::latest($agent, $this->limit),
            'hasMore' => $total > $this->limit,
            'canCollect' => $agent->current_debt > 0 && auth()->user()->can('create_remboursements'),
        ]);
    }
}
