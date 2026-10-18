@props(['subtitle', 'headline', 'lead', 'items' => []])
{{-- Panneau de marque des pages de connexion (écrans larges, visiteurs non connectés uniquement). --}}
<aside class="pf-aside" aria-label="Présentation de {{ config('branding.name') }}">
    <div class="pf-aside__inner">
        <p class="pf-aside__brand">
            <img class="pf-aside__logo" src="{{ \App\Support\BrandAsset::url('images/logo-wide.png') }}" alt="{{ config('branding.name') }}">
            <span>{{ cms('settings.brand.name') }} <small>{{ $subtitle }}</small></span>
        </p>

        <div class="pf-aside__body">
            <h2>{!! $headline !!}</h2>
            <p class="pf-aside__lead">{{ $lead }}</p>

            <ul class="pf-aside__list">
                @foreach ($items as $item)
                    <li>
                        <x-filament::icon :icon="$item['icon']" class="pf-aside__icon" />
                        <span><strong>{{ $item['title'] }}</strong>{{ $item['text'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="pf-aside__foot">© {{ date('Y') }} {{ config('branding.name') }}</p>
    </div>
</aside>

{{-- Le logo a un fond blanc : les pages de connexion restent en thème clair, quel que soit le réglage du système. --}}
<script>document.documentElement.classList.remove('dark')</script>
