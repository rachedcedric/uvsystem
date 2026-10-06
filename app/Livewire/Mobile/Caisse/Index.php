<?php

namespace App\Livewire\Mobile\Caisse;

use App\Models\CaisseMouvement;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Caisse')]
class Index extends Component
{
    public int $days = 14;

    public function mount(): void
    {
        Gate::authorize('viewAny', CaisseMouvement::class);
    }

    public function loadMore(): void
    {
        $this->days += 14;
    }

    public function render()
    {
        $service = app(CaisseService::class);
        $periods = app(CaissePeriodResolver::class);
        $today = $periods->currentDate();

        $summaries = $service->daySummaries($today->copy()->subDays($this->days - 1), $today);

        return view('livewire.mobile.caisse.index', [
            'solde' => $service->soldeActuel(),
            'today' => $service->dayTotals($today),
            'todayDate' => $today,
            'summaries' => $summaries,
            'canCreate' => auth()->user()->can('create', CaisseMouvement::class),
            'hasMore' => CaisseMouvement::query()->where('date_mouvement', '<', $periods->bounds($today->copy()->subDays($this->days - 1))['start'])->exists(),
        ]);
    }
}
