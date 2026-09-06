@php
    $topAgents = $this->getTopAgents();
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="🏆 Top 5 des Agents (Aujourd'hui)" compact>
        @if ($topAgents->isEmpty())
            <p class="py-2 text-sm text-gray-500 dark:text-gray-400">Aucune vente aujourd'hui pour le moment.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($topAgents as $index => $agent)
                    <li class="flex items-center justify-between py-1.5 px-1">
                        <div class="flex items-center gap-2">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-xs font-bold text-primary-700 dark:text-primary-300">
                                {{ $index + 1 }}
                            </span>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $agent['name'] }}</span>
                        </div>
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ number_format($agent['amount'], 0, ',', ' ') }} FCFA</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
