<div wire:poll.60s.visible>
    <x-m.appbar title="Journal d'activité" :back="route('m.more')" />

    <x-m.search wire:model.live.debounce.300ms="search" placeholder="Rechercher une action" />
    <x-m.chips model="module" :current="$module" :options="\App\Livewire\Mobile\Admin\ActivityLog::MODULES" />

    <div class="m-summary"><span>{{ $count }} {{ $count > 1 ? 'actions' : 'action' }}</span>
        @if ($userId)<button type="button" class="m-section__link" wire:click="$set('userId', null)">Tous les utilisateurs</button>@endif</div>

    @forelse ($groups as $date => $items)
        <x-m.day :date="$date" />
        <div class="m-group">
            @foreach ($items as $a)
                <x-m.row :href="route('m.activite.show', $a)" wire:key="a{{ $a->id }}" icon="clipboard-document-list" tone="muted"
                         :title="$a->description" :sub="($a->causer?->name ?? 'Système').' · '.$a->created_at->format('H:i')">
                    <x-slot:end>@if ($a->log_name && $a->log_name !== 'default')<span class="m-badge m-badge--brand">{{ \App\Livewire\Mobile\Admin\ActivityLog::MODULES[$a->log_name] ?? $a->log_name }}</span>@endif</x-slot:end>
                </x-m.row>
            @endforeach
        </div>
    @empty
        <div class="m-group" style="margin-top:18px"><x-m.empty icon="clipboard-document-list" title="Aucune activité" text="Aucune action ne correspond à ces filtres." /></div>
    @endforelse

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif
</div>
