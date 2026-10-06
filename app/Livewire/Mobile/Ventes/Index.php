<?php

namespace App\Livewire\Mobile\Ventes;

use App\Models\UvTransaction;
use App\Services\Mobile\MonthOverview;
use App\Services\Mobile\PeriodStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Ventes')]
class Index extends Component
{
    private const PAGE = 30;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'p', except: 'week')]
    public string $period = 'week';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        Gate::authorize('viewAny', UvTransaction::class);
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE;
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
        $term = trim($this->search);

        return UvTransaction::query()
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($term) {
                $w->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('agent', fn (Builder $a) => $a->where('name', 'like', "%{$term}%"));
            }))
            ->when($this->period === 'today', fn (Builder $q) => $q->whereDate('transaction_date', today()))
            ->when($this->period === 'week', fn (Builder $q) => $q->where('transaction_date', '>=', today()->subDays(6)))
            ->when($this->period === 'month', fn (Builder $q) => $q->where('transaction_date', '>=', today()->startOfMonth()));
    }

    public function render()
    {
        $rows = $this->filtered()
            ->with('agent:id,name')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $hasMore = $rows->count() > $this->limit;
        $items = $rows->take($this->limit);

        // Total par jour : calculé en base pour les jours affichés, pas seulement
        // sur les lignes chargées (le dernier jour peut être coupé par la pagination).
        $dayTotals = $this->filtered()
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DATE(transaction_date) as jour, SUM(amount) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $summary = $this->filtered()
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')
            ->first();

        return view('livewire.mobile.ventes.index', [
            'groups' => $items->groupBy(fn (UvTransaction $t) => $t->transaction_date->toDateString()),
            'dayTotals' => $dayTotals,
            'hasMore' => $hasMore,
            'count' => (int) $summary->n,
            'total' => (float) $summary->total,
            'canCreate' => auth()->user()->can('create', UvTransaction::class),
            'stats' => app(PeriodStats::class)->summary('ventes'),
            'topToday' => app(MonthOverview::class)->topAgentsToday(),
            'canExport' => auth()->user()->can('export_uv_transactions'),
        ]);
    }
}
