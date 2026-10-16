<div>
    <x-m.appbar title="Plus" />

    @if ($company)
        <div class="m-group" style="margin-top:4px">
            @php
                $leadHtml = $companyLogo
                    ? new \Illuminate\Support\HtmlString('<img src="'.e($companyLogo).'" alt="" style="width:100%;aspect-ratio:1;object-fit:contain;border-radius:inherit">')
                    : mb_strtoupper(mb_substr($company->name, 0, 2));
            @endphp
            <x-m.row :lead="$leadHtml" :title="$company->name" sub="Entreprise" :chevron="false" />
        </div>
    @endif

    <div class="m-group" style="margin-top:{{ $company ? '10px' : '4px' }}">
        <x-m.row :lead="mb_strtoupper(mb_substr($user->name, 0, 2))" :title="$user->name" :sub="$user->email" :chevron="false">
            @if ($role)
                <x-slot:end><span class="m-badge m-badge--brand">{{ ucfirst(str_replace('_', ' ', $role)) }}</span></x-slot:end>
            @endif
        </x-m.row>
    </div>

    <x-m.section title="Préférences">
        <div class="m-group">
            <div class="m-row">
                <span class="m-row__lead"><x-m.icon name="speaker-wave" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title" style="display:block">Sons</span><span class="m-row__sub" style="display:block">Notifications, succès et erreurs</span></span>
                <button type="button" role="switch" class="m-switch" data-pref="m-sound" data-default="1"><span class="m-switch__knob"></span><span data-pref-label class="m-sr"></span></button>
            </div>
            <div class="m-row">
                <span class="m-row__lead"><x-m.icon name="cursor-arrow-rays" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title" style="display:block">Sons des touches</span><span class="m-row__sub" style="display:block">Un petit clic sur les boutons</span></span>
                <button type="button" role="switch" class="m-switch" data-pref="m-sound-keys" data-default="0"><span class="m-switch__knob"></span><span data-pref-label class="m-sr"></span></button>
            </div>
            <div class="m-row">
                <span class="m-row__lead"><x-m.icon name="device-phone-mobile" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title" style="display:block">Vibrations</span><span class="m-row__sub" style="display:block">Retour tactile sur les actions</span></span>
                <button type="button" role="switch" class="m-switch" data-pref="m-haptics" data-default="1"><span class="m-switch__knob"></span><span data-pref-label class="m-sr"></span></button>
            </div>
            <div class="m-row" style="gap:8px;flex-wrap:wrap">
                <span class="m-row__body"><span class="m-row__sub" style="display:block">Écouter</span></span>
                <button type="button" class="m-chip" data-sound-test="notify">Notification</button>
                <button type="button" class="m-chip" data-sound-test="success">Succès</button>
                <button type="button" class="m-chip" data-sound-test="error">Erreur</button>
            </div>
        </div>
    </x-m.section>

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
        <div class="m-section__head" style="margin-top:22px">
            <h2 class="m-section__title">Autres écrans</h2>
        </div>
        <p style="margin:-2px 20px 6px;color:var(--fg-3);font-size:13.5px">S'ouvrent dans la version complète.</p>
    @endif

    @foreach ($groups as $group)
        <div class="m-day" style="margin-top:14px"><span>{{ $group['label'] }}</span></div>
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

    <div class="m-group" style="margin-top:22px">
        <form method="POST" action="{{ route('m.logout') }}">
            @csrf
            <button type="submit" class="m-row" style="color:var(--danger)">
                <span class="m-row__lead m-row__lead--danger"><x-m.icon name="arrow-right-on-rectangle" class="m-ico-sm" /></span>
                <span class="m-row__body"><span class="m-row__title">Se déconnecter</span></span>
            </button>
        </form>
    </div>

    <p style="margin:18px 20px 0;text-align:center;font-size:12.5px;color:var(--fg-3)">{{ config('branding.name') }} · Version 4.1</p>
</div>
