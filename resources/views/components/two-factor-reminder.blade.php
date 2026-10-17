@php
    $user = auth()->user();
    $days = $user ? app(\App\Security\TwoFactorGate::class)->reminderDaysLeft($user) : false;
@endphp
{{-- Inutile sur l'écran qui sert justement à l'activer --}}
@if ($days !== false && ! request()->routeIs('m.securite'))
    {{-- Toute la bande est cliquable (zone tactile ≥ 44 px) --}}
    <a href="{{ route('security.2fa') }}" role="status" style="display:block;min-height:44px;box-sizing:border-box;background:#fffbeb;color:#92400e;border-bottom:1px solid #fde68a;padding:12px 14px;font-size:14px;line-height:1.35;text-align:center;text-decoration:none">
        🔐 Sécurisez votre compte : activez la double authentification
        @if ($days !== null) (obligatoire dans {{ $days }} jour{{ $days > 1 ? 's' : '' }}) @endif
        — <strong style="text-decoration:underline">Activer maintenant</strong>
    </a>
@endif
