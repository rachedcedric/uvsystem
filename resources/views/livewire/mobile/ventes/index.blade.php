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

    <x-m.access-note />

    <x-m.filter-bar placeholder="Agent ou référence" :period="$period"
                    :periods="['today' => 'Aujourd\'hui', 'week' => '7 jours', 'month' => 'Ce mois', 'all' => 'Tout']"
                    :count="$count" noun="vente" nouns="ventes" :total="\App\Support\Mobile\Fcfa::format($total)" :active="$filtersActive" />

    <div wire:loading.class="m-dim" wire:target="search,period">
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
                            <x-m.amount class="m-row__amount" style="display:block" :value="$t->amount"
                                        :sign="$t->transaction_type === 'distribution' ? '−' : '+'" :cancelled="$cancelled" />
                            @if ($cancelled) <x-m.status-badge tone="danger" label="Annulée" /> @endif
                        </x-slot:end>
                    </x-m.row>
                @endforeach
            </div>
        @empty
            <div class="m-group" style="margin-top:18px">
                <x-m.empty icon="banknotes" :title="$filtersActive ? 'Aucune vente pour ces filtres' : 'Aucune vente'"
                           :text="$filtersActive ? 'Aucune vente ne correspond à ces filtres.' : 'Les ventes enregistrées apparaîtront ici.'">
                    @if ($filtersActive)
                        <div class="m-empty__actions">
                            <button type="button" class="m-btn m-btn--ghost" wire:click="resetFilters">Réinitialiser les filtres</button>
                        </div>
                    @endif
                </x-m.empty>
            </div>
        @endforelse
    </div>

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.ventes.create')" label="Nouvelle vente" />
    @endif
</div>
