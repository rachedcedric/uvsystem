@extends('layouts.public')
@section('title', 'Inscription validée')
@section('content')
    <div class="card" role="status">
        <h1>Félicitations !</h1>
        <p>{{ $message }}</p>
        <p>Connectez-vous avec l'adresse e-mail et le mot de passe choisis lors de votre inscription.</p>
        <a class="btn" href="{{ route('m.login') }}" style="margin-top:20px">Se connecter</a>
    </div>
@endsection
