@extends('errors.layout')

@section('code', "403")
@section('title', "Accès refusé")
@php
    // Message explicite fourni par l'application (ex. « Cet export n'est pas inclus dans votre offre ») ; sinon message générique.
    $reason = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $reason = in_array($reason, ['', 'Forbidden', 'This action is unauthorized.'], true) ? null : $reason;
@endphp
@section('message', $reason ?? "Votre compte n'a pas l'autorisation d'ouvrir cette page.")
