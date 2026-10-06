<div wire:poll.30s.visible>
    <x-m.appbar title="Notifications" :sub="$unread > 0 ? $unread.' non '.($unread > 1 ? 'lues' : 'lue') : null" :back="route('m.home')">
        @if ($unread > 0)
            <button type="button" class="m-iconbtn" wire:click="markAllRead" aria-label="Tout marquer comme lu" data-haptic>
                <x-m.icon name="check" />
            </button>
        @endif
    </x-m.appbar>

    <div class="m-group" style="margin-top:6px">
        @forelse ($items as $n)
            @php
                $data = $n->data;
                $isRead = $n->read_at !== null;
                $color = $data['color'] ?? $data['status'] ?? null;
                $tone = match ($color) { 'danger' => 'danger', 'warning' => 'warn', 'success' => 'ok', default => null };
                $icon = str_starts_with((string) ($data['icon'] ?? ''), 'heroicon-') ? preg_replace('/^heroicon-[a-z]-/', '', $data['icon']) : 'bell';
            @endphp
            <button type="button" class="m-row" wire:key="n{{ $n->id }}" wire:click="open('{{ $n->id }}')" style="{{ $isRead ? '' : 'background:var(--brand-soft)' }}">
                <span class="m-row__lead {{ $tone ? 'm-row__lead--'.$tone : '' }}"><x-m.icon :name="$icon" class="m-ico-sm" /></span>
                <span class="m-row__body">
                    <span class="m-row__title" style="display:block;white-space:normal;{{ $isRead ? 'font-weight:500' : '' }}">{{ $data['title'] ?? 'Notification' }}</span>
                    @if (! empty($data['body']))
                        <span class="m-row__sub" style="display:block;white-space:normal">{{ \Illuminate\Support\Str::limit(strip_tags($data['body']), 140) }}</span>
                    @endif
                    <span class="m-row__sub" style="display:block;margin-top:2px">{{ $n->created_at->diffForHumans() }}</span>
                </span>
            </button>
        @empty
            <x-m.empty icon="bell" title="Aucune notification" text="Les rappels de dette et les alertes apparaîtront ici." />
        @endforelse
    </div>

    @if ($hasMore)
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif
</div>
