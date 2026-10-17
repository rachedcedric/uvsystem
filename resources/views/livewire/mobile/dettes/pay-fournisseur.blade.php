<div>
    <x-m.appbar title="Payer" :sub="$fournisseur->name" :back="route('m.dettes', ['v' => 'fournisseurs'])" />

    <form wire:submit="save" class="m-form" novalidate>
        <div class="m-callout">
            <dl>
                <dt>Dette totale</dt><dd>{{ \App\Support\Mobile\Fcfa::format($this->totals['distribue']) }}</dd>
                <dt>Déjà payé</dt><dd style="color:var(--ok)">{{ \App\Support\Mobile\Fcfa::format($this->totals['rembourse']) }}</dd>
                <dt>Reste à payer</dt><dd style="color:{{ $this->reste > 0 ? 'var(--danger)' : 'var(--ok)' }}">{{ \App\Support\Mobile\Fcfa::format($this->reste) }}</dd>
                @if ($this->numericAmount > 0 && $this->numericAmount <= $this->reste)
                    <dt>Après ce paiement</dt><dd>{{ $this->reste - $this->numericAmount > 0 ? \App\Support\Mobile\Fcfa::format($this->reste - $this->numericAmount) : 'Soldé ✓' }}</dd>
                @endif
            </dl>
        </div>

        <x-m.field label="Montant payé" :error="$errors->first('amount')">
            <x-m.money model="amount" />
            @if ($this->reste > 0)
                <div class="m-quick"><button type="button" class="m-chip" wire:click="payAll" data-haptic>Solder tout</button></div>
            @endif
        </x-m.field>

        @if ($this->numericAmount > $this->reste)
            <div class="m-callout m-callout--danger"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>Le montant dépasse le reste à payer.</span></div>
        @endif

        <x-m.field label="Mode de paiement">
            <div class="m-chips" style="padding:0;flex-wrap:wrap">
                @foreach ($this->methods as $code => $label)
                    <button type="button" class="m-chip {{ $method === (string) $code ? 'is-on' : '' }}" aria-pressed="{{ $method === (string) $code ? 'true' : 'false' }}" wire:click="$set('method', '{{ $code }}')" data-haptic>{{ $label }}</button>
                @endforeach
            </div>
        </x-m.field>

        <x-m.operation-date-time />

        <x-m.field label="Notes (facultatif)" :error="$errors->first('notes')">
            <textarea wire:model="notes" rows="2" class="m-input" style="height:auto;padding:12px 14px"></textarea>
        </x-m.field>

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save" @disabled($this->numericAmount > $this->reste)>
                <span wire:loading.remove wire:target="save">Enregistrer le paiement</span>
                <span wire:loading wire:target="save" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
        </div>
    </form>
</div>
