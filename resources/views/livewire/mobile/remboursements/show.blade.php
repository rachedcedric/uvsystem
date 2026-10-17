@php($r = $remboursement)
<div>
    <x-m.appbar title="Remboursement" :sub="$r->reference" :back="route('m.remboursements')">
        @if ($canEdit)
            <a href="{{ route('m.crud.edit', ['remboursements', $r->id]) }}" wire:navigate class="m-iconbtn" aria-label="Modifier" data-haptic><x-m.icon name="pencil-square" /></a>
        @endif
    </x-m.appbar>

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">Montant payé</div>
        <div class="m-hero__amount m-num">
            {{ \App\Support\Mobile\Fcfa::number($r->amount) }}
            <span class="m-hero__unit">FCFA</span>
        </div>
        <div class="m-hero__foot">
            <span>{{ $r->payment_date->translatedFormat('l j F Y, H:i') }}</span>
        </div>
    </div>

    <div class="m-section">
        <div class="m-group">
            <x-m.row :href="$r->agent && \Illuminate\Support\Facades\Route::has('m.agents.show') ? route('m.agents.show', $r->agent) : null"
                     :lead="mb_strtoupper(mb_substr($r->agent?->name ?? '?', 0, 2))" :title="$r->agent?->name ?? 'Agent supprimé'"
                     :sub="'Dette restante : '.\App\Support\Mobile\Fcfa::format($r->agent?->current_debt ?? 0)" />
        </div>
    </div>

    <div class="m-section">
        <dl class="m-group" style="margin-bottom:0">
            <div class="m-kv"><dt>Mode de paiement</dt><dd>{{ $method ? trim($method->icon.' '.$method->name) : $r->payment_method }}</dd></div>
            @if ($r->notes)
                <div class="m-kv"><dt>Notes</dt><dd>{{ $r->notes }}</dd></div>
            @endif
        </dl>
    </div>

    <div class="m-actions">
        <a href="{{ route('remboursement.receipt.download', $r) }}" target="_blank" rel="noopener" class="m-action" aria-label="Télécharger le reçu au format PDF" data-download data-haptic>
            <x-m.icon name="document-arrow-down" />
            Reçu PDF
        </a>
    </div>

    @if ($canDelete)
        <button type="button" class="m-danger-link" style="background:transparent;box-shadow:inset 0 0 0 1.5px var(--danger-soft)" data-sheet-open="delete-sheet">
            <x-m.icon name="trash" class="m-ico-sm" /> Supprimer le remboursement
        </button>
        <div id="delete-sheet" class="m-sheet" role="dialog" aria-label="Confirmer la suppression">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Supprimer {{ $r->reference }} ?</div>
            <p style="margin:0 20px 16px;color:var(--fg-2)">Le paiement est supprimé et la dette de l'agent est recalculée.</p>
            <div style="padding:0 16px;display:grid;gap:10px">
                <button type="button" class="m-btn" style="background:var(--danger)" wire:click="delete" @click="MobileUI.closeSheets()">Oui, supprimer</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close>Annuler</button>
            </div>
        </div>
    @endif
</div>
