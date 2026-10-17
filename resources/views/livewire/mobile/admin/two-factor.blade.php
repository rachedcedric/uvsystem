<div>
    <x-m.appbar title="Sécurité du compte" sub="Double authentification" :back="route('m.more')" />

    <div class="m-group m-group--top-compact">
        <x-m.row :icon="$enabled ? 'shield-check' : 'shield-exclamation'" :tone="$enabled ? 'ok' : 'warn'" :chevron="false"
                 :title="$enabled ? 'Double authentification activée' : 'Double authentification désactivée'"
                 :sub="$enabled ? 'Votre compte est protégé par un code à usage unique.' : 'Ajoutez une protection avec une application d\'authentification.'" />
    </div>

    <div class="m-group m-group--separated">
        {{-- Page autonome (hors Livewire) : lien classique, sans wire:navigate --}}
        <a href="{{ route('security.sessions') }}" class="m-row">
            <span class="m-row__lead"><x-m.icon name="device-phone-mobile" class="m-ico-sm" /></span>
            <span class="m-row__body">
                <span class="m-row__title">Appareils connectés et mot de passe</span>
                <span class="m-row__sub">Déconnecter un appareil, changer le mot de passe</span>
            </span>
            <x-m.icon name="chevron-right" class="m-row__chev" />
        </a>
    </div>

    @if (! $enabled && ! $showSetup)
        <div class="m-primary-action"><button type="button" class="m-btn" wire:click="startSetup">Activer la protection</button></div>
    @endif

    @if ($showSetup)
        <x-m.section title="1. Scannez le code">
            <div class="m-group m-two-factor-setup">
                <div class="m-two-factor-qr">{!! $qrCode !!}</div>
                <p class="m-help-text">Ouvrez Google Authenticator (ou équivalent) et scannez ce code, ou saisissez la clé :</p>
                <p class="m-selectable m-num m-two-factor-secret">{{ $secret }}</p>
            </div>
        </x-m.section>
        <x-m.section title="2. Saisissez le code à 6 chiffres">
            <form wire:submit="confirmSetup" class="m-form" novalidate>
                <x-m.field label="Code" :error="$errors->first('code')">
                    <input type="text" inputmode="numeric" maxlength="6" wire:model="code" class="m-input @error('code') m-input--error @enderror" autocomplete="one-time-code" placeholder="000000">
                </x-m.field>
                <button type="submit" class="m-btn">Valider et activer</button>
                <button type="button" class="m-btn m-btn--ghost m-btn--separated" wire:click="cancelSetup">Annuler</button>
            </form>
        </x-m.section>
    @endif

    @if ($enabled)
        <x-m.section title="Codes de récupération">
            <div class="m-group m-two-factor-recovery">
                <p class="m-row__sub m-help-text m-help-text--flush">Chaque code ne sert qu'une fois si vous perdez votre téléphone. Conservez-les en lieu sûr.</p>
                <div class="m-selectable m-num m-recovery-codes">
                    @forelse ($recoveryCodes as $c)<span>{{ $c }}</span>@empty<span class="m-row__sub">Aucun code restant.</span>@endforelse
                </div>
            </div>
            <div class="m-button-stack m-button-stack--separated">
                <button type="button" class="m-btn m-btn--ghost" x-data @click="if (confirm('Générer de nouveaux codes ? Les anciens ne fonctionneront plus.')) $wire.regenerate()">Générer de nouveaux codes</button>
                @if (app(\App\Security\TwoFactorGate::class)->mayDisable(auth()->user()))
                    <input type="password" wire:model="password" placeholder="Mot de passe pour confirmer" autocomplete="current-password" class="m-input">
                    @error('password')<div class="m-error">{{ $message }}</div>@enderror
                    <button type="button" class="m-btn m-btn--danger" x-data @click="if (confirm('Désactiver la double authentification ?')) $wire.disable()">Désactiver la protection</button>
                @else
                    <div class="m-row__sub">Votre rôle impose la double authentification : elle ne peut pas être désactivée.</div>
                @endif
            </div>
        </x-m.section>
    @endif
</div>
