<div>
    <x-m.appbar title="Paiements fournisseurs" :back="route('m.dettes', ['v' => 'fournisseurs'])" />

    <x-m.chips model="period" :current="$period" :options="['today' => 'Aujourd\'hui', 'week' => '7 jours', 'month' => 'Ce mois', 'all' => 'Tout']" />

    <div class="m-actions m-space-top">
        <button type="button" class="m-action m-action--wide" data-sheet-open="rf-export-sheet" data-haptic><x-m.icon name="arrow-down-tray" /> Exporter en PDF ou Excel</button>
    </div>
    <div id="rf-export-sheet" class="m-sheet" role="dialog" aria-label="Exporter les paiements fournisseurs" wire:ignore>
        <div class="m-sheet__grip"></div>
        <div class="m-sheet__title">Exporter les paiements fournisseurs</div>
        <x-m.range-export category="remboursements_fournisseurs" />
    </div>

    <div class="m-summary" role="status" aria-live="polite">
        <span>{{ $count }} {{ $count > 1 ? 'paiements' : 'paiement' }}@if ($cancelledCount) · {{ $cancelledCount }} annulé{{ $cancelledCount > 1 ? 's' : '' }}@endif</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($total) }}</strong>
    </div>

    @forelse ($groups as $date => $items)
        <x-m.day :date="$date" :total="$items->reject->isCancelled()->sum('amount')" />
        <div class="m-group">
            @foreach ($items as $r)
                @include('livewire.mobile.partials.supplier-payment', ['r' => $r])
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
