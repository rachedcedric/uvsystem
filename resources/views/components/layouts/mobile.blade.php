@php
    $guest = $guest ?? false;
    $user = auth()->user();
    $tabs = $guest ? [] : \App\Support\Mobile\Nav::tabs($user);
    $quick = $guest ? [] : \App\Support\Mobile\Nav::quickActions($user);
@endphp
<!DOCTYPE html>
<html lang="fr" data-push-enabled="{{ $user?->hasAnyRole(['master', 'admin']) ? 'true' : 'false' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f5f7f2" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#08130d" media="(prefers-color-scheme: dark)">
    <title>{{ $title ?? 'MODELEC CI' }}</title>
    <script>try{if(sessionStorage.getItem('m-launched'))document.documentElement.classList.add('m-booted')}catch(e){}</script>
    @vite(['resources/css/mobile.css', 'resources/js/mobile.js'])
    @livewireStyles
</head>
<body class="m-app">
    @persist('m-splash')
        <div id="m-splash" class="m-splash" aria-hidden="true">
            <img class="m-logo m-splash__logo" src="{{ asset('images/logo-wide.png') }}" alt="">
            <div class="m-splash__bar"></div>
        </div>
    @endpersist

    @auth<x-two-factor-reminder />@endauth
    @auth<x-subscription-banner :mobile="true" />@endauth
    <div id="m-offline" class="m-offline" role="status">Hors connexion — les données peuvent être périmées</div>
    <div id="m-ptr" class="m-ptr" aria-hidden="true"><x-m.icon name="arrow-path" class="m-ico-sm" /></div>

    <main class="m-main {{ $guest ? 'm-main--bare' : '' }}">
        @if (session('m-toast'))
            <div data-m-flash="{{ session('m-toast') }}" hidden></div>
        @endif
        {{ $slot }}
    </main>

    @unless ($guest)
        @persist('m-chrome')
            <nav class="m-tabbar" aria-label="Navigation principale">
                @foreach ($tabs as $tab)
                    <a href="{{ route($tab['route']) }}" wire:navigate
                       class="m-tab" data-match="{{ $tab['match'] }}" @if (! empty($tab['exact'])) data-exact @endif>
                        <span class="m-tab__pill">
                            <x-m.icon :name="$tab['icon']" class="m-ico ico-o" />
                            <x-m.icon :name="$tab['icon']" solid class="m-ico ico-s" />
                            @if ($tab['route'] === 'm.more')
                                <livewire:mobile.notification-watcher />
                            @endif
                        </span>
                        <span>{{ $tab['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div id="m-backdrop" class="m-backdrop"></div>
            @if ($quick)
                <div id="m-quick" class="m-sheet" role="dialog" aria-label="Actions rapides">
                    <div class="m-sheet__grip"></div>
                    <div class="m-sheet__title">Que voulez-vous faire ?</div>
                    @foreach ($quick as $action)
                        <a href="{{ route($action['route']) }}" wire:navigate class="m-row" data-sheet-close>
                            <span class="m-row__lead"><x-m.icon :name="$action['icon']" class="m-ico-sm" /></span>
                            <span class="m-row__body">
                                <span class="m-row__title" style="display:block">{{ $action['label'] }}</span>
                                <span class="m-row__sub" style="display:block">{{ $action['hint'] }}</span>
                            </span>
                            <x-m.icon name="chevron-right" class="m-row__chev" />
                        </a>
                    @endforeach
                </div>

            @endif

            <div id="m-toast" class="m-toast" role="status">
                <x-m.icon name="check-circle" solid class="m-ico-sm" />
                <span data-toast-text></span>
            </div>
        @endpersist
    @endunless

    <script src="{{ asset('js/capacitor-bridge.js') }}?v={{ @filemtime(public_path('js/capacitor-bridge.js')) ?: 1 }}" data-navigate-once></script>
    @livewireScripts
</body>
</html>
