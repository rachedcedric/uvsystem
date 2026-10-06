@php($net = (float) $totals['solde'])
<div>
    <x-m.appbar :title="ucfirst($day->translatedFormat('l j F'))" sub="00h00 → 23h59" :back="route('m.caisse')" />

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">Résultat de la journée</div>
        <div class="m-hero__amount m-num">
            {{ $net >= 0 ? '+' : '−' }}{{ \App\Support\Mobile\Fcfa::number(abs($net)) }}
            <span class="m-hero__unit">FCFA</span>
        </div>
        <div class="m-hero__foot" style="gap:16px">
            <span><span style="color:#8be68a">+{{ \App\Support\Mobile\Fcfa::number($totals['entrees']) }}</span> entrées</span>
            <span><span style="color:#ffb1a5">−{{ \App\Support\Mobile\Fcfa::number($totals['sorties']) }}</span> sorties</span>
        </div>
    </div>

    @if ($categories->count() > 1)
        <x-m.section title="Par catégorie">
            <div class="m-group">
                @foreach ($categories as $cat)
                    @php($s = (float) $cat['solde'])
                    <x-m.row :title="$cat['name']" :chevron="false">
                        <x-slot:end>
                            <span class="m-row__amount {{ $s >= 0 ? 'm-amount--in' : 'm-amount--out' }}">{{ $s >= 0 ? '+' : '−' }}{{ \App\Support\Mobile\Fcfa::number(abs($s)) }}</span>
                        </x-slot:end>
                    </x-m.row>
                @endforeach
            </div>
        </x-m.section>
    @endif

    <div class="m-actions">
        @if ($canCreate)
            <a href="{{ route('m.caisse.create', ['date' => $day->toDateString()]) }}" wire:navigate class="m-action" data-haptic><x-m.icon name="plus" /> Ajouter à cette journée</a>
        @endif
        <a href="{{ route('m.exports.caisse-jour', $day->toDateString()) }}" target="_blank" class="m-action" data-haptic><x-m.icon name="document-arrow-down" /> Exporter en PDF</a>
    </div>

    <x-m.section title="Mouvements">
        <div class="m-group">
            @forelse ($mouvements as $m)
                @php($in = $m->type === 'entree')
                <x-m.row :href="route('m.crud.show', ['mouvements-caisse', $m->id])" :icon="$in ? 'arrow-down-left' : 'arrow-up-right'" :tone="$in ? 'ok' : 'danger'" :title="$m->libelle"
                         :sub="($m->categorie?->name ?? 'Non catégorisé').' · '.$m->date_mouvement->format('H:i')" wire:key="m{{ $m->id }}">
                    <x-slot:end>
                        <span class="m-row__amount {{ $in ? 'm-amount--in' : 'm-amount--out' }}" style="display:block">{{ $in ? '+' : '−' }}{{ \App\Support\Mobile\Fcfa::number($m->amount) }}</span>
                        @isset($running[$m->id])
                            <span class="m-row__sub" style="display:block">solde {{ \App\Support\Mobile\Fcfa::format((float) $running[$m->id]['solde_apres']) }}</span>
                        @endisset
                    </x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="wallet" title="Aucun mouvement ce jour-là" />
            @endforelse
        </div>
    </x-m.section>

    @if ($canCreate)
        <x-m.fab :href="route('m.caisse.create', ['date' => $day->toDateString()])" label="Mouvement" />
    @endif
</div>
