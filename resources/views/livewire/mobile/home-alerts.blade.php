<div>
    @if ($alerts->isNotEmpty())
        <x-m.section title="Dettes à surveiller">
            <div class="m-group">
                @foreach ($alerts as $alert)
                    @php
                        $tone = match ($alert['level']) { 'critical' => 'danger', 'high' => 'warn', default => 'warn' };
                        $href = \Illuminate\Support\Facades\Route::has('m.agents.show') ? route('m.agents.show', $alert['id']) : null;
                    @endphp
                    <x-m.row :href="$href" :title="$alert['name']" :lead="mb_strtoupper(mb_substr($alert['name'], 0, 2))" :tone="$tone">
                        <x-slot:body>
                            <span class="m-meter m-meter--{{ $alert['level'] }}" style="display:block">
                                <i style="width: {{ min(100, round($alert['ratio'])) }}%"></i>
                            </span>
                        </x-slot:body>
                        <x-slot:end>
                            <span class="m-row__amount" style="display:block">{{ \App\Support\Mobile\Fcfa::format($alert['debt']) }}</span>
                            <span class="m-badge m-badge--{{ $alert['level'] === 'critical' ? 'danger' : 'warn' }}">{{ round($alert['ratio']) }} %</span>
                        </x-slot:end>
                    </x-m.row>
                @endforeach
            </div>
        </x-m.section>
    @endif
</div>
