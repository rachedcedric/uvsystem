@extends('layouts.public')
@section('title', 'Vérifiez votre e-mail')
@section('content')
    <div class="card">
        <h1>{{ cms('public.sent.title') }}</h1>
        <p>Nous avons envoyé un lien de confirmation @if ($email) à <strong>{{ $email }}</strong>@endif. {{ cms('public.sent.body') }}</p>
        <p>{{ cms('public.sent.note', ['hours' => config('subscriptions.signup.link_ttl_hours')]) }}</p>
        <p><a href="{{ route('offres') }}">Retour aux offres</a></p>
    </div>
@endsection
