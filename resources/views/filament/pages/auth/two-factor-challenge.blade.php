<x-filament-panels::page.simple>
    <x-filament-panels::form wire:submit="verify">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
    </x-filament-panels::form>

    <div class="mt-6 text-center">
        <x-filament::button wire:click="logout" color="gray" size="sm" outlined>
            Se déconnecter
        </x-filament::button>
    </div>
</x-filament-panels::page.simple>
