<x-filament-panels::page>
    @php($t = $this->totals())
    <div class="space-y-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">Journée de caisse : {{ $this->periodLabel() }}</p>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total entrées</div>
                <div class="text-xl font-semibold" style="color: rgb(var(--success-600));">{{ \App\Services\CaisseService::fcfa($t['entrees']) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total sorties</div>
                <div class="text-xl font-semibold" style="color: rgb(var(--danger-600));">{{ \App\Services\CaisseService::fcfa($t['sorties']) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Solde de la journée</div>
                <div class="text-xl font-semibold text-gray-950 dark:text-white">{{ \App\Services\CaisseService::fcfa($t['solde']) }}</div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Soldes par catégorie">
            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm text-gray-950 dark:text-white">
                    <thead>
                        <tr class="font-semibold">
                            <th class="px-3 py-2 text-start">Catégorie</th>
                            <th class="px-3 py-2 text-end">Total des entrées</th>
                            <th class="px-3 py-2 text-end">Total des sorties</th>
                            <th class="px-3 py-2 text-end">Solde de la catégorie</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @forelse ($this->categoryRows() as $row)
                            <tr>
                                <td class="px-3 py-2">
                                    @if ($row['color'])<span class="mr-1 inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $row['color'] }}"></span>@endif{{ $row['name'] }}
                                </td>
                                <td class="px-3 py-2 text-end" style="color: rgb(var(--success-600));">{{ \App\Services\CaisseService::fcfa($row['entrees']) }}</td>
                                <td class="px-3 py-2 text-end" style="color: rgb(var(--danger-600));">{{ \App\Services\CaisseService::fcfa($row['sorties']) }}</td>
                                <td class="px-3 py-2 text-end font-semibold">{{ \App\Services\CaisseService::fcfa($row['solde']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Aucun mouvement.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
