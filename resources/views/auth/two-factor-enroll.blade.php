<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Double authentification — MODELEC CI</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f3f4f6;color:#111827;min-height:100vh;display:flex;justify-content:center;padding:16px}
        .card{background:#fff;border-radius:14px;box-shadow:0 4px 18px rgba(0,0,0,.08);padding:24px;width:100%;max-width:440px;height:fit-content}
        h1{font-size:20px;margin:0 0 6px}p{color:#4b5563;line-height:1.45;margin:8px 0}
        .alert{padding:10px 12px;border-radius:8px;margin:12px 0;font-size:14px}.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
        input[type=text],input[type=password]{width:100%;padding:14px;font-size:20px;text-align:center;letter-spacing:.2em;border:1px solid #d1d5db;border-radius:10px;margin:8px 0}
        input[type=password]{letter-spacing:normal;font-size:16px;text-align:left}
        button{width:100%;padding:14px;font-size:16px;font-weight:600;border:0;border-radius:10px;background:#047857;color:#fff;margin-top:8px;min-height:48px}
        button.danger{background:#b91c1c}.qr{display:flex;justify-content:center;margin:12px 0}.qr svg{width:200px;height:200px}
        code{display:block;background:#f3f4f6;padding:10px;border-radius:8px;word-break:break-all;font-size:13px;text-align:center}
        ul.codes{list-style:none;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:6px}ul.codes li{background:#f3f4f6;border-radius:6px;padding:8px;text-align:center;font-family:ui-monospace,monospace;font-size:13px}
        a{color:#047857}
    </style>
</head>
<body>
<div class="card">
    <h1>Double authentification</h1>

    @if (session('warning'))<div class="alert warn">{{ session('warning') }}</div>@endif
    @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert err">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif

    @if ($recoveryCodes)
        <div class="alert ok">Double authentification activée.</div>
        <p><strong>Conservez ces codes de récupération</strong> en lieu sûr : chacun ne sert qu'une fois si vous perdez votre téléphone. Ils ne seront plus affichés.</p>
        <ul class="codes">@foreach ($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach</ul>
        <p><a href="{{ auth()->user()->isSuperAdmin() ? '/platform' : '/admin' }}">Continuer</a></p>
    @elseif ($enabled)
        <div class="alert ok">La double authentification est <strong>activée</strong> sur votre compte.</div>
        @if ($mayDisable)
            <form method="POST" action="{{ route('security.2fa.disable') }}">
                @csrf
                <p>Pour la désactiver, confirmez votre mot de passe.</p>
                <input type="password" name="password" placeholder="Mot de passe" autocomplete="current-password" required>
                <button class="danger" type="submit">Désactiver</button>
            </form>
        @else
            <p>Votre rôle impose la double authentification : elle ne peut pas être désactivée.</p>
        @endif
        <p><a href="{{ auth()->user()->isSuperAdmin() ? '/platform' : '/admin' }}">Retour</a></p>
    @else
        @if ($required)<div class="alert warn">Obligatoire pour votre rôle : activez-la pour accéder à l'application.</div>@endif
        <p>1. Installez une application d'authentification (Google Authenticator, Microsoft Authenticator, Authy…).<br>
           2. Scannez ce QR code, ou saisissez la clé à la main.<br>
           3. Entrez le code à 6 chiffres affiché.</p>
        <div class="qr">{!! $qrCode !!}</div>
        <code>{{ $secret }}</code>
        <form method="POST" action="{{ route('security.2fa.confirm') }}">
            @csrf
            <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus>
            <button type="submit">Activer</button>
        </form>
    @endif

    @unless ($required)
        <p><a href="{{ auth()->user()->isSuperAdmin() ? '/platform' : '/m' }}">← Retour à l'application</a></p>
    @endunless
    <p><a href="{{ route('security.sessions') }}">Appareils connectés et mot de passe</a></p>
    <form method="POST" action="{{ route('filament.admin.auth.logout') }}" style="margin-top:14px">@csrf<button type="submit" style="background:#6b7280">Se déconnecter</button></form>
</div>
</body>
</html>
