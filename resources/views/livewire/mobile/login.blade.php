<div class="m-login" x-data="{ show: false }">
    <div class="m-login__brand">
        <img class="m-logo m-login__logo" src="{{ asset('images/logo-wide.png') }}" alt="MODELEC CI">
        <div>
            <h1 class="m-login__title">Bon retour.</h1>
            <p class="m-login__lead">Connectez-vous pour suivre vos ventes, vos dettes et votre caisse.</p>
        </div>
    </div>

    <form wire:submit="login" class="m-login__form" novalidate>
        <label class="m-field">
            <span class="m-field__label">Adresse e-mail</span>
            <input type="email" wire:model="email" class="m-input @error('email') m-input--error @enderror"
                   autocomplete="username" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false"
                   placeholder="vous@exemple.com">
            @error('email') <span class="m-field__error">{{ $message }}</span> @enderror
        </label>

        <label class="m-field">
            <span class="m-field__label">Mot de passe</span>
            <span class="m-pw">
                <input :type="show ? 'text' : 'password'" wire:model="password"
                       class="m-input @error('password') m-input--error @enderror" autocomplete="current-password">
                <button type="button" @click="show = !show" :aria-label="show ? 'Masquer le mot de passe' : 'Afficher le mot de passe'">
                    <x-m.icon name="eye" x-show="!show" />
                    <x-m.icon name="eye-slash" x-show="show" x-cloak />
                </button>
            </span>
            @error('password') <span class="m-field__error">{{ $message }}</span> @enderror
        </label>

        <label class="m-check">
            <input type="checkbox" wire:model="remember">
            <span>Rester connecté</span>
        </label>

        <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Se connecter</span>
            <span wire:loading wire:target="login" class="m-spin" aria-label="Connexion en cours"></span>
        </button>
    </form>

    <p class="m-login__foot">MODELEC CI — Côte d'Ivoire</p>
</div>
