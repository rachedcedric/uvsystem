@props(['placeholder' => 'Rechercher', 'label' => null])
<div class="m-search">
    <x-m.icon name="magnifying-glass" class="m-ico-sm" />
    <input type="search" placeholder="{{ $placeholder }}" aria-label="{{ $label ?? $placeholder }}" autocomplete="off" {{ $attributes }}>
</div>
