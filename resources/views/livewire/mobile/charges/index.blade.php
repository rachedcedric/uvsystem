<div>
    <x-m.appbar title="Charges" :back="route('m.more')" />

    <div class="m-strip" data-no-ptr>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="banknotes" class="m-ico-sm" /><span>Total revenu</span></div><div class="m-stat__value m-num" style="color:var(--ok)">{{ \App\Support\Mobile\Fcfa::number($stats['revenue']) }}<span class="m-stat__unit"> FCFA</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="receipt-percent" class="m-ico-sm" /><span>Total charges</span></div><div class="m-stat__value m-num" style="color:var(--danger)">{{ \App\Support\Mobile\Fcfa::number($stats['charges']) }}<span class="m-stat__unit"> FCFA</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="scale" class="m-ico-sm" /><span>Revenu net</span></div><div class="m-stat__value m-num" style="color:var(--{{ $stats['net'] >= 0 ? 'ok' : 'danger' }})">{{ $stats['net'] < 0 ? '−' : '' }}{{ \App\Support\Mobile\Fcfa::number(abs($stats['net'])) }}<span class="m-stat__unit"> FCFA</span></div></div>
    </div>
    <p style="margin:8px 20px 0;font-size:12.5px;color:var(--fg-3)">{{ $stats['period_label'] }}</p>

    @if ($canExport)
        <x-m.period-export module="charges" :summary="$exportSummary" noun="charge" nouns="charges" />
    @endif

    <x-m.search wire:model.live.debounce.300ms="search" placeholder="Libellé ou catégorie" />
    <x-m.chips model="period" :current="$period"
               :options="['today' => 'Aujourd\'hui', 'week' => '7 jours', 'month' => 'Ce mois', 'all' => 'Tout']" />

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'charges' : 'charge' }}</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($total) }}</strong>
    </div>

    @forelse ($groups as $date => $items)
        <x-m.day :date="$date" :total="$items->sum('montant')" />
        <div class="m-group">
            @foreach ($items as $c)
                <x-m.row :href="route('m.charges.show', $c)" wire:key="c{{ $c->id }}" icon="receipt-percent" tone="warn"
                         :title="$c->libelle" :sub="($c->category?->name ?? 'Sans catégorie').($c->justificatif_path ? ' · justificatif' : '')">
                    <x-slot:end>
                        <span class="m-row__amount m-amount--out">−{{ \App\Support\Mobile\Fcfa::number($c->montant) }}</span>
                    </x-slot:end>
                </x-m.row>
            @endforeach
        </div>
    @empty
        <div class="m-group" style="margin-top:18px">
            <x-m.empty icon="receipt-percent" title="Aucune charge" text="Aucune charge sur cette période." />
        </div>
    @endforelse

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.charges.create')" label="Nouvelle charge" />
    @endif
</div>
