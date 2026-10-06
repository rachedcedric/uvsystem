@extends('layouts.auth')
@section('title', 'Mot de passe oublié')
@section('content')
    <h1>Mot de passe oublié</h1>
    <p>Saisissez l'adresse e-mail de votre compte : si elle existe, vous recevrez un lien valable {{ (int) config('auth.passwords.users.expire', 60) }} minutes.</p>
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">Adresse e-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" inputmode="email" required autofocus>
        <button type="submit">Envoyer le lien</button>
    </form>
    <p><a href="{{ url('/m/login') }}">← Retour à la connexion</a></p>
@endsection
