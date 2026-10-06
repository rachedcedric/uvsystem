@php
    $max = max(1, collect($days)->max('value'));
    $total = collect($days)->sum('value');
    $avg = $total / max(1, count($days));
@endphp
<div class="m-group">
    <div class="m-readout" data-bars-readout aria-live="polite"><span>Aujourd'hui</span><strong class="m-num">{{ \App\Support\Mobile\Fcfa::format(collect($days)->last()['value'] ?? 0) }}</strong></div>
    <div class="m-bars" aria-label="Chiffre d'affaires des 7 derniers jours (touchez une barre pour le montant exact)">
        @foreach ($days as $day)
            <div class="m-bars__col {{ $day['today'] ? 'm-bars__col--today is-selected' : '' }}" role="button" tabindex="0"
                 data-amount="{{ (int) round($day['value']) }}" data-label="{{ $day['today'] ? 'Aujourd\'hui' : ucfirst(\Carbon\Carbon::parse($day['date'])->translatedFormat('l j F')) }}">
                @php($pct = $day['value'] > 0 ? max(4, round($day['value'] / $max * 84)) : 0)
                <span class="m-bars__track">
                    <span class="m-bars__bar" style="height: {{ max(3, $pct) }}%; animation-delay: {{ $loop->index * 40 }}ms"></span>
                </span>
                <span class="m-bars__lbl">{{ $day['label'] }}</span>
            </div>
        @endforeach
    </div>
    <div class="m-chart-foot m-num">
        <span>Total {{ \App\Support\Mobile\Fcfa::format($total) }}</span>
        <span>Moy. {{ \App\Support\Mobile\Fcfa::format($avg) }}/jour</span>
    </div>
</div>
