@props(['title', 'sub' => null, 'back' => null])
<header class="m-appbar {{ $back ? 'm-appbar--back' : '' }}">
    @if ($back)
        <a href="{{ $back }}" wire:navigate class="m-iconbtn" aria-label="Retour" data-haptic>
            <x-m.icon name="arrow-left" />
        </a>
    @endif
    <h1 class="m-appbar__title">
        {{ $title }}
        @if ($sub)
            <span class="m-appbar__sub">{{ $sub }}</span>
        @endif
    </h1>
    {{ $slot }}
</header>
