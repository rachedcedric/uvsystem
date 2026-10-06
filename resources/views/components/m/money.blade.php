@props(['model', 'quick' => []])
{{-- Saisie de montant : clavier numérique, affichage "1 250 000", valeur brute (chiffres) liée à Livewire. --}}
<div x-data="{
        raw: @entangle($model).live,
        fmt(v) { return String(v ?? '').replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ' '); },
     }">
    <div class="m-money">
        <input type="text" inputmode="numeric" autocomplete="off" placeholder="0"
               class="m-input @error($model) m-input--error @enderror"
               :value="fmt(raw)"
               @input="raw = $event.target.value.replace(/\D/g, ''); $event.target.value = fmt(raw)">
        <span class="m-money__unit">FCFA</span>
    </div>
    @if ($quick)
        <div class="m-quick">
            @foreach ($quick as $label => $value)
                <button type="button" class="m-chip" data-haptic @click="raw = '{{ $value }}'">{{ $label }}</button>
            @endforeach
        </div>
    @endif
</div>
