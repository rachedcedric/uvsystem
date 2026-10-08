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

    <x-m.access-note />

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">{{ $isSale ? 'Montant vendu' : 'Montant repris' }}</div>
        <div class="m-hero__amount m-num {{ $cancelled ? 'is-cancelled' : '' }}">
            {{ \App\Support\Mobile\Fcfa::number($t->amount) }}
            <span class="m-hero__unit">FCFA</span>
        </div>
        <div class="m-hero__foot">
            <span>{{ $t->transaction_date->translatedFormat('l j F Y, H:i') }}</span>
            @if ($cancelled) <x-m.status-badge tone="danger" label="Annulée" /> @endif
        </div>
    </div>

    <div class="m-section">
        <div class="m-group">
            <x-m.row :href="$t->agent && \Illuminate\Support\Facades\Route::has('m.agents.show') ? route('m.agents.show', $t->agent) : null"
                     :lead="mb_strtoupper(mb_substr($t->agent?->name ?? '?', 0, 2))" :title="$t->agent?->name ?? 'Agent supprimé'" :sub="$t->agent?->phone" />
        </div>
    </div>

    <div class="m-section">
        <x-m.kv-list style="margin-bottom:0" :items="[
            ['Solde UV avant', $t->solde_uv_avant !== null ? \App\Support\Mobile\Fcfa::format($t->solde_uv_avant) : 'Non disponible'],
            ['Solde UV après', $t->solde_uv_apres !== null ? \App\Support\Mobile\Fcfa::format($t->solde_uv_apres) : 'Non disponible'],
            ['Fournisseur', $t->fournisseur?->name],
            ['Notes', $t->notes],
        ]" />
    </div>

    @if ($canDuplicate)
        <div class="m-actions">
            <button type="button" class="m-action" wire:click="duplicate" wire:loading.attr="disabled" data-haptic><x-m.icon name="document-duplicate" /> Dupliquer cette vente</button>
        </div>
    @endif

    @if ($canCancel)
        <x-m.sheet-action sheet="cancel-sheet" icon="x-circle">Annuler cette vente</x-m.sheet-action>
        <x-m.confirm-sheet id="cancel-sheet" title="Annuler cette vente ?" confirm="Oui, annuler la vente" cancel="Non, garder"
                           text="Les UV sont restitués au solde UV disponible. À utiliser seulement si les UV n'ont finalement pas été distribués."
                           wire:click="cancel" />
    @endif

    @if ($canDelete)
        <x-m.sheet-action sheet="delete-sheet" icon="trash" outline>Supprimer la vente</x-m.sheet-action>
        <x-m.confirm-sheet id="delete-sheet" :title="'Supprimer '.$t->reference.' ?'" confirm="Oui, supprimer" cancel="Annuler"
                           text="La vente est supprimée et les soldes (UV et dette de l'agent) sont recalculés. Pour une vente non livrée, préférez « Annuler cette vente »."
                           wire:click="delete" />
    @endif
</div>
