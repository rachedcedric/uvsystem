<div>
    <x-m.appbar :title="$r::plural()" :back="route('m.more')" />

    @if ($r::searchColumns())
        <x-m.search wire:model.live.debounce.300ms="search" :placeholder="$r::searchPlaceholder()" />
    @endif
    @if ($r::filters())
        <x-m.chips model="filter" :current="$filter" :options="$r::filters()" />
    @endif

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'éléments' : 'élément' }}</span>
        @if ($summary) <strong>{{ $summary }}</strong> @endif
    </div>

    <div class="m-group" style="margin-top:12px">
        @forelse ($items as $item)
            <x-m.row :href="route('m.crud.show', [$resource, $item['id']])" wire:key="i{{ $item['id'] }}"
                     :icon="$item['icon'] ?? $r::icon()" :tone="$item['tone'] ?? null"
                     :title="$item['title']" :sub="$item['sub'] ?? null">
                @if (! empty($item['end']) || ! empty($item['badge']))
                    <x-slot:end>
                        @if (! empty($item['end']))
                            <span class="m-row__amount {{ $item['endClass'] ?? '' }}" style="display:block">{{ $item['end'] }}</span>
                        @endif
                        @if (! empty($item['badge']))
                            <span class="m-badge {{ ! empty($item['badgeTone']) ? 'm-badge--'.$item['badgeTone'] : '' }}">{{ $item['badge'] }}</span>
                        @endif
                    </x-slot:end>
                @endif
            </x-m.row>
        @empty
            <x-m.empty :icon="$r::icon()" :title="'Aucun élément'" text="Rien ne correspond à ces filtres." />
        @endforelse
    </div>

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.crud.create', $resource)" :label="'Ajouter'" />
    @endif
</div>
