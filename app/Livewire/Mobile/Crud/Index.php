<?php

namespace App\Livewire\Mobile\Crud;

use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Crud\Registry;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
class Index extends Component
{
    private const PAGE = 30;

    public string $resource;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'f', except: 'all')]
    public string $filter = 'all';

    public int $limit = self::PAGE;

    /** @return class-string<MobileResource> */
    private function res(): string
    {
        return Registry::find($this->resource) ?? abort(404);
    }

    public function mount(string $resource): void
    {
        $this->resource = $resource;

        abort_unless($this->res()::can(auth()->user(), 'viewAny'), 403);
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
        $r = $this->res();
        $query = $r::query();
        $term = trim($this->search);

        if ($term !== '' && $r::searchColumns() !== []) {
            $query->where(function (Builder $w) use ($r, $term) {
                foreach ($r::searchColumns() as $column) {
                    $w->orWhere($column, 'like', "%{$term}%");
                }
            });
        }

        if ($this->filter !== 'all' && array_key_exists($this->filter, $r::filters())) {
            $query = $r::applyFilter($query, $this->filter);
        }

        return $query;
    }

    public function render()
    {
        $r = $this->res();
        $rows = $r::order($this->filtered())->limit($this->limit + 1)->get();

        return view('livewire.mobile.crud.index', [
            'r' => $r,
            'items' => $rows->take($this->limit)->map(fn ($m) => ['id' => $m->getKey()] + $r::row($m)),
            'hasMore' => $rows->count() > $this->limit,
            'count' => $this->filtered()->count(),
            'summary' => $r::summary($this->filtered()),
            'canCreate' => $r::can(auth()->user(), 'create'),
        ])->title($r::plural());
    }
}
