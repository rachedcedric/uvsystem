<div wire:poll.60s.visible>
    <x-m.appbar title="Caisse" :sub="'Journée du '.$todayDate->translatedFormat('j F')" />

    <div class="m-hero">
        <div class="m-hero__label">Solde de la caisse</div>
        <div class="m-hero__amount m-num">
            {{ \App\Support\Mobile\Fcfa::number($solde) }}
            <span class="m-hero__unit">FCFA</span>
        </div>
        <div class="m-hero__foot" style="gap:16px">
            <span><span class="m-amount--in" style="color:#8be68a">+{{ \App\Support\Mobile\Fcfa::number($today['entrees']) }}</span> entrées</span>
            <span><span style="color:#ffb1a5">−{{ \App\Support\Mobile\Fcfa::number($today['sorties']) }}</span> sorties</span>
        </div>
    </div>

    <x-m.section title="Journées">
        <div class="m-group">
            @forelse ($summaries as $day)
                @php($solde_jour = (float) $day['solde'])
                <x-m.row :href="route('m.caisse.jour', $day['date']->toDateString())" wire:key="d{{ $day['date']->toDateString() }}"
                         :icon="$solde_jour >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" :tone="$solde_jour >= 0 ? 'ok' : 'danger'"
                         :title="ucfirst($day['date']->translatedFormat('l j F'))"
                         :sub="$day['count'].' '.($day['count'] > 1 ? 'mouvements' : 'mouvement').' · +'.\App\Support\Mobile\Fcfa::format($day['entrees']).' / −'.\App\Support\Mobile\Fcfa::format($day['sorties'])">
                    <x-slot:end>
                        <span class="m-row__amount {{ $solde_jour >= 0 ? 'm-amount--in' : 'm-amount--out' }}">
                            {{ $solde_jour >= 0 ? '+' : '−' }}{{ \App\Support\Mobile\Fcfa::number(abs($solde_jour)) }}
                        </span>
                    </x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="wallet" title="Aucun mouvement" text="Enregistrez une entrée ou une sortie d'argent pour suivre votre caisse." />
            @endforelse
        </div>
        @if ($hasMore)
            <button type="button" class="m-more" wire:click="loadMore">Journées précédentes</button>
        @endif
    </x-m.section>

    <div class="m-actions">
        <a href="{{ route('m.exports.caisse-historique') }}" target="_blank" class="m-action" data-haptic><x-m.icon name="document-arrow-down" /> PDF 14 jours</a>
        <a href="{{ route('m.exports.caisse-historique', ['p' => 'all']) }}" target="_blank" class="m-action" data-haptic><x-m.icon name="document-arrow-down" /> PDF complet</a>
        <a href="{{ route('m.crud.index', 'categories-caisse') }}" wire:navigate class="m-action" data-haptic><x-m.icon name="tag" /> Catégories</a>
    </div>

    @if ($canCreate)
        <x-m.fab :href="route('m.caisse.create')" label="Mouvement" />
    @endif
</div>
