@props(['label', 'error' => null, 'for' => null])
<div class="m-field">
    <label class="m-field__label" @if ($for) for="{{ $for }}" @endif>{{ $label }}</label>
    {{ $slot }}
    @if ($error) <div class="m-field__error">{{ $error }}</div> @endif
</div>
