@props(['module', 'summary', 'noun', 'nouns'])
@php
    $format = fn ($n) => \App\Support\Mobile\Fcfa::number($n);
    // Les charges ont leur propre route (périodes du bureau) et un export CSV en plus.
    $link = fn ($period, $type) => $module === 'charges'
        ? route('m.exports.charges', [$period, $type])
        : route('m.exports.period', [$module, $period, $type]);
@endphp

<div class="m-actions" style="margin-top:12px">
    <button type="button" class="m-action m-action--wide" data-sheet-open="export-sheet" data-haptic>
        <x-m.icon name="arrow-down-tray" /> Exporter en PDF ou Excel
    </button>
</div>

{{-- Contenu statique : wire:ignore évite que l'actualisation automatique ne referme la feuille. --}}
<div id="export-sheet" class="m-sheet" role="dialog" aria-label="Exporter" wire:ignore>
    <div class="m-sheet__grip"></div>
    <div class="m-sheet__title">Exporter les {{ $nouns }}</div>
    @foreach ($summary as $period => $s)
        <div class="m-export">
            <div class="m-export__info">
                <strong>{{ $s['label'] }}</strong>
                <span>{{ $s['count'] }} {{ $s['count'] > 1 ? $nouns : $noun }} · {{ $format($s['amount']) }} FCFA</span>
            </div>
            <a href="{{ $link($period, 'pdf') }}" target="_blank" rel="noopener" class="m-chip m-export__btn" aria-label="Télécharger l'export au format PDF" data-download data-haptic data-sheet-close><x-m.icon name="document-arrow-down" class="m-ico-sm" /> PDF</a>
            <a href="{{ $link($period, 'xlsx') }}" target="_blank" rel="noopener" class="m-chip m-export__btn" aria-label="Télécharger l'export au format Excel" data-download data-haptic data-sheet-close><x-m.icon name="table-cells" class="m-ico-sm" /> Excel</a>
            @if ($module === 'charges')
                <a href="{{ $link($period, 'csv') }}" target="_blank" rel="noopener" class="m-chip m-export__btn" aria-label="Télécharger l'export au format CSV" data-download data-haptic data-sheet-close><x-m.icon name="document-text" class="m-ico-sm" /> CSV</a>
            @endif
        </div>
    @endforeach
</div>
