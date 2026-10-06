@props(['name', 'solid' => false])
{{ svg(($solid ? 'heroicon-s-' : 'heroicon-o-').$name, $attributes->get('class', 'm-ico'), $attributes->except('class')->getAttributes()) }}
