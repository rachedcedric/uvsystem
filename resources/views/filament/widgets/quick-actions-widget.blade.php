<x-filament-widgets::widget class="fi-quick-actions">
    <div class="fi-quick-actions__bar">
        <span class="fi-quick-actions__title">Actions rapides</span>

        <div class="fi-quick-actions__list">
            @foreach ($this->getActions() as $action)
                <x-filament::button
                    tag="a"
                    :href="$action['url']"
                    :icon="$action['icon']"
                    color="gray"
                    outlined
                    :tooltip="$action['hint']"
                >
                    {{ $action['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
