<?php

namespace App\Livewire\Mobile\Charges;

use App\Models\Charge;
use App\Support\Mobile\ChargePeriods;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Charges')]
class Index extends Component
{
    private const PAGE = 30;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'p', except: 'month')]
    public string $period = 'month';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        Gate::authorize('viewAny', Charge::class);
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

        return Charge::query()
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($term) {
                $w->where('libelle', 'like', "%{$term}%")
                    ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"));
            }))
            ->when($this->period === 'today', fn (Builder $q) => $q->whereDate('date_charge', today()))
            ->when($this->period === 'week', fn (Builder $q) => $q->where('date_charge', '>=', today()->subDays(6)))
            ->when($this->period === 'month', fn (Builder $q) => $q->where('date_charge', '>=', today()->startOfMonth()));
    }

    public function render()
    {
        $rows = $this->filtered()
            ->with('category:id,name')
            ->orderByDesc('date_charge')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $summary = $this->filtered()->selectRaw('COUNT(*) as n, COALESCE(SUM(montant), 0) as total')->first();

        // Cartes Revenu / Charges / Net : mêmes bornes que le filtre de la liste (voir ChargePeriods pour les exports).
        $cardPeriod = match ($this->period) {
            'today' => 'today',
            'week' => 'week',
            'all' => 'all',
            default => 'month',
        };
        $stats = ChargePeriods::summary($cardPeriod);

        $exportSummary = [];
        foreach (ChargePeriods::LABELS as $key => $label) {
            $range = ChargePeriods::summary($key);
            $row = Charge::query()->forPeriod($range['start'], $range['end'])->selectRaw('COUNT(*) as n, COALESCE(SUM(montant), 0) as total')->first();
            $exportSummary[$key] = ['label' => $label, 'count' => (int) $row->n, 'amount' => (float) $row->total];
        }

        return view('livewire.mobile.charges.index', [
            'stats' => $stats,
            'exportSummary' => $exportSummary,
            'canExport' => auth()->user()->can('export_charges'),
            'groups' => $rows->take($this->limit)->groupBy(fn (Charge $c) => $c->date_charge->toDateString()),
            'hasMore' => $rows->count() > $this->limit,
            'count' => (int) $summary->n,
            'total' => (float) $summary->total,
            'canCreate' => auth()->user()->can('create', Charge::class),
        ]);
    }
}
