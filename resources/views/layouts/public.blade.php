<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — {{ cms('settings.brand.name') }}</title>
    <style>
        :root{--bg:#f3f4f6;--card:#fff;--text:#111827;--muted:#4b5563;--line:#e5e7eb;--brand:#047857;--brand-d:#065f46;--accent:#eef6ff;--danger:#b91c1c}
        @media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#111a2e;--text:#f3f4f6;--muted:#9ca3af;--line:#233049;--brand:#10b981;--brand-d:#34d399;--accent:#12263f;--danger:#f87171}}
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text);line-height:1.45;overflow-x:hidden}
        .wrap{max-width:1040px;margin:0 auto;padding:20px 16px 48px}
        h1{font-size:26px;margin:8px 0 4px}p{color:var(--muted);margin:6px 0}
        .seg{display:inline-flex;border:1px solid var(--line);border-radius:12px;background:var(--card);padding:4px;margin:16px 0}
        .seg a{min-height:44px;min-width:44px;display:inline-flex;align-items:center;justify-content:center;padding:0 18px;border-radius:9px;text-decoration:none;color:var(--text);font-weight:600}
        .seg a.on{background:var(--brand);color:#fff}
        .grid{display:grid;gap:16px;grid-template-columns:1fr}
        @media (min-width:760px){.grid{grid-template-columns:repeat(3,1fr)}}
        .plan{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;display:flex;flex-direction:column;position:relative}
        .plan.featured{border:2px solid var(--brand)}.plan.accent{background:var(--accent)}
        .plan .tag{position:absolute;top:-12px;left:16px;background:var(--brand);color:#fff;font-size:12px;font-weight:700;border-radius:999px;padding:4px 12px}
        .plan h2{margin:0;font-size:20px}.price{font-size:28px;font-weight:800;margin:10px 0 2px}.price small{font-size:14px;font-weight:600;color:var(--muted)}
        ul{list-style:none;padding:0;margin:14px 0;flex:1}li{display:flex;gap:8px;align-items:flex-start;padding:5px 0;font-size:15px}
        li svg{width:18px;height:18px;flex:none;color:var(--brand);margin-top:2px}
        .btn,button{display:flex;align-items:center;justify-content:center;width:100%;min-height:48px;padding:0 16px;font-size:16px;font-weight:700;border:0;border-radius:12px;background:var(--brand);color:#fff;text-decoration:none;cursor:pointer}
        .btn:hover,button:hover{background:var(--brand-d)}
        label{display:block;font-size:14px;font-weight:600;margin-top:14px}
        input[type=text],input[type=email],input[type=password],input[type=tel]{width:100%;min-height:48px;padding:12px;font-size:16px;border:1px solid var(--line);border-radius:10px;margin-top:6px;background:var(--card);color:var(--text)}
        .check{display:flex;gap:10px;align-items:flex-start;margin-top:16px;font-weight:400}.check input{width:24px;height:24px;margin-top:2px;flex:none}
        .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;max-width:520px;margin:0 auto}
        .alert{padding:10px 12px;border-radius:8px;margin:12px 0;font-size:14px;background:#fef2f2;color:var(--danger);border:1px solid #fecaca}
        a{color:var(--brand)}
    </style>
</head>
<body>
<div class="wrap">
    @if ($errors->any())<div class="alert" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    @yield('content')
</div>
</body>
</html>
