<div>
    <x-m.appbar title="Plus" />

    @if ($company)
        <div class="m-group m-more-company">
            @php
                $leadHtml = $companyLogo
                    ? new \Illuminate\Support\HtmlString('<img src="'.e($companyLogo).'" alt="" class="m-more-company__logo">')
                    : mb_strtoupper(mb_substr($company->name, 0, 2));
            @endphp
            <x-m.row :lead="$leadHtml" :title="$company->name" sub="Entreprise" :chevron="false" />
        </div>
    @endif

    <div class="m-group m-more-user">
        <x-m.row :lead="mb_strtoupper(mb_substr($user->name, 0, 2))" :title="$user->name" :sub="$user->email" :chevron="false">
            @if ($role)
                <x-slot:end><span class="m-badge m-badge--brand">{{ ucfirst(str_replace('_', ' ', $role)) }}</span></x-slot:end>
            @endif
        </x-m.row>
    </div>

    <div class="m-more-search">
        <x-m.search wire:model.live.debounce.300ms="search" label="Rechercher dans le menu Plus" placeholder="Rechercher une page ou une opération" />
        @if ($searchActive)
            <p class="m-search-meta" role="status" aria-live="polite">{{ $resultCount }} résultat{{ $resultCount === 1 ? '' : 's' }}</p>
        @endif
    </div>

    @unless ($searchActive)
        <x-m.section title="Préférences">
        <div class="m-group">
            <div class="m-row">
                <span class="m-row__lead"><x-m.icon name="speaker-wave" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title">Sons</span><span class="m-row__sub">Notifications, succès et erreurs</span></span>
                <button type="button" role="switch" aria-label="Sons" aria-checked="true" class="m-switch" data-pref="m-sound" data-default="1"><span class="m-switch__knob"></span><span data-pref-label class="m-sr"></span></button>
            </div>
            <div class="m-row">
                <span class="m-row__lead"><x-m.icon name="cursor-arrow-rays" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title">Sons des touches</span><span class="m-row__sub">Un petit clic sur les boutons</span></span>
                <button type="button" role="switch" aria-label="Sons des touches" aria-checked="false" class="m-switch" data-pref="m-sound-keys" data-default="0"><span class="m-switch__knob"></span><span data-pref-label class="m-sr"></span></button>
            </div>
            <div class="m-row">
                <span class="m-row__lead"><x-m.icon name="device-phone-mobile" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title">Vibrations</span><span class="m-row__sub">Retour tactile sur les actions</span></span>
                <button type="button" role="switch" aria-label="Vibrations" aria-checked="true" class="m-switch" data-pref="m-haptics" data-default="1"><span class="m-switch__knob"></span><span data-pref-label class="m-sr"></span></button>
            </div>
            <div class="m-row m-sound-tests">
                <span class="m-row__body"><span class="m-row__sub">Écouter</span></span>
                <button type="button" class="m-chip" data-sound-test="notify">Notification</button>
                <button type="button" class="m-chip" data-sound-test="success">Succès</button>
                <button type="button" class="m-chip" data-sound-test="error">Erreur</button>
            </div>
        </div>
        </x-m.section>
    @endunless

    @if ($searchActive && $resultCount === 0)
        <div class="m-group m-more-empty">
            <x-m.empty icon="magnifying-glass" title="Aucun résultat" text="Essayez un autre mot-clé." />
        </div>
    @endif

    @if ($native)
        <x-m.section title="Opérations">
            <div class="m-group">
                @foreach ($native as $item)
                    <x-m.row :href="route($item['route'])" :icon="$item['icon']" :title="$item['label']" :external="! empty($item['external'])" />
                @endforeach
            </div>
        </x-m.section>
    @endif

    @foreach ($resourceGroups as $groupLabel => $resources)
        <x-m.section :title="$groupLabel">
            <div class="m-group">
                @foreach ($resources as $resource)
                    <x-m.row :href="route('m.crud.index', $resource::slug())" :icon="$resource::icon()" :title="$resource::plural()" />
                @endforeach
            </div>
        </x-m.section>
    @endforeach

    @if ($groups)
        <div class="m-section__head m-more-external__head">
            <h2 class="m-section__title">Autres écrans</h2>
        </div>
        <p class="m-more-external__note">S'ouvrent dans la version complète.</p>
    @endif

    @foreach ($groups as $group)
        <div class="m-day m-more-external__day"><span>{{ $group['label'] }}</span></div>
        <div>
            <div class="m-group">
                @foreach ($group['items'] as $item)
                    {{-- Écrans complets Filament : navigation classique (pas de wire:navigate). --}}
                    <a href="{{ $item['url'] }}" class="m-row" data-haptic>
                        <span class="m-row__body"><span class="m-row__title">{{ $item['label'] }}</span></span>
                        <x-m.icon name="arrow-top-right-on-square" class="m-row__chev" />
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="m-group m-more-logout">
        <form method="POST" action="{{ route('m.logout') }}">
            @csrf
            <button type="submit" class="m-row m-text-danger">
                <span class="m-row__lead m-row__lead--danger"><x-m.icon name="arrow-right-on-rectangle" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title">Se déconnecter</span></span>
            </button>
        </form>
    </div>

    <p class="m-app-version">{{ config('branding.name') }} · Version 4.1</p>
</div>
