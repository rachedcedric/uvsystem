@props(['dateModel' => 'date', 'timeModel' => 'time'])

<div class="m-pair" style="gap:12px">
    <x-m.field label="Date" :error="$errors->first($dateModel)">
        <input
            type="date"
            wire:model="{{ $dateModel }}"
            max="{{ today()->toDateString() }}"
            aria-label="Date de l’opération"
            @class(['m-input', 'm-input--error' => $errors->has($dateModel)])
        >
    </x-m.field>
    <x-m.field label="Heure" :error="$errors->first($timeModel)">
        <input
            type="time"
            wire:model="{{ $timeModel }}"
            aria-label="Heure de l’opération"
            @class(['m-input', 'm-input--error' => $errors->has($timeModel)])
        >
    </x-m.field>
</div>
