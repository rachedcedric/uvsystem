<?php

namespace App\Livewire\Mobile\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Models\Activity;

#[Layout('components.layouts.mobile')]
#[Title('Journal d\'activité')]
class ActivityLog extends Component
{
    private const PAGE = 30;

    public const MODULES = [
        'all' => 'Tout',
        'uv_transactions' => 'Ventes',
        'remboursements' => 'Remboursements',
        'approvisionnements' => 'Approvisionnements',
        'agents' => 'Agents',
        'fournisseurs' => 'Fournisseurs',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'm', except: 'all')]
    public string $module = 'all';

    #[Url(as: 'u')]
    public ?int $userId = null;

    public int $limit = self::PAGE;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_activity_log'), 403);
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE;
    }

    public function updatedModule(): void
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

        return Activity::query()
            ->when($term !== '', fn (Builder $q) => $q->where('description', 'like', "%{$term}%"))
            ->when($this->module !== 'all', fn (Builder $q) => $q->where('log_name', $this->module))
            ->when($this->userId, fn (Builder $q) => $q->where('causer_id', $this->userId));
    }

    public function render()
    {
        $rows = $this->filtered()->with('causer')->latest('created_at')->latest('id')->limit($this->limit + 1)->get();

        return view('livewire.mobile.admin.activity-log', [
            'groups' => $rows->take($this->limit)->groupBy(fn (Activity $a) => $a->created_at->toDateString()),
            'hasMore' => $rows->count() > $this->limit,
            'count' => $this->filtered()->count(),
            'users' => User::orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
