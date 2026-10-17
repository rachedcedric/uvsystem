@props(['label', 'error' => null, 'for' => null, 'hint' => null, 'group' => false])
<div class="m-field">
    @if ($group)
        <div class="m-field__label" @if ($for) id="{{ $for }}-label" @endif>{{ $label }}</div>
    @else
        <label class="m-field__label" @if ($for) for="{{ $for }}" @endif>{{ $label }}</label>
    @endif
    {{ $slot }}
    @if ($hint) <div @if ($for) id="{{ $for }}-hint" @endif class="m-row__sub m-field__hint">{{ $hint }}</div> @endif
    @if ($error) <div @if ($for) id="{{ $for }}-error" @endif class="m-field__error" role="alert">{{ $error }}</div> @endif
</div>
