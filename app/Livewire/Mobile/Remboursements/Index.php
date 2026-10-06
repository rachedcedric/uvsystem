<?php

namespace App\Livewire\Mobile\Remboursements;

use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Services\Mobile\PeriodStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Remboursements')]
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
        Gate::authorize('viewAny', Remboursement::class);
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

        return Remboursement::query()
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($term) {
                $w->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('agent', fn (Builder $a) => $a->where('name', 'like', "%{$term}%"));
            }))
            ->when($this->period === 'today', fn (Builder $q) => $q->whereDate('payment_date', today()))
            ->when($this->period === 'week', fn (Builder $q) => $q->where('payment_date', '>=', today()->subDays(6)))
            ->when($this->period === 'month', fn (Builder $q) => $q->where('payment_date', '>=', today()->startOfMonth()));
    }

    public function render()
    {
        $rows = $this->filtered()
            ->with('agent:id,name')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $dayTotals = $this->filtered()
            ->selectRaw('DATE(payment_date) as jour, SUM(amount) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $summary = $this->filtered()->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->first();

        return view('livewire.mobile.remboursements.index', [
            'groups' => $rows->take($this->limit)->groupBy(fn (Remboursement $r) => $r->payment_date->toDateString()),
            'dayTotals' => $dayTotals,
            'hasMore' => $rows->count() > $this->limit,
            'count' => (int) $summary->n,
            'total' => (float) $summary->total,
            'methods' => PaymentMethod::pluck('name', 'code'),
            'canCreate' => auth()->user()->can('create', Remboursement::class),
            'stats' => app(PeriodStats::class)->summary('remboursements'),
            'yesterday' => app(PeriodStats::class)->yesterday('remboursements'),
            'daily' => app(PeriodStats::class)->monthDaily('remboursements'),
            'trend' => app(PeriodStats::class)->weekTrend('remboursements'),
            'canExport' => auth()->user()->can('export_remboursements'),
        ]);
    }
}
