<div wire:poll.30s>
    <x-m.appbar title="Diagnostic" sub="Notifications" :back="route('m.more')" />

    <div class="m-hero m-hero--compact">
        <div class="m-hero__label">État des notifications</div>
        <div class="m-hero__amount m-hero__amount--status m-num">
            @if ($summary['error'] > 0) {{ $summary['error'] }} problème{{ $summary['error'] > 1 ? 's' : '' }}
            @elseif ($summary['warn'] > 0) {{ $summary['warn'] }} point{{ $summary['warn'] > 1 ? 's' : '' }} à revoir
            @else Tout est en ordre @endif
        </div>
        <div class="m-hero__foot"><span>{{ $summary['ok'] }} contrôle{{ $summary['ok'] > 1 ? 's' : '' }} réussi{{ $summary['ok'] > 1 ? 's' : '' }} sur {{ count($checks) }}</span></div>
    </div>

    <x-m.section title="Contrôles">
        <div class="m-group">
            @foreach ($checks as $c)
                @php($tone = ['ok' => 'ok', 'warn' => 'warn', 'error' => 'danger', 'info' => 'muted'][$c['status']])
                @php($icon = ['ok' => 'check-circle', 'warn' => 'exclamation-triangle', 'error' => 'x-circle', 'info' => 'information-circle'][$c['status']])
                <div class="m-row m-row--top" wire:key="chk-{{ $c['key'] }}">
                    <span class="m-row__lead m-row__lead--{{ $tone }}"><x-m.icon :name="$icon" class="m-ico-sm" /></span>
                    <span class="m-row__body">
                        <span class="m-row__title">{{ $c['label'] }}</span>
                        <span class="m-row__sub m-row__sub--detail">{{ $c['detail'] }}</span>
                        @if ($c['hint'])
                            <span class="m-row__sub m-selectable m-diagnostic__hint {{ $c['status'] === 'ok' ? '' : 'is-warning' }}">→ {{ $c['hint'] }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </x-m.section>

    <div class="m-callout m-callout--device"><x-m.icon name="device-phone-mobile" class="m-ico-sm" /><span data-device-push class="m-device-status"></span></div>

    <x-m.section title="Tester">
        <div class="m-button-stack">
            <button type="button" class="m-btn" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                <span wire:loading.remove wire:target="sendTest">Envoyer une notification de test</span>
                <span wire:loading wire:target="sendTest" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
            <button type="button" class="m-btn m-btn--ghost" wire:click="sendTestMail" wire:loading.attr="disabled" wire:target="sendTestMail">
                <span wire:loading.remove wire:target="sendTestMail">Envoyer un e-mail de test</span>
                <span wire:loading wire:target="sendTestMail" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
        </div>
        @if ($results)
            <div class="m-group m-group--separated">
                @foreach ($results as $r)
                    <x-m.row :icon="$r['ok'] ? 'check-circle' : 'x-circle'" :tone="$r['ok'] ? 'ok' : 'danger'" :chevron="false" title="{{ $r['text'] }}" />
                @endforeach
            </div>
        @endif
    </x-m.section>
</div>
