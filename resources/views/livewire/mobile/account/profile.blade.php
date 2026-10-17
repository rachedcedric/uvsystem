<div>
    <x-m.appbar title="Profil de l’entreprise" :back="route('m.more')" />

    <form wire:submit="save" class="m-form" novalidate>
        <x-m.field label="Nom affiché" :error="$errors->first('name')">
            <input type="text" wire:model="name" class="m-input @error('name') m-input--error @enderror" maxlength="255" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="Raison sociale (facultatif)" :error="$errors->first('legal_name')">
            <input type="text" wire:model="legal_name" class="m-input" maxlength="255" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="Logo (facultatif)" :error="$errors->first('logo')">
            @if ($logo)
                <div class="m-select" style="cursor:default">
                    <span class="m-row__lead m-row__lead--ok"><x-m.icon name="photo" class="m-ico-sm" /></span>
                    <span class="m-row__body">
                        <span class="m-row__title" style="display:block">{{ $logo->getClientOriginalName() }}</span>
                        <span class="m-row__sub" style="display:block">{{ \App\Support\Mobile\FileSize::human($logo->getSize()) }} · sera réduit automatiquement</span>
                    </span>
                    <button type="button" class="m-iconbtn" wire:click="$set('logo', null)" aria-label="Retirer ce fichier"><x-m.icon name="x-mark" /></button>
                </div>
            @else
                @if ($hasLogo)
                    <div class="m-select" style="cursor:default">
                        <span class="m-row__lead" style="overflow:hidden;background:#fff">
                            <img src="{{ route('tenant.branding.logo', ['v' => $logoVersion], false) }}" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
                        </span>
                        <span class="m-row__body"><span class="m-row__title" style="display:block">Logo actuel</span></span>
                        @if ($canEdit)
                            <button type="button" class="m-iconbtn" wire:click="removeLogoChoice" aria-label="Retirer le logo"><x-m.icon name="trash" /></button>
                        @endif
                    </div>
                @endif
                @if ($canEdit)
                    <label class="m-select" style="cursor:pointer;margin-top:{{ $hasLogo ? '8px' : '0' }}">
                        <span class="m-row__lead"><x-m.icon name="photo" class="m-ico-sm" /></span>
                        <span class="m-select__placeholder">{{ $hasLogo ? 'Remplacer le logo' : 'Choisir une image' }}</span>
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" data-camera hidden>
                    </label>
                @endif
            @endif
            <div wire:loading wire:target="logo" class="m-row__sub" style="margin:6px 4px 0">Envoi du fichier…</div>
        </x-m.field>

        <x-m.field label="Responsable" :error="$errors->first('contact_name')">
            <input type="text" wire:model="contact_name" class="m-input" maxlength="255" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="E-mail" :error="$errors->first('contact_email')">
            <input type="email" wire:model="contact_email" class="m-input @error('contact_email') m-input--error @enderror" maxlength="255" inputmode="email" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="Téléphone" :error="$errors->first('contact_phone')">
            <input type="tel" wire:model="contact_phone" class="m-input" maxlength="30" inputmode="tel" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="Site web" :error="$errors->first('website')">
            <input type="url" wire:model="website" class="m-input @error('website') m-input--error @enderror" maxlength="255" inputmode="url" placeholder="https://" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="Adresse" :error="$errors->first('address')">
            <textarea wire:model="address" rows="2" class="m-input" style="height:auto;padding:12px 14px" maxlength="500" @disabled(! $canEdit)></textarea>
        </x-m.field>

        <x-m.field label="Ville" :error="$errors->first('city')">
            <input type="text" wire:model="city" class="m-input" maxlength="120" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="Pays" :error="$errors->first('country')">
            <input type="text" wire:model="country" class="m-input" maxlength="120" @disabled(! $canEdit)>
        </x-m.field>

        <x-m.field label="Pied de page des documents" :error="$errors->first('document_footer')">
            <textarea wire:model="document_footer" rows="3" class="m-input" style="height:auto;padding:12px 14px" maxlength="500" placeholder="Adresse, téléphone, informations légales…" @disabled(! $canEdit)></textarea>
        </x-m.field>

        @if ($canEdit)
            <div class="m-submit">
                <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save,logo">
                    <span wire:loading.remove wire:target="save">Enregistrer le profil</span>
                    <span wire:loading wire:target="save" class="m-spin" role="status" aria-label="Chargement en cours"></span>
                </button>
            </div>
        @endif
    </form>
</div>
