<div>
    <x-m.appbar title="Payer" :sub="$fournisseur->name" :back="route('m.dettes.fournisseur.show', $fournisseur)" />

    <form wire:submit="save" class="m-form" novalidate>
        <div class="m-callout">
            <dl>
                <dt>Dette totale</dt><dd>{{ \App\Support\Mobile\Fcfa::format($this->totals['distribue']) }}</dd>
                <dt>Déjà payé</dt><dd style="color:var(--ok)">{{ \App\Support\Mobile\Fcfa::format($this->totals['rembourse']) }}</dd>
                <dt>{{ $this->reste < 0 ? 'Découvert actuel' : 'Reste à payer' }}</dt><dd style="color:{{ $this->reste > 0 ? 'var(--danger)' : ($this->reste < 0 ? 'var(--ok)' : 'var(--fg)') }}">{{ \App\Support\Mobile\Fcfa::format($this->reste) }}</dd>
                @if ($this->numericAmount > 0)
                    <dt>Après ce paiement</dt><dd style="color:{{ $this->apres > 0 ? 'var(--danger)' : ($this->apres < 0 ? 'var(--ok)' : 'var(--fg)') }}">{{ $this->apres == 0 ? 'Soldé ✓' : \App\Support\Mobile\Fcfa::format($this->apres).($this->apres < 0 ? ' (découvert)' : '') }}</dd>
                @endif
            </dl>
        </div>

        <x-m.field label="Montant payé" :error="$errors->first('amount')">
            <x-m.money model="amount" />
            @if ($this->reste > 0)
                <div class="m-quick"><button type="button" class="m-chip" wire:click="payAll" data-haptic>Solder tout</button></div>
            @endif
        </x-m.field>

        @if ($this->numericAmount > 0 && $this->apres < 0)
            <x-m.callout tone="info" icon="information-circle">L'excédent de {{ \App\Support\Mobile\Fcfa::format(-$this->apres) }} sera enregistré comme découvert (avance) et déduit automatiquement des prochains approvisionnements à crédit.</x-m.callout>
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
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Enregistrer le paiement</span>
                <span wire:loading wire:target="save" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
        </div>
    </form>
</div>
