@php
    $meta = [
        'vente' => ['label' => 'Vente', 'icon' => 'banknotes', 'tone' => null, 'sign' => '−', 'class' => ''],
        'approvisionnement' => ['label' => 'Approvisionnement', 'icon' => 'truck', 'tone' => 'muted', 'sign' => '+', 'class' => 'm-amount--in'],
        'remboursement' => ['label' => 'Remboursement', 'icon' => 'arrow-uturn-left', 'tone' => 'ok', 'sign' => '+', 'class' => 'm-amount--in'],
    ];
@endphp
{{-- Racine <div> comme le squelette de chargement : Livewire échoue à remplacer un <div> par un <section>. --}}
<div>
<x-m.section title="Activité récente">
    <div class="m-group">
        @forelse ($items as $item)
            @php($m = $meta[$item['type']] ?? $meta['vente'])
            <x-m.row :icon="$m['icon']" :tone="$m['tone']" :title="$item['tiers']"
                     :sub="$m['label'].' · '.\Carbon\Carbon::parse($item['date'])->translatedFormat('j M, H:i')" :chevron="false">
                <x-slot:end>
                    <span class="m-row__amount {{ $item['status'] === 'cancelled' ? '' : $m['class'] }}" style="display:block;{{ $item['status'] === 'cancelled' ? 'text-decoration:line-through;color:var(--fg-3)' : '' }}">
                        {{ $m['sign'] }}{{ \App\Support\Mobile\Fcfa::number($item['amount']) }}
                    </span>
                    @if ($item['status'] === 'cancelled') <span class="m-badge m-badge--danger">Annulée</span> @endif
                </x-slot:end>
            </x-m.row>
        @empty
            <x-m.empty icon="inbox" title="Aucune opération" text="Les ventes, approvisionnements et remboursements apparaîtront ici." />
        @endforelse
    </div>
</x-m.section>
</div>
