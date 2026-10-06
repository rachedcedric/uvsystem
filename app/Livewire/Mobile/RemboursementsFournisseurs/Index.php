<?php

namespace App\Livewire\Mobile\RemboursementsFournisseurs;

use App\Models\RemboursementFournisseur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Paiements fournisseurs')]
class Index extends Component
{
    private const PAGE = 30;

    #[Url(as: 'p', except: 'month')]
    public string $period = 'month';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        Gate::authorize('viewAny', RemboursementFournisseur::class);
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
        return RemboursementFournisseur::query()
            ->when($this->period === 'today', fn (Builder $q) => $q->whereDate('payment_date', today()))
            ->when($this->period === 'week', fn (Builder $q) => $q->where('payment_date', '>=', today()->subDays(6)))
            ->when($this->period === 'month', fn (Builder $q) => $q->where('payment_date', '>=', today()->startOfMonth()));
    }

    public function render()
    {
        $rows = $this->filtered()->with('fournisseur:id,name')->orderByDesc('payment_date')->orderByDesc('id')->limit($this->limit + 1)->get();
        $summary = $this->filtered()->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->first();

        return view('livewire.mobile.remboursements-fournisseurs.index', [
            'groups' => $rows->take($this->limit)->groupBy(fn ($r) => $r->payment_date->toDateString()),
            'hasMore' => $rows->count() > $this->limit,
            'count' => (int) $summary->n,
            'total' => (float) $summary->total,
            'canCreate' => auth()->user()->can('create', RemboursementFournisseur::class),
        ]);
    }
}
