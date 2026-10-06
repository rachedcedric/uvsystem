<div>
    <x-m.appbar title="Scoring des agents" sub="Volume, régularité, paiement et dette" :back="route('m.more')" />

    <div class="m-grid">
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="trophy" class="m-ico-sm" /><span>Score moyen</span></div><div class="m-stat__value m-num">{{ $average }}<span class="m-stat__unit">/100</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="users" class="m-ico-sm" /><span>Agents notés</span></div><div class="m-stat__value m-num">{{ $total }}</div></div>
        <div class="m-stat"><div class="m-stat__label"><span>Excellents</span></div><div class="m-stat__value" style="color:var(--ok)">{{ $counts['excellent'] ?? 0 }}</div></div>
        <div class="m-stat"><div class="m-stat__label"><span>Faibles</span></div><div class="m-stat__value" style="color:var(--danger)">{{ $counts['poor'] ?? 0 }}</div></div>
    </div>

    <x-m.chips model="grade" :current="$grade" :options="\App\Livewire\Mobile\Analytics\Scoring::BANDS" />

    <div class="m-group" style="margin-top:12px">
        @forelse ($rows as $s)
            <x-m.row :href="route('m.scoring.agent', $s['id'])" wire:key="s{{ $s['id'] }}"
                     :lead="$s['rank']" :tone="['excellent' => 'ok', 'good' => null, 'average' => 'warn', 'poor' => 'danger'][$s['band']]"
                     :title="$s['name']" :sub="count($s['recommendations']) ? count($s['recommendations']).' action(s) conseillée(s)' : 'Aucune action'">
                <x-slot:end>
                    <span class="m-row__amount" style="display:block">{{ $s['score'] }}/100</span>
                    <span class="m-badge {{ ['excellent' => 'm-badge--ok', 'good' => 'm-badge--brand', 'average' => 'm-badge--warn', 'poor' => 'm-badge--danger'][$s['band']] }}">Grade {{ $s['grade'] }}</span>
                </x-slot:end>
            </x-m.row>
        @empty
            <x-m.empty icon="trophy" title="Aucun agent" text="Aucun agent ne correspond à ce filtre." />
        @endforelse
    </div>
</div>
