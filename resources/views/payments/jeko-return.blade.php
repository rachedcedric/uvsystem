<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title }} · {{ config('branding.name') }}</title>
    @include('partials.favicons')
    <style>
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f7f6; color: #132a25; }
        main { width: min(90vw, 34rem); padding: 2rem; background: #fff; border: 1px solid #dce6e2; border-radius: 8px; box-shadow: 0 14px 40px rgba(19, 42, 37, .08); }
        .status { width: 3rem; height: 3rem; display: grid; place-items: center; border-radius: 50%; background: {{ $successful ? '#dcfce7' : '#fee2e2' }}; color: {{ $successful ? '#166534' : '#991b1b' }}; font-size: 1.5rem; font-weight: 700; }
        h1 { margin: 1.25rem 0 .75rem; font-size: 1.5rem; }
        p { margin: 0; color: #49625b; line-height: 1.6; }
    </style>
</head>
<body>
    <main>
        <img src="{{ \App\Support\BrandAsset::url('images/logo-wide.png') }}" alt="{{ config('branding.name') }}" style="display:block;height:2.25rem;margin:0 auto 1.5rem">
        <div class="status" aria-hidden="true">{{ $successful ? '✓' : '!' }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
    </main>
</body>
</html>
