<div>
    <x-m.appbar title="Approvisionnements" :back="route('m.more')" />

    <x-m.period-dashboard module="approvisionnements" :summary="$stats" :yesterday="$yesterday" :daily="$daily" :trend="$trend" noun="approvisionnement" nouns="approvisionnements" :can-export="$canExport" />

    <a href="{{ route('m.rapports', ['c' => 'retours_agents']) }}" wire:navigate class="m-more">Historique des retours agents</a>

    <x-m.chips model="period" :current="$period"
               :options="['today' => 'Aujourd\'hui', 'week' => '7 jours', 'month' => 'Ce mois', 'all' => 'Tout']" />

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'approvisionnements' : 'approvisionnement' }}</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($total) }}</strong>
    </div>

    @forelse ($groups as $date => $items)
        <x-m.day :date="$date" :total="$items->sum('amount')" />
        <div class="m-group">
            @foreach ($items as $a)
                <x-m.row :href="route('m.crud.show', ['approvisionnements', $a->id])" wire:key="ap{{ $a->id }}" icon="truck" tone="muted"
                         :title="$a->fournisseur?->name ?? 'Fournisseur supprimé'" :sub="$a->reference.' · '.$a->date_approvisionnement->format('H:i').($a->notes ? ' · '.\Illuminate\Support\Str::limit($a->notes, 28) : '')">
                    <x-slot:end>
                        <span class="m-row__amount m-amount--in">+{{ \App\Support\Mobile\Fcfa::number($a->amount) }}</span>
                    </x-slot:end>
                </x-m.row>
            @endforeach
        </div>
    @empty
        <div class="m-group" style="margin-top:18px">
            <x-m.empty icon="truck" title="Aucun approvisionnement" text="Aucun approvisionnement sur cette période." />
        </div>
    @endforelse

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.approvisionnements.create')" label="Approvisionner" />
    @endif
</div>
