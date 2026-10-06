@extends('layouts.public')
@section('title', 'Vérifiez votre e-mail')
@section('content')
    <div class="card">
        <h1>Vérifiez votre boîte mail</h1>
        <p>Nous avons envoyé un lien de confirmation @if ($email) à <strong>{{ $email }}</strong>@endif. Cliquez dessus pour créer votre entreprise et démarrer votre essai gratuit.</p>
        <p>Le lien est valable {{ config('subscriptions.signup.link_ttl_hours') }} heures et ne fonctionne qu'une fois. Rien n'est créé tant que vous ne l'avez pas ouvert.</p>
        <p><a href="{{ route('offres') }}">Retour aux offres</a></p>
    </div>
@endsection
