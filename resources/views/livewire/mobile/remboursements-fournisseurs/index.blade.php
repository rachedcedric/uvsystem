<div>
    <x-m.appbar title="Paiements fournisseurs" :back="route('m.dettes', ['v' => 'fournisseurs'])" />

    <x-m.chips model="period" :current="$period" :options="['today' => 'Aujourd\'hui', 'week' => '7 jours', 'month' => 'Ce mois', 'all' => 'Tout']" />

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'paiements' : 'paiement' }}</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($total) }}</strong>
    </div>

    @forelse ($groups as $date => $items)
        <x-m.day :date="$date" :total="$items->sum('amount')" />
        <div class="m-group">
            @foreach ($items as $r)
                <x-m.row wire:key="rf{{ $r->id }}" icon="arrow-uturn-left" tone="ok" :chevron="false"
                         :title="$r->fournisseur?->name ?? 'Fournisseur supprimé'" :sub="$r->reference.' · '.$r->payment_date->format('H:i')">
                    <x-slot:end><span class="m-row__amount">−{{ \App\Support\Mobile\Fcfa::number($r->amount) }}</span></x-slot:end>
                </x-m.row>
            @endforeach
        </div>
    @empty
        <div class="m-group" style="margin-top:18px"><x-m.empty icon="arrow-uturn-left" title="Aucun paiement" text="Aucun paiement fournisseur sur cette période." /></div>
    @endforelse

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif
</div>
