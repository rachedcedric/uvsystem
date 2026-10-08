@props(['tone' => 'muted', 'label' => null])
{{-- Pastille d'état. Le texte porte toujours le sens (jamais la couleur seule). Tons : ok, warn, danger, brand, muted. --}}
<span {{ $attributes->class(['m-badge', 'm-badge--'.$tone]) }}>{{ $label ?? $slot }}</span>
