@props(['sheet', 'icon' => null, 'tone' => 'danger', 'outline' => false])
{{-- Bouton qui ouvre une feuille de confirmation (<x-m.confirm-sheet>). Pleine largeur, 52 px de haut (zone tactile). --}}
<button type="button" data-sheet-open="{{ $sheet }}" aria-haspopup="dialog"
        {{ $attributes->class(['m-danger-link', 'm-danger-link--outline' => $outline]) }}>
    @if ($icon)<x-m.icon :name="$icon" class="m-ico-sm" />@endif {{ $slot }}
</button>
