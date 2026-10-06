@props(['date', 'total' => null])
@php
    $d = \Carbon\Carbon::parse($date);
    $label = $d->isToday() ? "Aujourd'hui" : ($d->isYesterday() ? 'Hier' : ucfirst($d->translatedFormat('l j F')));
@endphp
<div class="m-day">
    <span>{{ $label }}</span>
    @if ($total !== null) <span>{{ \App\Support\Mobile\Fcfa::format($total) }}</span> @endif
</div>
