<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b6b3a">
    <title>@yield('title') — {{ config('branding.name') }}</title>
    @vite('resources/css/auth.css')
    @include('partials.favicons')
</head>
<body class="auth-page">
<div class="auth-frame">
    <header class="auth-brand">
        <picture>
            <source media="(prefers-color-scheme: dark)" srcset="{{ \App\Support\BrandAsset::url(config('branding.logo.dark_path')) }}">
            <img class="auth-brand__logo" src="{{ \App\Support\BrandAsset::url(config('branding.logo.path')) }}" alt="{{ config('branding.name') }}">
        </picture>
        <p>{{ config('branding.tagline') }}</p>
    </header>

    <main class="auth-card" id="main-content">
        @if (session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
        @if (session('warning'))<div class="alert warn" role="alert">{{ session('warning') }}</div>@endif
        @if ($errors->any())
            <div class="alert err" role="alert" aria-live="assertive">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="auth-footer">{{ config('branding.domain') }}</footer>
</div>
</body>
</html>
