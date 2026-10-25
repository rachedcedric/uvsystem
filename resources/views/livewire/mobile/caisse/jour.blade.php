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
        <a href="{{ route('m.exports.caisse-jour', $day->toDateString()) }}" target="_blank" rel="noopener" class="m-action" aria-label="Télécharger la journée de caisse au format PDF" data-download data-haptic><x-m.icon name="document-arrow-down" /> Exporter en PDF</a>
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

    @if ($annules->isNotEmpty())
        <x-m.section title="Mouvements annulés">
            <div class="m-group">
                @foreach ($annules as $m)
                    @php($in = $m->type === 'entree')
                    {{-- Ligne dépliée : le motif et l'auteur de l'annulation doivent rester lisibles. --}}
                    <x-m.row icon="x-circle" tone="muted" :chevron="false" :title="$m->libelle" wire:key="mx{{ $m->id }}" class="m-row--top"
                             :sub="$m->reference.' · '.$m->date_mouvement->format('H:i').' · annulé le '.$m->cancelled_at->format('d/m/Y H:i').($m->cancelledBy ? ' par '.$m->cancelledBy->name : '').' — '.$m->cancellation_reason">
                        <x-slot:end><x-m.amount :value="$m->amount" :sign="$in ? '+' : '−'" :cancelled="true" class="m-row__amount" /></x-slot:end>
                    </x-m.row>
                @endforeach
            </div>
        </x-m.section>
    @endif

    @if ($canCancelDay && $totals['count'] > 0)
        <div style="margin-top:18px">
            <x-m.sheet-action sheet="cancel-day-sheet" icon="trash" :outline="true">Supprimer les mouvements de cette journée</x-m.sheet-action>
        </div>

        <div id="cancel-day-sheet" class="m-sheet" role="dialog" aria-modal="true" aria-label="Supprimer les mouvements de la journée" aria-hidden="true" tabindex="-1">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Supprimer les mouvements du {{ $day->format('d/m/Y') }}</div>
            <div style="padding:0 16px">
                <x-m.callout tone="danger" icon="exclamation-triangle">Attention : vous êtes sur le point de supprimer les mouvements du {{ $day->format('d/m/Y') }}. Cette action modifiera les historiques et les soldes concernés.</x-m.callout>
                <x-m.kv-list :items="[
                    'Mouvements' => (string) $totals['count'],
                    'Entrées' => \App\Support\Mobile\Fcfa::format($totals['entrees']),
                    'Sorties' => \App\Support\Mobile\Fcfa::format($totals['sorties']),
                    'Résultat' => \App\Support\Mobile\Fcfa::format($totals['solde']),
                ]" />
                <p class="m-note">Les mouvements ne sont pas effacés : ils sont annulés, restent visibles dans « Mouvements annulés » avec le motif, et ne comptent plus dans les soldes. Les autres journées ne changent pas.</p>
                <x-m.field label="Motif" for="cancel-day-reason">
                    <textarea id="cancel-day-reason" wire:model="cancelReason" rows="2" maxlength="500" class="m-input" style="height:auto;padding:12px 14px" placeholder="Ex. : journée saisie par erreur"></textarea>
                </x-m.field>
                <x-m.field label="Pour confirmer, saisissez la date {{ $day->format('d/m/Y') }}" for="cancel-day-confirm">
                    <input id="cancel-day-confirm" type="text" inputmode="numeric" wire:model="cancelConfirmation" class="m-input" placeholder="{{ $day->format('d/m/Y') }}" autocomplete="off">
                </x-m.field>
            </div>
            <div class="m-sheet__actions">
                <button type="button" class="m-btn m-btn--danger" wire:click="cancelDay" @click="MobileUI.closeSheets()">Supprimer les mouvements de cette journée</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close data-sheet-initial-focus>Annuler</button>
            </div>
        </div>
    @endif

    @if ($canCreate)
        <x-m.fab :href="route('m.caisse.create', ['date' => $day->toDateString()])" label="Mouvement" />
    @endif
</div>
