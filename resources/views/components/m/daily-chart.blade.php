@props(['days', 'label' => 'Évolution du mois'])
@php
    use App\Support\Mobile\Fcfa;

    $max = max(1, collect($days)->max('value'));
    $total = collect($days)->sum('value');
    $avg = $total / max(1, count($days));
@endphp
{{-- Une barre par jour du mois en cours ; toucher une barre affiche son montant exact (voir mobile.js, selectBar). --}}
<div class="m-group">
    <div class="m-readout" data-bars-readout aria-live="polite"><span>Aujourd'hui</span><strong class="m-num">{{ Fcfa::format(collect($days)->last()['value'] ?? 0) }}</strong></div>
    <div class="m-bars m-bars--dense" role="img" aria-label="{{ $label }} (touchez une barre pour le montant exact)">
        @foreach ($days as $day)
            @php
                $pct = $day['value'] > 0 ? max(4, round($day['value'] / $max * 84)) : 0;
                $showLabel = $day['today'] || $loop->first || ((int) $day['label']) % 5 === 0;
            @endphp
            <div class="m-bars__col {{ $day['today'] ? 'm-bars__col--today is-selected' : '' }}" role="button" tabindex="0"
                 data-amount="{{ (int) round($day['value']) }}" data-label="{{ $day['today'] ? 'Aujourd\'hui' : ucfirst(\Carbon\Carbon::parse($day['date'])->translatedFormat('l j F')) }}">
                <span class="m-bars__track">
                    <span class="m-bars__bar" style="height: {{ max(3, $pct) }}%; animation-delay: {{ $loop->index * 15 }}ms"></span>
                </span>
                <span class="m-bars__lbl">{{ $showLabel ? $day['label'] : '' }}</span>
            </div>
        @endforeach
    </div>
    <div class="m-chart-foot m-num">
        <span>Total {{ Fcfa::format($total) }}</span>
        <span>Moy. {{ Fcfa::format($avg) }}/jour</span>
    </div>
</div>
