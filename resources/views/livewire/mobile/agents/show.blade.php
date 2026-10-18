@php
    $level = $ratio >= 100 ? 'critical' : ($ratio >= 90 ? 'high' : ($ratio >= 70 ? 'watch' : 'ok'));
    $meta = [
        'vente' => ['Vente', 'arrow-up-right', null, '−', 'm-amount--out'],
        'retour' => ['Retour', 'arrow-down-left', 'ok', '+', 'm-amount--in'],
        'remboursement' => ['Remboursement', 'arrow-uturn-left', 'ok', '+', 'm-amount--in'],
    ];
@endphp
<div>
    <x-m.appbar :title="$a->name" :back="route('m.agents')">
        @if ($canEdit)
            <a href="{{ route('m.crud.edit', ['agents', $a->id]) }}" wire:navigate class="m-iconbtn" aria-label="Modifier" data-haptic>
                <x-m.icon name="pencil-square" />
            </a>
        @endif
    </x-m.appbar>

    <div class="m-profile">
        <div class="m-profile__avatar">{{ mb_strtoupper(mb_substr($a->name, 0, 2)) }}</div>
        <div>
            <div class="m-profile__name">{{ $a->name }}
                @if ($a->status !== 'active') <span class="m-badge">Inactif</span> @endif
            </div>
            <div class="m-profile__sub">{{ collect([$a->zone, $a->phone])->filter()->implode(' · ') ?: '—' }}</div>
        </div>
    </div>

    <div class="m-actions">
        @if ($canSell)
            <a href="{{ route('m.ventes.create', ['agent' => $a->id]) }}" wire:navigate class="m-action" data-haptic>
                <x-m.icon name="banknotes" /> Vendre
            </a>
        @endif
        @if ($canCollect)
            <a href="{{ route('m.remboursements.create', ['agent' => $a->id]) }}" wire:navigate class="m-action" data-haptic>
                <x-m.icon name="arrow-uturn-left" /> Encaisser
            </a>
        @endif
        @if ($canRemind)
            <button type="button" class="m-action" data-sheet-open="remind-sheet" data-haptic><x-m.icon name="bell-alert" /> Rappel</button>
        @endif
        @if ($a->phone)
            <a href="tel:{{ preg_replace('/\s+/', '', $a->phone) }}" class="m-action" data-haptic>
                <x-m.icon name="phone" /> Appeler
            </a>
        @endif
    </div>

    <div class="m-grid">
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>Dette actuelle</span></div>
            <div class="m-stat__value m-num" style="{{ $a->current_debt > 0 ? 'color:var(--warn)' : '' }}">{{ \App\Support\Mobile\Fcfa::number($a->current_debt) }}<span class="m-stat__unit"> FCFA</span></div>
            @if ($a->debt_limit > 0)
                <span class="m-meter m-meter--{{ $level }}" style="display:block"><i style="width: {{ min(100, round($ratio)) }}%"></i></span>
            @endif
        </div>
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="shield-check" class="m-ico-sm" /><span>Crédit disponible</span></div>
            <div class="m-stat__value m-num">{{ $unlimited ? 'Illimité' : \App\Support\Mobile\Fcfa::number($credit) }}@unless ($unlimited)<span class="m-stat__unit"> FCFA</span>@endunless</div>
            <div class="m-row__sub" style="margin-top:8px">Plafond {{ $a->debt_limit > 0 ? \App\Support\Mobile\Fcfa::format($a->debt_limit) : 'aucun' }}</div>
        </div>
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="arrow-up-right" class="m-ico-sm" /><span>Total distribué</span></div>
            <div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($totals['distribue']) }}<span class="m-stat__unit"> FCFA</span></div>
        </div>
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="arrow-uturn-left" class="m-ico-sm" /><span>Total remboursé</span></div>
            <div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($totals['rembourse']) }}<span class="m-stat__unit"> FCFA</span></div>
        </div>
    </div>

    <div class="m-group" style="margin-top:12px">
        <x-m.row :href="route('m.dettes.agent', $a)" icon="exclamation-triangle" tone="warn" title="Détail de la dette" sub="Historique complet, PDF et Excel" />
        <a href="{{ route('m.exports.fiche-agent', $a) }}" target="_blank" rel="noopener" class="m-row" aria-label="Télécharger la fiche PDF de {{ $a->name }}" data-download data-haptic>
            <span class="m-row__lead m-row__lead--muted"><x-m.icon name="document-text" class="m-ico-sm" /></span>
            <span class="m-row__body"><span class="m-row__title">Fiche agent (PDF)</span></span>
            <x-m.icon name="arrow-down-tray" class="m-row__chev" />
        </a>
    </div>

    <x-m.section title="Historique">
        <div class="m-group">
            @forelse ($timeline as $e)
                @php($m = $meta[$e['kind']])
                <x-m.row :href="$e['url']" :icon="$m[1]" :tone="$e['cancelled'] ? 'muted' : $m[2]" :title="$m[0]"
                         :sub="$e['ref'].' · '.$e['date']->translatedFormat('j M, H:i')">
                    <x-slot:end>
                        <span class="m-row__amount {{ $e['cancelled'] ? '' : $m[4] }}" style="display:block;{{ $e['cancelled'] ? 'text-decoration:line-through;color:var(--fg-3)' : '' }}">
                            {{ $m[3] }}{{ \App\Support\Mobile\Fcfa::number($e['amount']) }}
                        </span>
                    </x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="clock" title="Aucune opération" text="Les ventes et paiements de cet agent apparaîtront ici." />
            @endforelse
        </div>
    </x-m.section>

    @if ($canDelete)
        <button type="button" class="m-danger-link" data-sheet-open="delete-sheet">
            <x-m.icon name="trash" class="m-ico-sm" /> Supprimer l'agent
        </button>
        <div id="delete-sheet" class="m-sheet" role="dialog" aria-label="Confirmer la suppression">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Supprimer « {{ $a->name }} » ?</div>
            <p style="margin:0 20px 16px;color:var(--fg-2)">L'agent disparaît des listes. Ses ventes et remboursements sont conservés dans l'historique.</p>
            <div style="padding:0 16px;display:grid;gap:10px">
                <button type="button" class="m-btn" style="background:var(--danger)" wire:click="delete" @click="MobileUI.closeSheets()">Oui, supprimer</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close>Annuler</button>
            </div>
        </div>
    @endif

    @if ($canRemind)
        <div id="remind-sheet" class="m-sheet" role="dialog" aria-label="Rappel de paiement">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Envoyer un rappel à {{ $a->name }}</div>
            <div style="padding:0 16px;display:grid;gap:12px">
                <div class="m-chips" style="padding:0;flex-wrap:wrap">
                    @foreach (['sms' => 'SMS', 'email' => 'E-mail', 'both' => 'SMS + e-mail'] as $k => $l)
                        <button type="button" class="m-chip {{ $channel === $k ? 'is-on' : '' }}" aria-pressed="{{ $channel === $k ? 'true' : 'false' }}" wire:click="$set('channel', '{{ $k }}')">{{ $l }}</button>
                    @endforeach
                </div>
                <textarea wire:model="customMessage" rows="3" class="m-input" style="height:auto;padding:12px 14px" placeholder="Message personnalisé (facultatif). Laissez vide pour le message par défaut."></textarea>
                <button type="button" class="m-btn" wire:click="sendReminder" @click="MobileUI.closeSheets()">Envoyer le rappel</button>
            </div>
        </div>
    @endif
</div>
