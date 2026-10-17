@props(['options', 'model', 'current'])
<div class="m-chips" data-no-ptr>
    @foreach ($options as $value => $label)
        <button type="button" class="m-chip {{ (string) $current === (string) $value ? 'is-on' : '' }}"
            aria-pressed="{{ (string) $current === (string) $value ? 'true' : 'false' }}"
                wire:click="$set('{{ $model }}', '{{ $value }}')" data-haptic>{{ $label }}</button>
    @endforeach
</div>
