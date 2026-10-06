@php($c = $commission)
<div>
    <x-m.appbar :title="ucfirst($c->date_commission->translatedFormat('l j F'))" sub="Commission du jour" :back="route('m.commissions')" />

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">Commission globale</div>
        <div class="m-hero__amount m-num">{{ \App\Support\Mobile\Fcfa::number($c->commission_globale) }}<span class="m-hero__unit">FCFA</span></div>
        <div class="m-hero__foot"><span>Volume {{ \App\Support\Mobile\Fcfa::format($c->volume_total_jour) }}</span></div>
    </div>

    <div class="m-section">
        <dl class="m-group" style="margin-bottom:0">
            <div class="m-kv"><dt>Date</dt><dd>{{ $c->date_commission->translatedFormat('d/m/Y') }}</dd></div>
            <div class="m-kv"><dt>Nombre de transactions</dt><dd>{{ $c->nombre_transactions }}</dd></div>
            <div class="m-kv"><dt>Volume du jour</dt><dd>{{ \App\Support\Mobile\Fcfa::format($c->volume_total_jour) }}</dd></div>
            <div class="m-kv"><dt>Palier appliqué</dt><dd>{{ \App\Support\Mobile\Fcfa::number($c->seuil_min) }} — {{ \App\Support\Mobile\Fcfa::number($c->seuil_max) }}</dd></div>
            @if ($c->notes)<div class="m-kv"><dt>Notes</dt><dd>{{ $c->notes }}</dd></div>@endif
        </dl>
    </div>
</div>
