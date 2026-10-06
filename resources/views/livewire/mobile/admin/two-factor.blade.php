<div>
    <x-m.appbar title="Sécurité du compte" sub="Double authentification" :back="route('m.more')" />

    <div class="m-group" style="margin-top:6px">
        <x-m.row :icon="$enabled ? 'shield-check' : 'shield-exclamation'" :tone="$enabled ? 'ok' : 'warn'" :chevron="false"
                 :title="$enabled ? 'Double authentification activée' : 'Double authentification désactivée'"
                 :sub="$enabled ? 'Votre compte est protégé par un code à usage unique.' : 'Ajoutez une protection avec une application d\'authentification.'" />
    </div>

    @if (! $enabled && ! $showSetup)
        <div style="padding:16px 12px 0"><button type="button" class="m-btn" wire:click="startSetup">Activer la protection</button></div>
    @endif

    @if ($showSetup)
        <x-m.section title="1. Scannez le code">
            <div class="m-group" style="padding:16px;text-align:center">
                <div style="display:inline-block;background:#fff;padding:10px;border-radius:14px">{!! $qrCode !!}</div>
                <p class="m-row__sub" style="margin:10px 0 0;white-space:normal">Ouvrez Google Authenticator (ou équivalent) et scannez ce code, ou saisissez la clé :</p>
                <p class="m-selectable m-num" style="font-weight:700;letter-spacing:.06em;word-break:break-all;margin:6px 0 0">{{ $secret }}</p>
            </div>
        </x-m.section>
        <x-m.section title="2. Saisissez le code à 6 chiffres">
            <form wire:submit="confirmSetup" class="m-form" novalidate>
                <x-m.field label="Code" :error="$errors->first('code')">
                    <input type="text" inputmode="numeric" maxlength="6" wire:model="code" class="m-input @error('code') m-input--error @enderror" autocomplete="one-time-code" placeholder="000000">
                </x-m.field>
                <button type="submit" class="m-btn">Valider et activer</button>
                <button type="button" class="m-btn m-btn--ghost" style="margin-top:10px" wire:click="cancelSetup">Annuler</button>
            </form>
        </x-m.section>
    @endif

    @if ($enabled)
        <x-m.section title="Codes de récupération">
            <div class="m-group" style="padding:14px 16px">
                <p class="m-row__sub" style="margin:0 0 10px;white-space:normal">Chaque code ne sert qu'une fois si vous perdez votre téléphone. Conservez-les en lieu sûr.</p>
                <div class="m-selectable m-num" style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-weight:600">
                    @forelse ($recoveryCodes as $c)<span>{{ $c }}</span>@empty<span class="m-row__sub">Aucun code restant.</span>@endforelse
                </div>
            </div>
            <div style="padding:12px 12px 0;display:grid;gap:10px">
                <button type="button" class="m-btn m-btn--ghost" x-data @click="if (confirm('Générer de nouveaux codes ? Les anciens ne fonctionneront plus.')) $wire.regenerate()">Générer de nouveaux codes</button>
                @if (app(\App\Security\TwoFactorGate::class)->mayDisable(auth()->user()))
                    <input type="password" wire:model="password" placeholder="Mot de passe pour confirmer" autocomplete="current-password" class="m-input">
                    @error('password')<div class="m-error">{{ $message }}</div>@enderror
                    <button type="button" class="m-btn" style="background:var(--danger)" x-data @click="if (confirm('Désactiver la double authentification ?')) $wire.disable()">Désactiver la protection</button>
                @else
                    <div class="m-row__sub">Votre rôle impose la double authentification : elle ne peut pas être désactivée.</div>
                @endif
            </div>
        </x-m.section>
    @endif
</div>
