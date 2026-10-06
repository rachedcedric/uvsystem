@php
    $user = auth()->user();
    $days = $user ? app(\App\Security\TwoFactorGate::class)->reminderDaysLeft($user) : false;
@endphp
@if ($days !== false)
    <div role="status" style="background:#fffbeb;color:#92400e;border-bottom:1px solid #fde68a;padding:10px 14px;font-size:14px;text-align:center">
        🔐 Sécurisez votre compte : activez la double authentification
        @if ($days !== null) (obligatoire dans {{ $days }} jour{{ $days > 1 ? 's' : '' }}) @endif
        — <a href="{{ route('security.2fa') }}" style="color:#92400e;font-weight:600;text-decoration:underline">Activer maintenant</a>
    </div>
@endif
