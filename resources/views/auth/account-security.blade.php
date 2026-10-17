@extends('layouts.auth')
@section('title', 'Sécurité du compte')
@section('content')
    <p><a href="{{ auth()->user()->isSuperAdmin() ? '/platform' : '/m' }}">← Retour à l'application</a></p>
    <h1>Sécurité du compte</h1>
    <p>Double authentification : <strong>{{ $twoFactor ? 'activée' : 'non activée' }}</strong> — <a href="{{ route('security.2fa') }}">{{ $twoFactor ? 'gérer' : 'activer' }}</a></p>

    <h2>Appareils connectés</h2>
    @if (! $sessionsListable)
        <p>La liste des appareils n'est pas disponible avec la configuration actuelle des sessions.</p>
    @else
        @forelse ($sessions as $session)
            <div class="row">
                <div>{{ $session['device'] }} @if ($session['current'])<span class="badge">cet appareil</span>@endif
                    <small>{{ $session['ip'] }} · {{ $session['last_activity']->diffForHumans() }}</small></div>
                @unless ($session['current'])
                    <form method="POST" action="{{ route('security.sessions.revoke') }}">@csrf<input type="hidden" name="session_id" value="{{ $session['id'] }}"><button class="small danger" type="submit">Déconnecter</button></form>
                @endunless
            </div>
        @empty
            <p>Aucune session enregistrée.</p>
        @endforelse
    @endif

    @if ($tokens->isNotEmpty())
        <h2>Tokens API</h2>
        @foreach ($tokens as $token)
            <div class="row">
                <div>{{ $token->name }}<small>créé {{ $token->created_at?->diffForHumans() }}@if ($token->last_used_at) · utilisé {{ $token->last_used_at->diffForHumans() }}@endif</small></div>
                <form method="POST" action="{{ route('security.tokens.revoke', $token->id) }}">@csrf<button class="small danger" type="submit">Révoquer</button></form>
            </div>
        @endforeach
    @endif

    <h2>Se déconnecter partout ailleurs</h2>
    <form method="POST" action="{{ route('security.sessions.revoke-others') }}">
        @csrf
        <label for="password_others">Mot de passe</label>
        <input id="password_others" type="password" name="password" autocomplete="current-password" required>
        <button class="danger" type="submit">Déconnecter tous les autres appareils</button>
    </form>

    <hr>
    <h2>Changer le mot de passe</h2>
    <form method="POST" action="{{ route('security.password') }}">
        @csrf
        <label for="current_password">Mot de passe actuel</label>
        <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
        <label for="password">Nouveau mot de passe ({{ \App\Support\PasswordPolicy::HINT }})</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required>
        <label for="password_confirmation">Confirmation</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        <button type="submit">Modifier</button>
    </form>
    <p><a href="{{ auth()->user()->isSuperAdmin() ? '/platform' : '/m' }}">← Retour</a></p>
@endsection
