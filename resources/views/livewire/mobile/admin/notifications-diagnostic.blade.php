<div wire:poll.30s>
    <x-m.appbar title="Diagnostic" sub="Notifications" :back="route('m.more')" />

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">État des notifications</div>
        <div class="m-hero__amount m-num" style="font-size:36px">
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
                <div class="m-row" style="align-items:flex-start" wire:key="chk-{{ $c['key'] }}">
                    <span class="m-row__lead m-row__lead--{{ $tone }}"><x-m.icon :name="$icon" class="m-ico-sm" /></span>
                    <span class="m-row__body">
                        <span class="m-row__title" style="display:block;white-space:normal">{{ $c['label'] }}</span>
                        <span class="m-row__sub" style="display:block;white-space:normal;color:var(--fg-2)">{{ $c['detail'] }}</span>
                        @if ($c['hint'])
                            <span class="m-row__sub m-selectable" style="display:block;white-space:normal;margin-top:4px;color:{{ $c['status'] === 'ok' ? 'var(--fg-3)' : 'var(--warn)' }}">→ {{ $c['hint'] }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </x-m.section>

    <div class="m-callout" style="margin:12px 12px 0"><x-m.icon name="device-phone-mobile" class="m-ico-sm" /><span data-device-push style="font-size:13.5px"></span></div>

    <x-m.section title="Tester">
        <div style="padding:0 12px;display:grid;gap:10px">
            <button type="button" class="m-btn" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                <span wire:loading.remove wire:target="sendTest">Envoyer une notification de test</span>
                <span wire:loading wire:target="sendTest" class="m-spin"></span>
            </button>
            <button type="button" class="m-btn m-btn--ghost" wire:click="sendTestMail" wire:loading.attr="disabled" wire:target="sendTestMail">
                <span wire:loading.remove wire:target="sendTestMail">Envoyer un e-mail de test</span>
                <span wire:loading wire:target="sendTestMail" class="m-spin"></span>
            </button>
        </div>
        @if ($results)
            <div class="m-group" style="margin-top:12px">
                @foreach ($results as $r)
                    <x-m.row :icon="$r['ok'] ? 'check-circle' : 'x-circle'" :tone="$r['ok'] ? 'ok' : 'danger'" :chevron="false" title="{{ $r['text'] }}" />
                @endforeach
            </div>
        @endif
    </x-m.section>
</div>
