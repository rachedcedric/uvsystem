@extends('layouts.auth')
@section('title', 'Nouveau mot de passe')
@section('content')
    <h1>Nouveau mot de passe</h1>
    <p>12 caractères minimum, avec majuscules, minuscules et chiffres.</p>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">Adresse e-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required>
        <label for="password">Nouveau mot de passe</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required>
        <label for="password_confirmation">Confirmation</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        <button type="submit">Enregistrer</button>
    </form>
@endsection
