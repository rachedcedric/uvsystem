@props(['tone' => 'info', 'icon' => null])
{{-- Encadré d'information. Tons : info (neutre), warn, danger. Les alertes sont annoncées (role="alert"). --}}
<div {{ $attributes->class(['m-callout', 'm-callout--'.$tone]) }} @if ($tone === 'danger') role="alert" @endif>
    @if ($icon)<x-m.icon :name="$icon" class="m-ico-sm" />@endif
    {{ $slot }}
</div>
