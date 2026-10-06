<?php

namespace App\Livewire\Mobile\Analytics;

use App\Models\Agent;
use App\Models\PaymentMethod;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Rapports & exports : choix des filtres puis téléchargement (PDF / Excel). */
#[Layout('components.layouts.mobile')]
#[Title('Rapports et exports')]
class Reports extends Component
{
    public string $report = 'transactions';

    // Transactions / remboursements
    public ?int $agentId = null;

    public string $from = '';

    public string $to = '';

    public string $type = '';

    public string $method = '';

    // Situation des agents
    public string $status = '';

    public bool $withDebtOnly = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_reports') || auth()->user()->can('export_reports'), 403);

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function render()
    {
        $params = array_filter([
            'agent' => $this->agentId,
            'from' => $this->from,
            'to' => $this->to,
            'type' => $this->report === 'transactions' ? $this->type : null,
            'method' => $this->report === 'remboursements' ? $this->method : null,
            'status' => $this->report === 'agents' ? $this->status : null,
            'debt' => $this->report === 'agents' && $this->withDebtOnly ? 1 : null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($this->report === 'agents') {
            unset($params['agent'], $params['from'], $params['to']);
        }

        return view('livewire.mobile.analytics.reports', [
            'agents' => Agent::orderBy('name')->get(['id', 'name', 'phone', 'current_debt']),
            'selectedAgent' => $this->agentId ? Agent::find($this->agentId) : null,
            'methods' => PaymentMethod::getActiveOptions(),
            'params' => $params,
            'canExport' => auth()->user()->can('export_reports'),
        ]);
    }
}
