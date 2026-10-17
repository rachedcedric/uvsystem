<div>
    <x-m.appbar title="Nouvelle charge" :back="route('m.charges')" />

    <form wire:submit="save" class="m-form" novalidate>
        <x-m.field label="Libellé" :error="$errors->first('libelle')">
            <input type="text" wire:model="libelle" class="m-input @error('libelle') m-input--error @enderror" maxlength="255" placeholder="Ex. : Loyer boutique, carburant">
        </x-m.field>

        <x-m.field label="Montant" :error="$errors->first('amount')">
            <x-m.money model="amount" />
        </x-m.field>

        <x-m.field label="Catégorie" :error="$errors->first('categoryId')">
            <div class="m-chips" style="padding:0;flex-wrap:wrap">
                @foreach ($this->categories as $id => $name)
                    <button type="button" class="m-chip {{ $categoryId === $id ? 'is-on' : '' }}"
                            aria-pressed="{{ $categoryId === $id ? 'true' : 'false' }}"
                            wire:click="$set('categoryId', {{ $id }})" data-haptic>{{ $name }}</button>
                @endforeach
            </div>
        </x-m.field>

        <x-m.operation-date-time />

        <x-m.field label="Justificatif (facultatif)" :error="$errors->first('justificatif')">
            @if ($justificatif)
                <div class="m-select" style="cursor:default">
                    <span class="m-row__lead m-row__lead--ok"><x-m.icon name="paper-clip" class="m-ico-sm" /></span>
                    <span class="m-row__body">
                        <span class="m-row__title" style="display:block">{{ $justificatif->getClientOriginalName() }}</span>
                        <span class="m-row__sub" style="display:block">{{ \App\Support\Mobile\FileSize::human($justificatif->getSize()) }}</span>
                    </span>
                    <button type="button" class="m-iconbtn" wire:click="removeJustificatif" aria-label="Retirer le justificatif"><x-m.icon name="x-mark" /></button>
                </div>
            @else
                <label class="m-select" style="cursor:pointer">
                    <span class="m-row__lead"><x-m.icon name="paper-clip" class="m-ico-sm" /></span>
                    <span class="m-select__placeholder">Joindre une photo ou un PDF</span>
                    <input type="file" wire:model="justificatif" accept="image/jpeg,image/png,application/pdf" data-camera hidden>
                </label>
            @endif
            <div wire:loading wire:target="justificatif" class="m-row__sub" style="margin:6px 4px 0">Envoi du fichier…</div>
        </x-m.field>

        <x-m.field label="Description (facultatif)" :error="$errors->first('description')">
            <textarea wire:model="description" rows="2" class="m-input" style="height:auto;padding:12px 14px" placeholder="Informations complémentaires"></textarea>
        </x-m.field>

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save,justificatif">
                <span wire:loading.remove wire:target="save">Enregistrer la charge</span>
                <span wire:loading wire:target="save" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
        </div>
    </form>
</div>
