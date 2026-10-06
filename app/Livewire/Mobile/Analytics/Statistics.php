<?php

namespace App\Livewire\Mobile\Analytics;

use App\Models\Agent;
use App\Services\Mobile\StatisticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Statistiques')]
class Statistics extends Component
{
    #[Url(as: 'p', except: 'month')]
    public string $period = 'month';

    #[Url(as: 'agent')]
    public ?int $agentId = null;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_statistics') || auth()->user()->can('view_dashboard'), 403);

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function clearAgent(): void
    {
        $this->agentId = null;
    }

    public function render()
    {
        [$from, $to] = StatisticsService::range($this->period, $this->from, $this->to);
        $service = new StatisticsService($from, $to, $this->agentId);
        $series = $service->series();

        return view('livewire.mobile.analytics.statistics', [
            'range' => [$service->from, $service->to],
            'overview' => $service->overview(),
            'trends' => $service->trends(),
            'series' => $series,
            'maxSeries' => max(1, collect($series)->max(fn ($p) => max($p['ca'], $p['rembourse']))),
            'topAgents' => $service->topAgents(),
            'modes' => $service->paymentModes(),
            'debt' => $service->debt(),
            'debtors' => $service->worstDebtors(),
            'agents' => Agent::orderBy('name')->get(['id', 'name', 'phone', 'current_debt']),
            'selectedAgent' => $this->agentId ? Agent::find($this->agentId) : null,
        ]);
    }
}
