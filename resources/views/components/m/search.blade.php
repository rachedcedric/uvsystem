@props(['placeholder' => 'Rechercher'])
<div class="m-search">
    <x-m.icon name="magnifying-glass" class="m-ico-sm" />
    <input type="search" placeholder="{{ $placeholder }}" autocomplete="off" {{ $attributes }}>
</div>
