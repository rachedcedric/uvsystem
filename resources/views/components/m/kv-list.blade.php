@props(['items'])
{{-- Liste « libellé / valeur » d'une fiche. Les valeurs vides (null, '') sont omises ; [libellé, valeur] ou libellé => valeur. --}}
@php
    $rows = collect($items)->map(fn ($v, $k) => is_array($v) ? $v : [$k, $v])->filter(fn ($r) => filled($r[1] ?? null));
@endphp
@if ($rows->isNotEmpty())
    <dl {{ $attributes->class(['m-group', 'm-kv-list']) }}>
        @foreach ($rows as [$label, $value])
            <div class="m-kv"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
@endif
