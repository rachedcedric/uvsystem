@props(['agents', 'model', 'selected' => null, 'id' => 'agent-picker', 'label' => 'Choisir un agent'])
{{-- Champ + feuille de sélection avec recherche locale. --}}
<div x-data="{ q: '' }">
    <button type="button" class="m-select" data-sheet-open="{{ $id }}">
        @if ($selected)
            <span class="m-row__lead">{{ mb_strtoupper(mb_substr($selected->name, 0, 2)) }}</span>
            <span class="m-row__body">
                <span class="m-row__title" style="display:block">{{ $selected->name }}</span>
                <span class="m-row__sub" style="display:block">Dette {{ \App\Support\Mobile\Fcfa::format($selected->current_debt) }}</span>
            </span>
        @else
            <span class="m-select__placeholder">{{ $label }}</span>
        @endif
        <x-m.icon name="chevron-up-down" class="m-row__chev" />
    </button>

    <div id="{{ $id }}" class="m-sheet" role="dialog" aria-label="{{ $label }}">
        <div class="m-sheet__grip"></div>
        <div class="m-sheet__title">{{ $label }}</div>
        <div class="m-picker-search">
            <div class="m-search">
                <x-m.icon name="magnifying-glass" class="m-ico-sm" />
                <input type="search" x-model="q" placeholder="Nom ou téléphone" autocomplete="off">
            </div>
        </div>
        @foreach ($agents as $agent)
            <button type="button" class="m-row" data-name="{{ mb_strtolower($agent->name.' '.$agent->phone) }}"
                    x-show="!q || $el.dataset.name.includes(q.toLowerCase())"
                    @click="$wire.set('{{ $model }}', {{ $agent->id }}); q = ''; MobileUI.closeSheets()">
                <span class="m-row__lead">{{ mb_strtoupper(mb_substr($agent->name, 0, 2)) }}</span>
                <span class="m-row__body">
                    <span class="m-row__title" style="display:block">{{ $agent->name }}</span>
                    <span class="m-row__sub" style="display:block">{{ $agent->phone ?: '—' }}</span>
                </span>
                <span class="m-row__end">
                    <span class="m-row__amount" style="display:block">{{ \App\Support\Mobile\Fcfa::format($agent->current_debt) }}</span>
                    <span class="m-row__sub" style="display:block">dette</span>
                </span>
            </button>
        @endforeach
    </div>
</div>
