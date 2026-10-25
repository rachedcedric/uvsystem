{{-- Ligne d'un remboursement fournisseur + feuille « ⋯ » (détails, annulation avec motif). $r : RemboursementFournisseur. --}}
@php
    $cancelled = $r->isCancelled();
    $canCancel = ! $cancelled && auth()->user()->can('cancel', $r);
    $sheet = 'rf-sheet-'.$r->id;
@endphp
<div class="m-row" wire:key="rf{{ $r->id }}">
    <span class="m-row__lead {{ $cancelled ? 'm-row__lead--muted' : 'm-row__lead--ok' }}"><x-m.icon name="arrow-uturn-left" class="m-ico-sm" /></span>
    <span class="m-row__body">
        <span class="m-row__title">{{ $showSupplier ?? true ? ($r->fournisseur?->name ?? 'Fournisseur supprimé') : 'Remboursement' }}</span>
        <span class="m-row__sub">{{ $r->reference }} · {{ $r->payment_date->format(($withDate ?? false) ? 'd/m/Y H:i' : 'H:i') }}@if ($cancelled) · <x-m.status-badge tone="muted" label="Annulé" />@endif</span>
    </span>
    <span class="m-row__end"><x-m.amount :value="$r->amount" sign="−" :cancelled="$cancelled" class="m-row__amount" /></span>
    <button type="button" class="m-iconbtn" data-sheet-open="{{ $sheet }}" aria-haspopup="dialog" aria-controls="{{ $sheet }}" aria-expanded="false" aria-label="Actions sur le remboursement {{ $r->reference }}" data-haptic>
        <x-m.icon name="ellipsis-horizontal" />
    </button>
</div>

<div id="{{ $sheet }}" class="m-sheet" role="dialog" aria-modal="true" aria-label="Remboursement {{ $r->reference }}" aria-hidden="true" tabindex="-1">
    <div class="m-sheet__grip"></div>
    <div class="m-sheet__title">Remboursement {{ $r->reference }}</div>
    <x-m.kv-list :items="[
        'Fournisseur' => $r->fournisseur?->name ?? 'Fournisseur supprimé',
        'Montant' => \App\Support\Mobile\Fcfa::format($r->amount),
        'Date' => $r->payment_date->format('d/m/Y à H:i'),
        'Saisi par' => $r->user?->name,
        'Notes' => $r->notes,
        'Statut' => $cancelled ? 'Annulé le '.$r->cancelled_at->format('d/m/Y à H:i').($r->cancelledBy ? ' par '.$r->cancelledBy->name : '') : 'Validé',
        'Motif d\'annulation' => $r->cancellation_reason,
    ]" />
    @if ($canCancel)
        <p class="m-sheet__text">L'annulation conserve l'opération dans l'historique avec le statut « Annulé » et la retire du solde du fournisseur. Elle est définitive.</p>
        <div style="padding:0 16px">
            <x-m.field label="Motif de l'annulation" for="reason-{{ $r->id }}">
                <textarea id="reason-{{ $r->id }}" wire:model="cancelReason" rows="2" maxlength="500" class="m-input" style="height:auto;padding:12px 14px" placeholder="Ex. : saisie en double"></textarea>
            </x-m.field>
        </div>
        <div class="m-sheet__actions">
            <button type="button" class="m-btn m-btn--danger" wire:click="cancelPayment({{ $r->id }})" @click="MobileUI.closeSheets()">Annuler le remboursement</button>
            <button type="button" class="m-btn m-btn--ghost" data-sheet-close data-sheet-initial-focus>Fermer</button>
        </div>
    @else
        <div class="m-sheet__actions"><button type="button" class="m-btn m-btn--ghost" data-sheet-close data-sheet-initial-focus>Fermer</button></div>
    @endif
</div>
