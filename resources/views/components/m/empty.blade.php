@props(['icon' => 'inbox', 'title', 'text' => null])
<div class="m-empty">
    <x-m.icon :name="$icon" />
    <strong>{{ $title }}</strong>
    @if ($text) <span>{{ $text }}</span> @endif
    {{ $slot }}
</div>
