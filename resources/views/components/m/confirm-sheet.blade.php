@props(['id', 'title', 'text' => null, 'confirm' => 'Confirmer', 'cancel' => 'Annuler', 'tone' => 'danger'])
{{-- Feuille de confirmation d'une action sensible. L'action elle-même est passée en attribut (ex. wire:click="delete") :
     elle s'applique au bouton de confirmation ; la feuille se referme ensuite. Le serveur reste seul juge (Gate). --}}
<div id="{{ $id }}" class="m-sheet" role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <div class="m-sheet__grip"></div>
    <div class="m-sheet__title">{{ $title }}</div>
    @if ($text)<p class="m-sheet__text">{{ $text }}</p>@endif
    <div class="m-sheet__actions">
        <button type="button" {{ $attributes->class(['m-btn', 'm-btn--danger' => $tone === 'danger']) }} @click="MobileUI.closeSheets()">{{ $confirm }}</button>
        <button type="button" class="m-btn m-btn--ghost" data-sheet-close>{{ $cancel }}</button>
    </div>
</div>
