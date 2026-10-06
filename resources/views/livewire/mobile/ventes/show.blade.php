@php
    $t = $transaction;
    $cancelled = $t->status === 'cancelled';
    $isSale = $t->transaction_type === 'distribution';
@endphp
<div>
    <x-m.appbar :title="$isSale ? 'Vente UV' : 'Retour UV'" :sub="$t->reference" :back="route('m.ventes')">
        @if ($canEdit)
            <a href="{{ route('m.crud.edit', ['ventes', $t->id]) }}" wire:navigate class="m-iconbtn" aria-label="Modifier" data-haptic><x-m.icon name="pencil-square" /></a>
        @endif
    </x-m.appbar>

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">{{ $isSale ? 'Montant vendu' : 'Montant repris' }}</div>
        <div class="m-hero__amount m-num" style="{{ $cancelled ? 'text-decoration:line-through;opacity:.6' : '' }}">
            {{ \App\Support\Mobile\Fcfa::number($t->amount) }}
            <span class="m-hero__unit">FCFA</span>
        </div>
        <div class="m-hero__foot">
            <span>{{ $t->transaction_date->translatedFormat('l j F Y, H:i') }}</span>
            @if ($cancelled) <span class="m-badge m-badge--danger">Annulée</span> @endif
        </div>
    </div>

    <div class="m-section">
        <div class="m-group">
            <x-m.row :href="$t->agent && \Illuminate\Support\Facades\Route::has('m.agents.show') ? route('m.agents.show', $t->agent) : null"
                     :lead="mb_strtoupper(mb_substr($t->agent?->name ?? '?', 0, 2))" :title="$t->agent?->name ?? 'Agent supprimé'" :sub="$t->agent?->phone" />
        </div>
    </div>

    <div class="m-section">
        <dl class="m-group" style="margin-bottom:0">
            <div class="m-kv"><dt>Solde UV avant</dt><dd>{{ $t->solde_uv_avant !== null ? \App\Support\Mobile\Fcfa::format($t->solde_uv_avant) : 'Non disponible' }}</dd></div>
            <div class="m-kv"><dt>Solde UV après</dt><dd>{{ $t->solde_uv_apres !== null ? \App\Support\Mobile\Fcfa::format($t->solde_uv_apres) : 'Non disponible' }}</dd></div>
            @if ($t->fournisseur)
                <div class="m-kv"><dt>Fournisseur</dt><dd>{{ $t->fournisseur->name }}</dd></div>
            @endif
            @if ($t->notes)
                <div class="m-kv"><dt>Notes</dt><dd>{{ $t->notes }}</dd></div>
            @endif
        </dl>
    </div>

    @if ($canDuplicate)
        <div class="m-actions">
            <button type="button" class="m-action" wire:click="duplicate" wire:loading.attr="disabled" data-haptic><x-m.icon name="document-duplicate" /> Dupliquer cette vente</button>
        </div>
    @endif

    @if ($canCancel)
        <button type="button" class="m-danger-link" data-sheet-open="cancel-sheet">
            <x-m.icon name="x-circle" class="m-ico-sm" /> Annuler cette vente
        </button>

        <div id="cancel-sheet" class="m-sheet" role="dialog" aria-label="Confirmer l'annulation">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Annuler cette vente ?</div>
            <p style="margin:0 20px 16px;color:var(--fg-2)">
                Les UV sont restitués au solde UV disponible. À utiliser seulement si les UV n'ont finalement pas été distribués.
            </p>
            <div style="padding:0 16px;display:grid;gap:10px">
                <button type="button" class="m-btn" style="background:var(--danger)"
                        wire:click="cancel" @click="MobileUI.closeSheets()">Oui, annuler la vente</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close>Non, garder</button>
            </div>
        </div>
    @endif

    @if ($canDelete)
        <button type="button" class="m-danger-link" style="background:transparent;box-shadow:inset 0 0 0 1.5px var(--danger-soft)" data-sheet-open="delete-sheet">
            <x-m.icon name="trash" class="m-ico-sm" /> Supprimer la vente
        </button>
        <div id="delete-sheet" class="m-sheet" role="dialog" aria-label="Confirmer la suppression">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Supprimer {{ $t->reference }} ?</div>
            <p style="margin:0 20px 16px;color:var(--fg-2)">La vente est supprimée et les soldes (UV et dette de l'agent) sont recalculés. Pour une vente non livrée, préférez « Annuler cette vente ».</p>
            <div style="padding:0 16px;display:grid;gap:10px">
                <button type="button" class="m-btn" style="background:var(--danger)" wire:click="delete" @click="MobileUI.closeSheets()">Oui, supprimer</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close>Annuler</button>
            </div>
        </div>
    @endif
</div>
