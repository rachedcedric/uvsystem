<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — {{ config('branding.name') }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f3f4f6;color:#111827;min-height:100vh;display:flex;justify-content:center;padding:16px}
        .card{background:#fff;border-radius:14px;box-shadow:0 4px 18px rgba(0,0,0,.08);padding:24px;width:100%;max-width:480px;height:fit-content}
        h1{font-size:20px;margin:0 0 6px}h2{font-size:16px;margin:22px 0 6px}p{color:#4b5563;line-height:1.45;margin:8px 0}
        .alert{padding:10px 12px;border-radius:8px;margin:12px 0;font-size:14px}.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
        input[type=text],input[type=email],input[type=password]{width:100%;padding:14px;font-size:16px;border:1px solid #d1d5db;border-radius:10px;margin:6px 0}
        label{display:block;font-size:14px;font-weight:600;margin-top:10px}
        button,.btn{display:block;width:100%;padding:14px;font-size:16px;font-weight:600;border:0;border-radius:10px;background:#047857;color:#fff;margin-top:10px;min-height:48px;text-align:center;text-decoration:none;cursor:pointer}
        button.danger{background:#b91c1c}button.ghost{background:#e5e7eb;color:#111827}button.small{width:auto;min-height:40px;padding:8px 14px;font-size:14px;margin:0}
        .row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 0;border-top:1px solid #e5e7eb;font-size:14px}.row small{color:#6b7280;display:block}
        .badge{background:#d1fae5;color:#065f46;border-radius:999px;padding:2px 8px;font-size:12px;font-weight:600}
        a{color:#047857}hr{border:0;border-top:1px solid #e5e7eb;margin:20px 0}
    </style>
</head>
<body>
<div class="card">
    @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if (session('warning'))<div class="alert warn">{{ session('warning') }}</div>@endif
    @if ($errors->any())<div class="alert err">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    @yield('content')
</div>
</body>
</html>
