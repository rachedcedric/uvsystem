<div wire:poll.60s.visible>
    <x-m.appbar title="Ventes UV" />

    <x-m.period-stats module="ventes" :summary="$stats" noun="vente" nouns="ventes" :can-export="$canExport" />

    @if ($topToday->isNotEmpty() && trim($search) === '')
        @php($topMax = max(1, $topToday->max('amount')))
        <x-m.section title="Top 5 des agents · aujourd'hui">
            <div class="m-group">
                @foreach ($topToday as $i => $top)
                    <x-m.row :href="$top['id'] ? route('m.agents.show', $top['id']) : null" :lead="$i + 1" :title="$top['name']" wire:key="top{{ $i }}">
                        <x-slot:body><span class="m-meter" style="display:block"><i style="width: {{ round($top['amount'] / $topMax * 100) }}%"></i></span></x-slot:body>
                        <x-slot:end><span class="m-row__amount">{{ \App\Support\Mobile\Fcfa::format($top['amount']) }}</span></x-slot:end>
                    </x-m.row>
                @endforeach
            </div>
        </x-m.section>
    @endif

    <x-m.search wire:model.live.debounce.300ms="search" placeholder="Agent ou référence" />
    <x-m.chips model="period" :current="$period"
               :options="['today' => 'Aujourd\'hui', 'week' => '7 jours', 'month' => 'Ce mois', 'all' => 'Tout']" />

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'ventes' : 'vente' }}</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($total) }}</strong>
    </div>

    @forelse ($groups as $date => $items)
        <x-m.day :date="$date" :total="$dayTotals[$date] ?? 0" />
        <div class="m-group">
            @foreach ($items as $t)
                @php($cancelled = $t->status === 'cancelled')
                <x-m.row :href="route('m.ventes.show', $t)" wire:key="v{{ $t->id }}"
                         :icon="$t->transaction_type === 'distribution' ? 'arrow-up-right' : 'arrow-down-left'"
                         :tone="$cancelled ? 'muted' : ($t->transaction_type === 'distribution' ? null : 'ok')"
                         :title="$t->agent?->name ?? 'Agent supprimé'"
                         :sub="$t->reference.' · '.$t->transaction_date->format('H:i')">
                    <x-slot:end>
                        <span class="m-row__amount" style="display:block;{{ $cancelled ? 'text-decoration:line-through;color:var(--fg-3)' : '' }}">
                            {{ $t->transaction_type === 'distribution' ? '−' : '+' }}{{ \App\Support\Mobile\Fcfa::number($t->amount) }}
                        </span>
                        @if ($cancelled) <span class="m-badge m-badge--danger">Annulée</span> @endif
                    </x-slot:end>
                </x-m.row>
            @endforeach
        </div>
    @empty
        <div class="m-group" style="margin-top:18px">
            <x-m.empty icon="banknotes" title="Aucune vente" text="Aucune vente ne correspond à ces filtres." />
        </div>
    @endforelse

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.ventes.create')" label="Nouvelle vente" />
    @endif
</div>
