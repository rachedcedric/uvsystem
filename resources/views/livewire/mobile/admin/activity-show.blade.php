@php($fmt = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'oui' : 'non') : ($v === null || $v === '' ? '—' : (string) $v)))
<div>
    <x-m.appbar title="Activité" :sub="$a->created_at->translatedFormat('j F Y, H:i:s')" :back="route('m.activite')" />

    <div class="m-section">
        <dl class="m-group" style="margin-bottom:0">
            <div class="m-kv"><dt>Action</dt><dd>{{ $a->description }}</dd></div>
            <div class="m-kv"><dt>Utilisateur</dt><dd>{{ $a->causer?->name ?? 'Système' }}</dd></div>
            <div class="m-kv"><dt>Module</dt><dd>{{ \App\Livewire\Mobile\Admin\ActivityLog::MODULES[$a->log_name] ?? ($a->log_name ?: '—') }}</dd></div>
            @if ($a->subject_type)<div class="m-kv"><dt>Objet</dt><dd>{{ class_basename($a->subject_type) }} #{{ $a->subject_id }}</dd></div>@endif
            @if ($a->event)<div class="m-kv"><dt>Événement</dt><dd>{{ $a->event }}</dd></div>@endif
        </dl>
    </div>

    @if ($changes)
        <x-m.section title="Modifications">
            <div class="m-group">
                @foreach ($changes as $field => $c)
                    <div class="m-row" style="flex-direction:column;align-items:flex-start;gap:3px">
                        <span class="m-row__title">{{ $field }}</span>
                        <span class="m-row__sub" style="white-space:normal">{{ $fmt($c['old']) }} → <b style="color:var(--fg)">{{ $fmt($c['new']) }}</b></span>
                    </div>
                @endforeach
            </div>
        </x-m.section>
    @endif

    @if ($extra)
        <x-m.section title="Détails">
            <dl class="m-group" style="margin-bottom:0">
                @foreach ($extra as $k => $v)<div class="m-kv"><dt>{{ $k }}</dt><dd>{{ $fmt($v) }}</dd></div>@endforeach
            </dl>
        </x-m.section>
    @endif
</div>
