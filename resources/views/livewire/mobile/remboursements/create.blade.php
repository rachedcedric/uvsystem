<div>
    <x-m.appbar title="Encaisser" :back="route('m.remboursements')" />

    <form wire:submit="save" class="m-form" novalidate>
        <x-m.field label="Agent" :error="$errors->first('agentId')">
            <x-m.agent-picker :agents="$this->agents" :selected="$this->agent" model="agentId" label="Agents avec une dette" />
        </x-m.field>

        @if ($this->agent)
            <div class="m-callout">
                <dl>
                    <dt>Reste à payer</dt>
                    <dd style="color: {{ $this->debt > 0 ? 'var(--danger)' : 'var(--ok)' }}">{{ \App\Support\Mobile\Fcfa::format($this->debt) }}</dd>
                    @if ($this->numericAmount > 0 && $this->numericAmount <= $this->debt)
                        <dt>Après ce paiement</dt>
                        <dd style="color: {{ $this->remaining > 0 ? 'var(--warn)' : 'var(--ok)' }}">
                            {{ $this->remaining > 0 ? \App\Support\Mobile\Fcfa::format($this->remaining) : 'Soldé ✓' }}
                        </dd>
                    @endif
                </dl>
            </div>
        @endif

        <x-m.field label="Montant payé" :error="$errors->first('amount')">
            <x-m.money model="amount" />
            @if ($this->agent && $this->debt > 0)
                <div class="m-quick">
                    <button type="button" class="m-chip" wire:click="payAll" data-haptic>Solder tout</button>
                </div>
            @endif
        </x-m.field>

        @if ($this->numericAmount > $this->debt && $this->agent)
            <div class="m-callout m-callout--danger">
                <x-m.icon name="exclamation-triangle" class="m-ico-sm" />
                <span>Le montant dépasse le reste à payer.</span>
            </div>
        @endif

        <x-m.field label="Mode de paiement" :error="$errors->first('method')">
            <div class="m-chips" style="padding:0;flex-wrap:wrap">
                @foreach ($this->methods as $code => $label)
                    <button type="button" class="m-chip {{ $method === (string) $code ? 'is-on' : '' }}"
                            aria-pressed="{{ $method === (string) $code ? 'true' : 'false' }}"
                            wire:click="$set('method', '{{ $code }}')" data-haptic>{{ $label }}</button>
                @endforeach
            </div>
        </x-m.field>

        <x-m.operation-date-time />

        <x-m.field label="Notes (facultatif)" :error="$errors->first('notes')">
            <textarea wire:model="notes" rows="2" class="m-input" style="height:auto;padding:12px 14px" placeholder="Informations complémentaires"></textarea>
        </x-m.field>

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save"
                    @disabled($this->agent && $this->numericAmount > $this->debt)>
                <span wire:loading.remove wire:target="save">Enregistrer le paiement</span>
                <span wire:loading wire:target="save" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
        </div>
    </form>
</div>
