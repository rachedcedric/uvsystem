<div wire:poll.60s.visible>
    <x-m.appbar title="Dettes" :back="route('m.more')">
        @if ($mode === 'agents' && ($canRemind ?? false))
            <button type="button" class="m-iconbtn" data-sheet-open="remind-sheet" aria-label="Envoyer les rappels" data-haptic><x-m.icon name="bell-alert" /></button>
        @endif
    </x-m.appbar>

    @if (count($scopes) > 1)
        <x-m.chips model="scope" :current="$mode" :options="$scopes" />
    @endif

    @if ($mode === 'agents')
        <div class="m-grid">
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>Non remboursé</span></div>
                <div class="m-stat__value m-num" style="color:var(--warn)">{{ \App\Support\Mobile\Fcfa::number($summary['outstanding']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="users" class="m-ico-sm" /><span>Agents endettés</span></div>
                <div class="m-stat__value m-num">{{ $summary['count'] }}</div>
            </div>
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="arrow-up-right" class="m-ico-sm" /><span>Dette du jour</span></div>
                <div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($summary['debtToday']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="arrow-uturn-left" class="m-ico-sm" /><span>Payé aujourd'hui</span></div>
                <div class="m-stat__value m-num" style="color:var(--ok)">{{ \App\Support\Mobile\Fcfa::number($summary['repaidToday']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
        </div>
    @else
        <div class="m-grid">
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="truck" class="m-ico-sm" /><span>Total à crédit</span></div>
                <div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($summary['totalDette']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="arrow-uturn-left" class="m-ico-sm" /><span>Déjà payé</span></div>
                <div class="m-stat__value m-num" style="color:var(--ok)">{{ \App\Support\Mobile\Fcfa::number($summary['totalRembourse']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
            <div class="m-stat" style="grid-column: span 2">
                <div class="m-stat__label"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>Reste à payer aux fournisseurs</span></div>
                <div class="m-stat__value m-num" style="color:var(--warn)">{{ \App\Support\Mobile\Fcfa::number($summary['resteAPayer']) }}<span class="m-stat__unit">FCFA</span></div>
            </div>
        </div>
    @endif

    <div style="margin-top:14px"><x-m.search wire:model.live.debounce.300ms="search" :placeholder="$mode === 'agents' ? 'Rechercher un agent' : 'Rechercher un fournisseur'" /></div>

    <div class="m-group" style="margin-top:12px">
        @if ($mode === 'agents')
            @forelse ($rows as $agent)
                @php($ratio = $agent->debt_limit > 0 ? ($agent->current_debt / $agent->debt_limit) * 100 : 0)
                @php($level = $ratio >= 100 ? 'critical' : ($ratio >= 90 ? 'high' : ($ratio >= 70 ? 'watch' : 'ok')))
                <x-m.row :href="route('m.dettes.agent', $agent)" wire:key="d{{ $agent->id }}" :lead="mb_strtoupper(mb_substr($agent->name, 0, 2))"
                         :tone="in_array($level, ['critical']) ? 'danger' : (in_array($level, ['high', 'watch']) ? 'warn' : null)"
                         :title="$agent->name"
                         :sub="collect([$agent->debt_today > 0 ? '+'.\App\Support\Mobile\Fcfa::format($agent->debt_today).' ce jour' : null, $agent->repaid_today > 0 ? '−'.\App\Support\Mobile\Fcfa::format($agent->repaid_today).' payés' : null])->filter()->implode(' · ') ?: ($agent->phone ?: '—')">
                    @if ($agent->debt_limit > 0)
                        <x-slot:body><span class="m-meter m-meter--{{ $level }}" style="display:block"><i style="width: {{ min(100, round($ratio)) }}%"></i></span></x-slot:body>
                    @endif
                    <x-slot:end><span class="m-row__amount">{{ \App\Support\Mobile\Fcfa::number($agent->current_debt) }}</span></x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="check-circle" title="Aucune dette active" text="Tous les agents sont à jour." />
            @endforelse
        @else
            @forelse ($rows as $row)
                @php($f = $row['model'])
                <x-m.row :href="$canPay && $row['reste'] > 0 ? route('m.dettes.fournisseur', $f) : null" wire:key="f{{ $f->id }}" icon="building-library"
                         :tone="$row['reste'] > 0 ? 'warn' : 'ok'" :title="$f->name" :sub="$f->contact ?: 'Fournisseur à crédit'">
                    <x-slot:end>
                        <span class="m-row__amount" style="display:block">{{ \App\Support\Mobile\Fcfa::number(max(0, $row['reste'])) }}</span>
                        <span class="m-badge {{ $row['reste'] > 0 ? 'm-badge--warn' : 'm-badge--ok' }}">{{ $row['reste'] > 0 ? 'À payer' : 'Soldé' }}</span>
                    </x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="check-circle" title="Aucun fournisseur à crédit" text="Aucun fournisseur « Agent Premium » actif." />
            @endforelse
        @endif
    </div>

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($mode === 'fournisseurs' && \Illuminate\Support\Facades\Route::has('m.remboursements-fournisseurs'))
        <a href="{{ route('m.remboursements-fournisseurs') }}" wire:navigate class="m-more">Historique des paiements fournisseurs</a>
    @endif

    @if ($mode === 'agents' && ($canRemind ?? false))
        <div id="remind-sheet" class="m-sheet" role="dialog" aria-label="Rappels de dettes">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Envoyer les rappels de dettes ?</div>
            <p style="margin:0 20px 16px;color:var(--fg-2)">Un rappel est créé pour chaque agent endetté qui n'a pas encore été notifié pendant le cycle de 2 heures en cours.</p>
            <div style="padding:0 16px;display:grid;gap:10px">
                <button type="button" class="m-btn" wire:click="sendReminders" @click="MobileUI.closeSheets()">Envoyer maintenant</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close>Annuler</button>
            </div>
        </div>
    @endif
</div>
