@extends('layouts.public')
@section('title', 'Souscription')
@section('content')
    <div class="card">
        <h1>{{ cms('public.inscription.title') }}</h1>
        <p>Offre <strong>{{ $plan->name }}</strong> — {{ $cycle->label() }} : {{ number_format($price, 0, ',', ' ') }} FCFA / {{ $cycle === \App\Enums\SubscriptionCycle::Yearly ? 'an' : 'mois' }}.
            @if ($plan->trial_days > 0) {{ cms('public.inscription.trial_note', ['days' => $plan->trial_days]) }}@endif</p>
        <p><a href="{{ route('offres', ['cycle' => $cycle->value]) }}">Changer d'offre</a></p>

        <form method="POST" action="{{ route('inscription.store') }}" class="cols" novalidate>
            @csrf
            <input type="hidden" name="plan" value="{{ $plan->slug }}">
            <input type="hidden" name="cycle" value="{{ $cycle->value }}">
            <input type="hidden" name="token" value="{{ $token }}">
            {{-- Leurre anti-robot : masqué pour les humains, ignoré par les lecteurs d'écran --}}
            <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
                <label for="website">Ne pas remplir</label>
                <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="field"><label for="company_name">Nom de l'entreprise</label>
            <input type="text" id="company_name" name="company_name" value="{{ old('company_name') }}" required autocomplete="organization"></div>

            <div class="field"><label for="name">Votre nom (premier administrateur)</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name"></div>

            <div class="field"><label for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email"></div>

            <div class="field"><label for="phone">Téléphone (facultatif)</label>
            <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel"></div>

            <div class="field"><label for="password">Mot de passe ({{ \App\Support\PasswordPolicy::HINT }})</label>
            <input type="password" id="password" name="password" required autocomplete="new-password"></div>

            <div class="field"><label for="password_confirmation">Confirmer le mot de passe</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"></div>

            <label class="check wide"><input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }}>
                <span>J'accepte les @if ($termsUrl = (cms('settings.links.terms_url') ?: config('subscriptions.terms_url')))<a href="{{ $termsUrl }}" target="_blank" rel="noopener">conditions d'utilisation</a>@else conditions d'utilisation @endif.</span></label>

            <div class="wide" style="margin-top:20px"><button type="submit" onclick="this.disabled=true;this.form.submit()">{{ cms('public.inscription.submit') }}</button></div>
        </form>
    </div>
@endsection
