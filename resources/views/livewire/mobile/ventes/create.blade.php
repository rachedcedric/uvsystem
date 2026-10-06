<div>
    <x-m.appbar title="Nouvelle vente" :back="route('m.ventes')" />

    <form wire:submit="save" class="m-form" novalidate>
        <x-m.field label="Agent" :error="$errors->first('agentId')">
            <x-m.agent-picker :agents="$this->agents" :selected="$this->agent" model="agentId" />
        </x-m.field>

        @if ($this->agent)
            <div class="m-callout">
                <dl>
                    <dt>Dette actuelle</dt>
                    <dd>{{ \App\Support\Mobile\Fcfa::format($this->agent->current_debt) }}</dd>
                    <dt>Crédit disponible</dt>
                    <dd>{{ $this->unlimited ? 'Illimité' : \App\Support\Mobile\Fcfa::format($this->credit) }}</dd>
                    <dt>Solde UV disponible</dt>
                    <dd>{{ \App\Support\Mobile\Fcfa::format($this->masterBalance) }}</dd>
                </dl>
            </div>
        @endif

        <x-m.field label="Montant" :error="$errors->first('amount')">
            <x-m.money model="amount" :quick="['25 000' => 25000, '50 000' => 50000, '100 000' => 100000, '250 000' => 250000]" />
        </x-m.field>

        @if ($this->warning)
            <div class="m-callout m-callout--danger">
                <x-m.icon name="exclamation-triangle" class="m-ico-sm" />
                <span>{{ $this->warning }}</span>
            </div>
        @endif

        <x-m.operation-date-time />

        <x-m.field label="Notes (facultatif)" :error="$errors->first('notes')">
            <textarea wire:model="notes" rows="2" class="m-input" style="height:auto;padding:12px 14px" placeholder="Informations complémentaires"></textarea>
        </x-m.field>

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save" @disabled($this->warning)>
                <span wire:loading.remove wire:target="save">Enregistrer la vente</span>
                <span wire:loading wire:target="save" class="m-spin"></span>
            </button>
        </div>
    </form>
</div>
