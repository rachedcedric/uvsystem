@props(['href' => null, 'label', 'sheet' => null])
@if ($href)
    <a href="{{ $href }}" wire:navigate class="m-fab" data-haptic><x-m.icon name="plus" solid class="m-ico" />{{ $label }}</a>
@else
    <button type="button" class="m-fab" @if ($sheet) data-sheet-open="{{ $sheet }}" @endif><x-m.icon name="plus" solid class="m-ico" />{{ $label }}</button>
@endif
