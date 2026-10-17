@props(['href' => null, 'title', 'sub' => null, 'lead' => null, 'icon' => null, 'tone' => null, 'chevron' => null, 'external' => false])
@php
    $tag = $href ? 'a' : 'div';
    $showChevron = $chevron ?? (bool) $href;
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @unless ($external) wire:navigate @endunless @endif {{ $attributes->class(['m-row']) }}>
    @if ($icon || $lead)
        <span class="m-row__lead {{ $tone ? 'm-row__lead--'.$tone : '' }}">
            @if ($icon)
                <x-m.icon :name="$icon" class="m-ico-sm" />
            @else
                {{ $lead }}
            @endif
        </span>
    @endif
    <span class="m-row__body">
        <span class="m-row__title">{{ $title }}</span>
        @if ($sub)
            <span class="m-row__sub">{{ $sub }}</span>
        @endif
        {{ $body ?? '' }}
    </span>
    @isset($end)
        <span class="m-row__end">{{ $end }}</span>
    @endisset
    @if ($showChevron)
        <x-m.icon name="chevron-right" class="m-row__chev" />
    @endif
</{{ $tag }}>
