@props(['category', 'title' => 'Période personnalisée'])
{{-- Export PDF / Excel d'une catégorie de rapport sur des dates libres (date de fin incluse). Les liens sont construits
     dans le navigateur ; le serveur revalide la période (message clair si la fin précède le début). --}}
@php($base = route('m.exports.categorie', [$category, '__F__']))
<div class="m-export m-export--range" x-data="{ du: '{{ now()->startOfMonth()->toDateString() }}', au: '{{ now()->toDateString() }}' }">
    <div class="m-export__info">
        <strong>{{ $title }}</strong>
        <span x-show="du && au && du > au" class="m-text-danger" role="alert">La date de fin doit être égale ou postérieure à la date de début.</span>
    </div>
    <div class="m-pair">
        <x-m.field label="Du" for="{{ $category }}-du"><input id="{{ $category }}-du" type="date" class="m-input" x-model="du" max="{{ now()->toDateString() }}"></x-m.field>
        <x-m.field label="Au" for="{{ $category }}-au"><input id="{{ $category }}-au" type="date" class="m-input" x-model="au"></x-m.field>
    </div>
    <div class="m-quick">
        <a :href="'{{ str_replace('__F__', 'pdf', $base) }}?periode=custom&du=' + du + '&au=' + au" x-bind:aria-disabled="!(du && au && du <= au)" target="_blank" rel="noopener" class="m-chip m-export__btn" aria-label="Exporter la période choisie au format PDF" data-download data-haptic data-sheet-close><x-m.icon name="document-arrow-down" class="m-ico-sm" /> PDF</a>
        <a :href="'{{ str_replace('__F__', 'xlsx', $base) }}?periode=custom&du=' + du + '&au=' + au" x-bind:aria-disabled="!(du && au && du <= au)" target="_blank" rel="noopener" class="m-chip m-export__btn" aria-label="Exporter la période choisie au format Excel" data-download data-haptic data-sheet-close><x-m.icon name="table-cells" class="m-ico-sm" /> Excel</a>
    </div>
</div>
