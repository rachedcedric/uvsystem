@props(['title', 'link' => null, 'linkLabel' => 'Voir tout'])
<section class="m-section">
    <div class="m-section__head">
        <h2 class="m-section__title">{{ $title }}</h2>
        @if ($link)
            <a href="{{ $link }}" wire:navigate class="m-section__link">{{ $linkLabel }}</a>
        @endif
    </div>
    {{ $slot }}
</section>
