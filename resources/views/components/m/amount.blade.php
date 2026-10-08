@props(['value', 'sign' => null, 'cancelled' => false, 'unit' => false])
{{-- Montant en FCFA : chiffres tabulaires, toujours complet (jamais « 2,8 M »). Annulé : barré + annoncé aux lecteurs d'écran. --}}
<span {{ $attributes->class(['m-amount', 'is-cancelled' => $cancelled]) }}>
    @if ($sign){{ $sign }}@endif{{ \App\Support\Mobile\Fcfa::number($value) }}@if ($unit)<span class="m-amount__unit"> FCFA</span>@endif
    @if ($cancelled)<span class="m-sr"> (annulé)</span>@endif
</span>
