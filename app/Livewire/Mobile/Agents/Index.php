<?php

namespace App\Livewire\Mobile\Agents;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Agents')]
class Index extends Component
{
    private const PAGE = 40;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'f', except: 'all')]
    public string $filter = 'all';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        Gate::authorize('viewAny', Agent::class);
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE;
    }

    public function updatedFilter(): void
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

        return Agent::query()
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($term) {
                $w->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('zone', 'like', "%{$term}%");
            }))
            ->when($this->filter === 'debt', fn (Builder $q) => $q->active()->withDebt())
            ->when($this->filter === 'watch', fn (Builder $q) => $q->active()->where('debt_limit', '>', 0)->whereRaw('current_debt * 100 >= debt_limit * 70'))
            ->when($this->filter === 'inactive', fn (Builder $q) => $q->inactiveForDays())
            ->when($this->filter === 'all', fn (Builder $q) => $q->active());
    }

    public function render()
    {
        $query = $this->filtered();

        if (in_array($this->filter, ['debt', 'watch'], true)) {
            $query->orderByDesc('current_debt');
        } elseif ($this->filter === 'inactive') {
            // Date du dernier achat : les plus anciens (ou ceux qui n'ont jamais acheté) en premier.
            $query->withMax(['transactions as last_purchase_at' => fn (Builder $q) => $q->where('transaction_type', 'distribution')->where('status', '!=', 'cancelled')], 'transaction_date')
                ->orderBy('last_purchase_at')->orderBy('name');
        } else {
            $query->orderBy('name');
        }

        $rows = $query->limit($this->limit + 1)->get();

        return view('livewire.mobile.agents.index', [
            'agents' => $rows->take($this->limit),
            'hasMore' => $rows->count() > $this->limit,
            'count' => $this->filtered()->count(),
            'totalDebt' => (float) $this->filtered()->sum('current_debt'),
            'canCreate' => auth()->user()->can('create', Agent::class),
            'inactivityDays' => Agent::INACTIVITY_DAYS,
        ]);
    }
}
