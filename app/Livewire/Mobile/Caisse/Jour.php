<?php

namespace App\Livewire\Mobile\Caisse;

use App\Models\CaisseMouvement;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Journée de caisse')]
class Jour extends Component
{
    public string $date;

    public function mount(string $date): void
    {
        Gate::authorize('viewAny', CaisseMouvement::class);

        try {
            $this->date = Carbon::createFromFormat('Y-m-d', $date, CaissePeriodResolver::TIMEZONE)->toDateString();
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function render()
    {
        $service = app(CaisseService::class);
        $day = Carbon::parse($this->date, CaissePeriodResolver::TIMEZONE)->startOfDay();
        $running = $service->dayRunningBalances($day);

        return view('livewire.mobile.caisse.jour', [
            'day' => $day,
            'totals' => $service->dayTotals($day),
            'categories' => $service->categoryBalances($day, $day),
            'mouvements' => $service->mouvementsQuery($day)->with('categorie')->orderByDesc('date_mouvement')->orderByDesc('id')->get(),
            'running' => $running,
            'period' => app(CaissePeriodResolver::class)->bounds($day),
            'canCreate' => Gate::allows('create', CaisseMouvement::class),
        ]);
    }
}
