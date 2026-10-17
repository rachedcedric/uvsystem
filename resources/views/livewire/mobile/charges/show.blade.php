@php($c = $charge)
<div>
    <x-m.appbar title="Charge" :sub="$c->category?->name" :back="route('m.charges')">
        @if ($canEdit)
            <a href="{{ route('m.crud.edit', ['charges', $c->id]) }}" wire:navigate class="m-iconbtn" aria-label="Modifier" data-haptic><x-m.icon name="pencil-square" /></a>
        @endif
    </x-m.appbar>

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">{{ $c->libelle }}</div>
        <div class="m-hero__amount m-num">
            {{ \App\Support\Mobile\Fcfa::number($c->montant) }}
            <span class="m-hero__unit">FCFA</span>
        </div>
        <div class="m-hero__foot">
            <span>{{ $c->date_charge->translatedFormat('l j F Y') }}{{ $c->heure_charge ? ', '.\Illuminate\Support\Str::of($c->heure_charge)->substr(0, 5) : '' }}</span>
        </div>
    </div>

    <div class="m-section">
        <dl class="m-group" style="margin-bottom:0">
            <div class="m-kv"><dt>Catégorie</dt><dd>{{ $c->category?->name ?? '—' }}</dd></div>
            <div class="m-kv"><dt>Enregistrée par</dt><dd>{{ $c->creator?->name ?? 'Système' }}</dd></div>
            @if ($c->description)
                <div class="m-kv"><dt>Description</dt><dd>{{ $c->description }}</dd></div>
            @endif
        </dl>
    </div>

    @if ($c->justificatif_path && auth()->user()->can('view_charges'))
        <div class="m-actions">
            <a href="{{ route('charges.justificatif.download', $c) }}" target="_blank" rel="noopener" class="m-action" aria-label="Télécharger le justificatif de la charge" data-download data-haptic>
                <x-m.icon name="paper-clip" />
                Voir le justificatif
            </a>
        </div>
    @endif

    @if ($canDelete)
        <button type="button" class="m-danger-link" data-sheet-open="delete-sheet"><x-m.icon name="trash" class="m-ico-sm" /> Supprimer la charge</button>
        <div id="delete-sheet" class="m-sheet" role="dialog" aria-label="Confirmer la suppression">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Supprimer « {{ $c->libelle }} » ?</div>
            <p style="margin:0 20px 16px;color:var(--fg-2)">La charge est retirée des totaux et du revenu net.</p>
            <div style="padding:0 16px;display:grid;gap:10px">
                <button type="button" class="m-btn" style="background:var(--danger)" wire:click="delete" @click="MobileUI.closeSheets()">Oui, supprimer</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close>Annuler</button>
            </div>
        </div>
    @endif
</div>
