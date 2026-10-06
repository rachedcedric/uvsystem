<?php

namespace App\Livewire\Mobile\Approvisionnements;

use App\Models\Approvisionnement;
use App\Services\Mobile\PeriodStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Approvisionnements')]
class Index extends Component
{
    private const PAGE = 30;

    #[Url(as: 'p', except: 'month')]
    public string $period = 'month';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        Gate::authorize('viewAny', Approvisionnement::class);
    }

    public function updatedPeriod(): void
    {
        $this->limit = self::PAGE;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    private function filtered(): Builder
    {
        return Approvisionnement::query()
            ->when($this->period === 'today', fn (Builder $q) => $q->whereDate('date_approvisionnement', today()))
            ->when($this->period === 'week', fn (Builder $q) => $q->where('date_approvisionnement', '>=', today()->subDays(6)))
            ->when($this->period === 'month', fn (Builder $q) => $q->where('date_approvisionnement', '>=', today()->startOfMonth()));
    }

    public function render()
    {
        $rows = $this->filtered()
            ->with('fournisseur:id,name')
            ->orderByDesc('date_approvisionnement')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $summary = $this->filtered()->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->first();

        return view('livewire.mobile.approvisionnements.index', [
            'groups' => $rows->take($this->limit)->groupBy(fn (Approvisionnement $a) => \Carbon\Carbon::parse($a->date_approvisionnement)->toDateString()),
            'hasMore' => $rows->count() > $this->limit,
            'count' => (int) $summary->n,
            'total' => (float) $summary->total,
            'canCreate' => auth()->user()->can('create', Approvisionnement::class),
            'stats' => app(PeriodStats::class)->summary('approvisionnements'),
            'yesterday' => app(PeriodStats::class)->yesterday('approvisionnements'),
            'daily' => app(PeriodStats::class)->monthDaily('approvisionnements'),
            'trend' => app(PeriodStats::class)->weekTrend('approvisionnements'),
            'canExport' => auth()->user()->can('view_approvisionnements'),
        ]);
    }
}
