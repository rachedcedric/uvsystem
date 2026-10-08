@props(['searchModel' => 'search', 'placeholder' => 'Rechercher', 'periodModel' => 'period', 'period' => null, 'periods' => [], 'count' => null, 'noun' => 'résultat', 'nouns' => 'résultats', 'total' => null, 'active' => false])
{{-- Recherche + période + résumé du résultat (annoncé aux lecteurs d'écran) + « Réinitialiser » quand un filtre est actif.
     Le composant Livewire parent expose resetFilters(). Aucune logique métier ici : tout vient du parent. --}}
<x-m.search wire:model.live.debounce.300ms="{{ $searchModel }}" :placeholder="$placeholder" />
@if ($periods)
    <x-m.chips :model="$periodModel" :current="$period" :options="$periods" />
@endif
<div class="m-summary" role="status" aria-live="polite">
    @if ($count !== null)<span>{{ $count }} {{ $count > 1 ? $nouns : $noun }}</span>@endif
    @if ($total !== null)<strong>{{ $total }}</strong>@endif
    @if ($active)<button type="button" class="m-link" wire:click="resetFilters" data-haptic>Réinitialiser</button>@endif
</div>
