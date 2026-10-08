@props(['mobile' => false])
@php
    // Les écrans « Mon abonnement » affichent déjà ce message en détail.
    $onSubscriptionScreen = request()->is('master/mon-abonnement*', 'm/abonnement*');
    $banner = $onSubscriptionScreen ? null : \App\Services\Subscriptions\SubscriptionBanner::for(auth()->user());
    $url = $banner && $banner['manageable']
        ? ($mobile ? route('m.abonnement', [], false) : \App\Filament\Pages\SubscriptionPage::getUrl())
        : null;
    $tag = $url ? 'a' : 'div';
    $colors = $banner && $banner['level'] === 'danger'
        ? 'background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca'
        : 'background:#fffbeb;color:#92400e;border-bottom:1px solid #fde68a';
@endphp
@if ($banner)
    {{-- Toute la bande est cliquable quand l'utilisateur peut gérer l'abonnement (zone tactile ≥ 44 px) --}}
    <{{ $tag }} @if ($url) href="{{ $url }}" @endif role="status" data-subscription-banner="{{ $banner['level'] }}" style="display:block;min-height:44px;box-sizing:border-box;{{ $colors }};padding:12px 14px;font-size:14px;line-height:1.35;text-align:center;text-decoration:none">
        <strong>{{ $banner['title'] }}</strong> {{ $banner['message'] }}
        @if ($url)
            <strong style="text-decoration:underline">Voir mon abonnement</strong>
        @endif
    </{{ $tag }}>
@endif
