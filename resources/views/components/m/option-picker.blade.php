@props(['options', 'model', 'selected' => null, 'label' => 'Choisir', 'id' => null, 'labelledBy' => null, 'describedBy' => null, 'invalid' => false])
@php($sheetId = 'opt-'.md5($model))
<div x-data="{ q: '' }">
    <button type="button" class="m-select" data-sheet-open="{{ $sheetId }}" @if ($id) id="{{ $id }}" @endif
            @if ($labelledBy) aria-labelledby="{{ $labelledBy }}" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($invalid) aria-invalid="true" @endif aria-haspopup="dialog" aria-controls="{{ $sheetId }}" aria-expanded="false">
        @if ($selected !== null && $selected !== '' && isset($options[$selected]))
            <span class="m-row__body"><span class="m-row__title" style="display:block">{{ $options[$selected] }}</span></span>
        @else
            <span class="m-select__placeholder">{{ $label }}</span>
        @endif
        <x-m.icon name="chevron-up-down" class="m-row__chev" />
    </button>

    <div id="{{ $sheetId }}" class="m-sheet" role="dialog" aria-modal="true" aria-label="{{ $label }}" aria-hidden="true" tabindex="-1">
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
