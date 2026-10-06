<div>
    <x-m.appbar title="Approvisionner" :back="route('m.approvisionnements')" />

    <form wire:submit="save" class="m-form" novalidate>
        <div class="m-callout">
            <dl><dt>Solde UV disponible</dt><dd>{{ \App\Support\Mobile\Fcfa::format($this->balance) }}</dd>
                @if ($this->numericAmount > 0)
                    <dt>Après approvisionnement</dt><dd style="color:var(--ok)">{{ \App\Support\Mobile\Fcfa::format($this->balance + $this->numericAmount) }}</dd>
                @endif
            </dl>
        </div>

        <x-m.field label="Fournisseur" :error="$errors->first('fournisseurId')">
            <div class="m-chips" style="padding:0;flex-wrap:wrap">
                @foreach ($this->fournisseurs as $f)
                    <button type="button" class="m-chip {{ $fournisseurId === $f->id ? 'is-on' : '' }}"
                            wire:click="$set('fournisseurId', {{ $f->id }})" data-haptic>{{ $f->name }}</button>
                @endforeach
            </div>
        </x-m.field>

        <x-m.field label="Montant" :error="$errors->first('amount')">
            <x-m.money model="amount" :quick="['500 000' => 500000, '1 000 000' => 1000000, '3 000 000' => 3000000, '5 000 000' => 5000000]" />
        </x-m.field>

        <x-m.operation-date-time />

        <x-m.field label="Notes (facultatif)" :error="$errors->first('notes')">
            <textarea wire:model="notes" rows="2" class="m-input" style="height:auto;padding:12px 14px"></textarea>
        </x-m.field>

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Enregistrer l'approvisionnement</span>
                <span wire:loading wire:target="save" class="m-spin"></span>
            </button>
        </div>
    </form>
</div>
