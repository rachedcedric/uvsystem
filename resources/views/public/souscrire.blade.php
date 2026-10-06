@extends('layouts.public')
@section('title', 'Souscription')
@section('content')
    <div class="card">
        <h1>Créer votre entreprise</h1>
        <p>Offre <strong>{{ $plan->name }}</strong> — {{ $cycle->label() }} : {{ number_format($price, 0, ',', ' ') }} FCFA / {{ $cycle === \App\Enums\SubscriptionCycle::Yearly ? 'an' : 'mois' }}.
            @if ($plan->trial_days > 0) Essai gratuit de {{ $plan->trial_days }} jours, rien n'est facturé aujourd'hui.@endif</p>
        <p><a href="{{ route('offres', ['cycle' => $cycle->value]) }}">Changer d'offre</a></p>

        <form method="POST" action="{{ route('souscrire.store') }}" novalidate>
            @csrf
            <input type="hidden" name="plan" value="{{ $plan->slug }}">
            <input type="hidden" name="cycle" value="{{ $cycle->value }}">
            <input type="hidden" name="token" value="{{ $token }}">
            {{-- Leurre anti-robot : masqué pour les humains, ignoré par les lecteurs d'écran --}}
            <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
                <label for="website">Ne pas remplir</label>
                <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
            </div>

            <label for="company_name">Nom de l'entreprise</label>
            <input type="text" id="company_name" name="company_name" value="{{ old('company_name') }}" required autocomplete="organization">

            <label for="name">Votre nom (premier administrateur)</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name">

            <label for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">

            <label for="phone">Téléphone (facultatif)</label>
            <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel">

            <label for="password">Mot de passe (12 caractères minimum, majuscules, minuscules et chiffres)</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">

            <label for="password_confirmation">Confirmer le mot de passe</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">

            <label class="check"><input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }}>
                <span>J'accepte les @if (config('subscriptions.terms_url'))<a href="{{ config('subscriptions.terms_url') }}" target="_blank" rel="noopener">conditions d'utilisation</a>@else conditions d'utilisation @endif.</span></label>

            <div style="margin-top:20px"><button type="submit" onclick="this.disabled=true;this.form.submit()">Recevoir mon lien de confirmation</button></div>
        </form>
    </div>
@endsection
