<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $this->getSectionDescription() }}</p>

    <form wire:submit="save" class="grid gap-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" size="lg">Enregistrer</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
