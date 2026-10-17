<div>
    <x-m.appbar title="Rapports et exports" :back="route('m.more')" />

    <x-m.chips model="report" :current="$report" :options="['transactions' => 'Ventes', 'remboursements' => 'Remboursements', 'agents' => 'Situation des agents']" />

    <div class="m-form m-report-form">
        @if ($report !== 'agents')
            <x-m.field label="Agent">
                <x-m.agent-picker :agents="$agents" :selected="$selectedAgent" model="agentId" label="Tous les agents" id="report-agent" />
                @if ($selectedAgent)<button type="button" class="m-section__link m-report-reset" wire:click="$set('agentId', null)">Tous les agents</button>@endif
            </x-m.field>
            <div class="m-pair">
                <x-m.field label="Du"><input type="date" wire:model.live="from" class="m-input"></x-m.field>
                <x-m.field label="Au"><input type="date" wire:model.live="to" class="m-input"></x-m.field>
            </div>
        @endif

        @if ($report === 'transactions')
            <x-m.field label="Type">
                <x-m.chips model="type" :current="$type" :options="['' => 'Tous', 'distribution' => 'Distributions', 'retour' => 'Retours']" />
            </x-m.field>
        @elseif ($report === 'remboursements')
            <x-m.field label="Mode de paiement">
                <x-m.chips model="method" :current="$method" :options="['' => 'Tous'] + $methods" />
            </x-m.field>
        @else
            <x-m.field label="Statut">
                <x-m.chips model="status" :current="$status" :options="['' => 'Tous', 'active' => 'Actifs', 'inactive' => 'Inactifs', 'suspended' => 'Suspendus']" />
            </x-m.field>
            <x-m.field label="Dettes">
                <button type="button" role="switch" aria-checked="{{ $withDebtOnly ? 'true' : 'false' }}" class="m-switch {{ $withDebtOnly ? 'is-on' : '' }}" wire:click="$toggle('withDebtOnly')" data-haptic>
                    <span class="m-switch__knob"></span><span>Uniquement les agents avec une dette</span>
                </button>
            </x-m.field>
        @endif
    </div>

    <div class="m-actions">
        @if ($canExport)
            @if ($report !== 'remboursements')
                <a href="{{ route('m.exports.report', ['type' => $report, 'format' => 'pdf'] + $params) }}" target="_blank" rel="noopener" class="m-action" aria-label="Télécharger le rapport au format PDF" data-download data-haptic><x-m.icon name="document-arrow-down" /> PDF</a>
            @endif
            <a href="{{ route('m.exports.report', ['type' => $report, 'format' => 'xlsx'] + $params) }}" target="_blank" rel="noopener" class="m-action" aria-label="Télécharger le rapport au format Excel" data-download data-haptic><x-m.icon name="table-cells" /> Excel</a>
        @else
            <div class="m-callout m-callout--warn m-callout--fill"><span>Vous n'avez pas le droit d'exporter des rapports.</span></div>
        @endif
    </div>

    @if (auth()->user()->hasRole('master'))
        <div class="m-actions m-actions--separated">
            <a href="{{ route('exports.tenant-data') }}" target="_blank" rel="noopener" class="m-action" aria-label="Télécharger toutes mes données au format ZIP" data-download data-haptic><x-m.icon name="document-arrow-down" /> Exporter toutes mes données (ZIP)</a>
        </div>
    @endif
</div>
