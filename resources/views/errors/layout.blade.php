@php
    $home = request()->is('m', 'm/*') ? url('/m') : url('/');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') — {{ config('branding.name') }}</title>
    <style>
        :root { --bg: #f5f7f2; --fg: #11261a; --muted: #4a5d51; --brand: #0b6b3a; --line: #d8e0d6; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0e1712; --fg: #eaf2ec; --muted: #a5b8ab; --brand: #3fbf7a; --line: #26372c; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 420px; }
        .code { margin: 0 0 4px; font-size: 14px; font-weight: 700; letter-spacing: .08em; color: var(--brand); }
        h1 { margin: 0 0 8px; font-size: 28px; line-height: 1.2; }
        p { margin: 0 0 24px; color: var(--muted); }
        .actions { display: grid; gap: 12px; }
        a, button { display: block; width: 100%; min-height: 48px; padding: 13px 16px; border-radius: 14px; font: inherit; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer; }
        .primary { border: 0; background: var(--brand); color: #fff; }
        @media (prefers-color-scheme: dark) { .primary { color: #06140c; } }
        .secondary { border: 1px solid var(--line); background: transparent; color: var(--fg); }
    </style>
    @include('partials.favicons')
</head>
<body>
    <main role="main">
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <button type="button" class="primary" onclick="if (history.length > 1) { history.back(); } else { location.href = '{{ $home }}'; }">Retour</button>
            <a class="secondary" href="{{ $home }}">Accueil</a>
        </div>
    </main>
</body>
</html>
