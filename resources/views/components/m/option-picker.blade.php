@props(['options', 'model', 'selected' => null, 'label' => 'Choisir'])
@php($id = 'opt-'.md5($model))
<div x-data="{ q: '' }">
    <button type="button" class="m-select" data-sheet-open="{{ $id }}">
        @if ($selected !== null && $selected !== '' && isset($options[$selected]))
            <span class="m-row__body"><span class="m-row__title" style="display:block">{{ $options[$selected] }}</span></span>
        @else
            <span class="m-select__placeholder">{{ $label }}</span>
        @endif
        <x-m.icon name="chevron-up-down" class="m-row__chev" />
    </button>

    <div id="{{ $id }}" class="m-sheet" role="dialog" aria-label="{{ $label }}">
        <div class="m-sheet__grip"></div>
        <div class="m-sheet__title">{{ $label }}</div>
        <div class="m-picker-search">
            <div class="m-search"><x-m.icon name="magnifying-glass" class="m-ico-sm" /><input type="search" x-model="q" placeholder="Rechercher" autocomplete="off"></div>
        </div>
        @foreach ($options as $value => $text)
            <button type="button" class="m-row" data-name="{{ mb_strtolower($text) }}" x-show="!q || $el.dataset.name.includes(q.toLowerCase())"
                    @click="$wire.set('{{ $model }}', '{{ $value }}'); q = ''; MobileUI.closeSheets()">
                <span class="m-row__body"><span class="m-row__title" style="display:block">{{ $text }}</span></span>
            </button>
        @endforeach
    </div>
</div>
