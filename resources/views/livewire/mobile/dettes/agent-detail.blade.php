@php
    $meta = [
        'distribution' => ['Vente', 'arrow-up-right', null],
        'retour' => ['Retour', 'arrow-down-left', 'ok'],
        'remboursement' => ['Remboursement', 'arrow-uturn-left', 'ok'],
    ];
@endphp
<div>
    <x-m.appbar :title="$a->name" sub="Détail de la dette" :back="route('m.dettes')" />

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">Reste à payer</div>
        <div class="m-hero__amount m-num">{{ \App\Support\Mobile\Fcfa::number($summary['resteAPayer']) }}<span class="m-hero__unit">FCFA</span></div>
        <div class="m-hero__foot" style="gap:16px">
            <span>Total {{ \App\Support\Mobile\Fcfa::format($summary['totalDette']) }}</span>
            <span>Payé {{ \App\Support\Mobile\Fcfa::format($summary['rembourse']) }}</span>
            <span>Jour {{ $summary['detteDuJour'] >= 0 ? '+' : '−' }}{{ \App\Support\Mobile\Fcfa::format(abs($summary['detteDuJour'])) }}</span>
        </div>
    </div>

    <div class="m-actions">
        @if ($canCollect)
            <a href="{{ route('m.remboursements.create', ['agent' => $a->id]) }}" wire:navigate class="m-action" data-haptic><x-m.icon name="banknotes" /> Encaisser</a>
        @endif
        <a href="{{ route('m.exports.dette-agent', [$a, 'pdf']) }}" target="_blank" class="m-action" data-haptic><x-m.icon name="document-arrow-down" /> PDF</a>
        <a href="{{ route('m.exports.dette-agent', [$a, 'xlsx']) }}" target="_blank" class="m-action" data-haptic><x-m.icon name="table-cells" /> Excel</a>
    </div>

    <x-m.section title="Historique">
        <div class="m-group">
            @forelse ($history as $h)
                @php($m = $meta[$h->type] ?? $meta['distribution'])
                @php($isRepay = $h->type === 'remboursement')
                <x-m.row :icon="$m[1]" :tone="$m[2]" :title="$m[0]" :chevron="false" wire:key="h{{ $h->type }}{{ $h->id }}"
                         :sub="$h->reference.' · '.\Carbon\Carbon::parse($h->date_operation)->translatedFormat('j M, H:i')">
                    <x-slot:end>
                        <span class="m-row__amount {{ $isRepay || $h->type === 'retour' ? 'm-amount--in' : '' }}" style="display:block">
                            {{ $isRepay || $h->type === 'retour' ? '+' : '−' }}{{ \App\Support\Mobile\Fcfa::number($isRepay ? $h->remboursement_amount : $h->amount) }}
                        </span>
                        @if ($h->solde_uv_apres !== null)
                            <span class="m-row__sub" style="display:block">solde UV {{ \App\Support\Mobile\Fcfa::format((float) $h->solde_uv_apres) }}</span>
                        @endif
                    </x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="clock" title="Aucune opération" />
            @endforelse
        </div>
        @if ($hasMore)
            <div wire:intersect="loadMore"></div>
            <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
        @endif
    </x-m.section>
</div>
