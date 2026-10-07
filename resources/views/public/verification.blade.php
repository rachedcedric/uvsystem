@extends('layouts.public')
@section('title', 'Confirmer')
@section('content')
    <div class="card">
        <h1>{{ cms('public.confirm.title') }}</h1>
        <p>{{ cms('public.confirm.body') }}</p>
        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <button type="submit" onclick="this.disabled=true;this.form.submit()">{{ cms('public.confirm.button') }}</button>
        </form>
    </div>
@endsection
