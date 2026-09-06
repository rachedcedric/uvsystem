@php
    $s = $getState();
    if (!$s) {
        return;
    }
@endphp

<div class="flex gap-1">
    <span
        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $s['details']['volume'] >= 15 ? 'bg-green-100 text-green-800' : ($s['details']['volume'] >= 10 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}"
        title="Volume">
        📦 {{ $s['details']['volume'] }}/25
    </span>
    <span
        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $s['details']['regularite'] >= 12 ? 'bg-green-100 text-green-800' : ($s['details']['regularite'] >= 8 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}"
        title="Régularité">
        📅 {{ $s['details']['regularite'] }}/20
    </span>
    <span
        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $s['details']['paiement'] >= 20 ? 'bg-green-100 text-green-800' : ($s['details']['paiement'] >= 12 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}"
        title="Paiement">
        💰 {{ $s['details']['paiement'] }}/30
    </span>
    <span
        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $s['details']['dette'] >= 15 ? 'bg-green-100 text-green-800' : ($s['details']['dette'] >= 10 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}"
        title="Dette">
        📊 {{ $s['details']['dette'] }}/25
    </span>
</div>
