<div>
    <x-m.appbar title="Agents" />

    <x-m.search wire:model.live.debounce.300ms="search" placeholder="Nom, téléphone ou zone" />
    <x-m.chips model="filter" :current="$filter"
               :options="['all' => 'Actifs', 'debt' => 'Avec dette', 'watch' => 'À surveiller', 'inactive' => 'Inactifs']" />

    @if ($filter === 'inactive')
        <p style="margin:10px 20px 0;font-size:13px;color:var(--fg-3)">Agents sans aucun achat depuis {{ $inactivityDays }} jours ou plus.</p>
    @endif

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'agents' : 'agent' }}</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($totalDebt) }} <span style="font-size:12px;font-weight:500;color:var(--fg-3)">de dette</span></strong>
    </div>

    <div class="m-group" style="margin-top:12px">
        @forelse ($agents as $agent)
            @php
                $ratio = $agent->debt_limit > 0 ? ($agent->current_debt / $agent->debt_limit) * 100 : 0;
                $level = $ratio >= 100 ? 'critical' : ($ratio >= 90 ? 'high' : ($ratio >= 70 ? 'watch' : 'ok'));
            @endphp
            <x-m.row :href="route('m.agents.show', $agent)" wire:key="a{{ $agent->id }}"
                     :lead="mb_strtoupper(mb_substr($agent->name, 0, 2))"
                     :tone="$agent->status !== 'active' ? 'muted' : ($level === 'critical' ? 'danger' : (in_array($level, ['high', 'watch']) ? 'warn' : null))"
                     :title="$agent->name"
                     :sub="collect([$agent->zone, $agent->phone])->filter()->implode(' · ') ?: '—'">
                @if ($agent->debt_limit > 0 && $agent->current_debt > 0)
                    <x-slot:body>
                        <span class="m-meter m-meter--{{ $level }}" style="display:block"><i style="width: {{ min(100, round($ratio)) }}%"></i></span>
                    </x-slot:body>
                @endif
                <x-slot:end>
                    <span class="m-row__amount" style="display:block;{{ $agent->current_debt > 0 ? '' : 'color:var(--fg-3);font-weight:500' }}">
                        {{ $agent->current_debt > 0 ? \App\Support\Mobile\Fcfa::format($agent->current_debt) : 'Aucune dette' }}
                    </span>
                    @if ($filter === 'inactive')
                        @php
                            $last = $agent->last_purchase_at ? \Carbon\Carbon::parse($agent->last_purchase_at)->startOfDay() : null;
                            $daysAgo = $last ? (int) $last->diffInDays(today(), true) : null;
                        @endphp
                        <span class="m-badge m-badge--warn">{{ $daysAgo === null ? 'Aucun achat' : 'Dernier achat il y a '.$daysAgo.' j' }}</span>
                        @if ($agent->status !== 'active') <span class="m-badge">{{ $agent->status === 'suspended' ? 'Suspendu' : 'Désactivé' }}</span> @endif
                    @elseif ($agent->status !== 'active') <span class="m-badge">Inactif</span> @endif
                </x-slot:end>
            </x-m.row>
        @empty
            @if ($filter === 'inactive' && trim($search) === '')
                <x-m.empty icon="users" title="Aucun agent inactif" text="Tous les agents ont acheté ces {{ $inactivityDays }} derniers jours." />
            @else
                <x-m.empty icon="users" title="Aucun agent" text="Aucun agent ne correspond à ces filtres." />
            @endif
        @endforelse
    </div>

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.crud.create', 'agents')" label="Nouvel agent" />
    @endif
</div>
