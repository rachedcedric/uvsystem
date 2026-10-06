@php
    $score = $scoring['score'] ?? 0;
    $grade = $scoring['grade'] ?? 'E';
    $tone = $score >= 80 ? 'ok' : ($score >= 60 ? 'brand' : ($score >= 40 ? 'warn' : 'danger'));
@endphp
<div>
    <x-m.appbar :title="$agent->name" sub="Score de performance" :back="route('m.scoring')" />

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">Score global</div>
        <div class="m-hero__amount m-num">{{ $score }}<span class="m-hero__unit">/100 · Grade {{ $grade }}</span></div>
        <div class="m-hero__foot"><span>{{ $agent->phone }}{{ $agent->zone ? ' · '.$agent->zone : '' }}</span></div>
    </div>

    <x-m.section title="Détail du score">
        <div class="m-group">
            @foreach ($criteria as $c)
                @php($value = $scoring['details'][$c['key']] ?? 0)
                @php($pct = $c['max'] > 0 ? ($value / $c['max']) * 100 : 0)
                <div class="m-row" style="align-items:flex-start;flex-direction:column;gap:6px">
                    <div style="display:flex;justify-content:space-between;width:100%"><span class="m-row__title">{{ $c['label'] }}</span><span class="m-row__amount">{{ $value }}/{{ $c['max'] }}</span></div>
                    <span class="m-meter m-meter--{{ $pct >= 60 ? 'ok' : ($pct >= 40 ? 'watch' : 'critical') }}" style="display:block;width:100%;margin:0"><i style="width: {{ round($pct) }}%"></i></span>
                    <span class="m-row__sub">{{ $c['desc'] }}</span>
                </div>
            @endforeach
        </div>
    </x-m.section>

    <x-m.section title="Recommandations">
        <div class="m-group">
            @forelse (($scoring['recommendations'] ?? []) as $r)
                <x-m.row icon="light-bulb" tone="warn" :title="is_array($r) ? ($r['message'] ?? json_encode($r)) : $r" :chevron="false" />
            @empty
                <x-m.empty icon="check-circle" title="Aucune action nécessaire" text="Cet agent est en bonne santé financière." />
            @endforelse
        </div>
    </x-m.section>

    <div class="m-actions">
        <a href="{{ route('m.agents.show', $agent) }}" wire:navigate class="m-action" data-haptic><x-m.icon name="user" /> Fiche agent</a>
    </div>
</div>
