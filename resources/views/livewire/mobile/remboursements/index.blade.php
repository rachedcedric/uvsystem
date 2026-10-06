<div wire:poll.60s.visible>
    <x-m.appbar title="Remboursements" />

    <x-m.period-dashboard module="remboursements" :summary="$stats" :yesterday="$yesterday" :daily="$daily" :trend="$trend" noun="remboursement" nouns="remboursements" :can-export="$canExport" />

    <x-m.search wire:model.live.debounce.300ms="search" placeholder="Agent ou référence" />
    <x-m.chips model="period" :current="$period"
               :options="['today' => 'Aujourd\'hui', 'week' => '7 jours', 'month' => 'Ce mois', 'all' => 'Tout']" />

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'paiements' : 'paiement' }}</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($total) }}</strong>
    </div>

    @forelse ($groups as $date => $items)
        <x-m.day :date="$date" :total="$dayTotals[$date] ?? 0" />
        <div class="m-group">
            @foreach ($items as $r)
                <x-m.row :href="route('m.remboursements.show', $r)" wire:key="r{{ $r->id }}" icon="arrow-uturn-left" tone="ok"
                         :title="$r->agent?->name ?? 'Agent supprimé'"
                         :sub="$r->reference.' · '.($methods[$r->payment_method] ?? $r->payment_method).' · '.$r->payment_date->format('H:i')">
                    <x-slot:end>
                        <span class="m-row__amount m-amount--in" style="display:block">+{{ \App\Support\Mobile\Fcfa::number($r->amount) }}</span>
                    </x-slot:end>
                </x-m.row>
            @endforeach
        </div>
    @empty
        <div class="m-group" style="margin-top:18px">
            <x-m.empty icon="arrow-uturn-left" title="Aucun remboursement" text="Aucun paiement ne correspond à ces filtres." />
        </div>
    @endforelse

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.remboursements.create')" label="Encaisser" />
    @endif
</div>
