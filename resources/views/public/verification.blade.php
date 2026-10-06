@extends('layouts.public')
@section('title', 'Confirmer')
@section('content')
    <div class="card">
        <h1>Confirmer la création</h1>
        <p>Un dernier clic pour créer votre entreprise et démarrer votre essai gratuit.</p>
        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <button type="submit" onclick="this.disabled=true;this.form.submit()">Confirmer et démarrer mon essai</button>
        </form>
    </div>
@endsection
