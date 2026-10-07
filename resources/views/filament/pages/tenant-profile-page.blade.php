<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" size="lg" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Enregistrer le profil</span>
                <span wire:loading wire:target="save">Enregistrement…</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
