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

        @php($annules = $this->cancelledRows())
        @if ($annules->isNotEmpty())
            <x-filament::section heading="Mouvements annulés" description="Annulations tracées : ces mouvements ne comptent plus dans les soldes." compact>
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                @foreach (['Heure', 'Référence', 'Libellé', 'Montant', 'Annulé le', 'Par', 'Motif'] as $h)
                                    <th class="px-2.5 py-1.5 text-start text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($annules as $m)
                                <tr wire:key="annule-{{ $m->id }}" style="opacity:.7">
                                    <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $m->date_mouvement->format('H:i') }}</td>
                                    <td class="px-2.5 py-1.5 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $m->reference }}</td>
                                    <td class="px-2.5 py-1.5 text-sm text-gray-950 dark:text-white">{{ $m->libelle }}</td>
                                    <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300" style="text-decoration:line-through">{{ $m->type === 'entree' ? '+' : '−' }}{{ \App\Services\CaisseService::fcfa($m->amount) }}<span class="sr-only"> (annulé)</span></td>
                                    <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $m->cancelled_at->format('d/m/Y H:i') }}</td>
                                    <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $m->cancelledBy?->name ?? '—' }}</td>
                                    <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $m->cancellation_reason }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
