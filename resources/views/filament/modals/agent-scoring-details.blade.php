<div class="space-y-6">
    {{-- Header avec score global --}}
    <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
        <div>
            <h3 class="text-xl font-bold">{{ $agent->name }}</h3>
            <p class="text-gray-500">{{ $agent->phone }} • {{ $agent->zone ?? 'Zone non définie' }}</p>
        </div>
        <div class="text-center">
            @php
                $score = $scoring['score'] ?? 0;
                $grade = $scoring['grade'] ?? 'E';
                $colorClass = match (true) {
                    $score >= 80 => 'bg-green-500',
                    $score >= 60 => 'bg-blue-500',
                    $score >= 40 => 'bg-yellow-500',
                    default => 'bg-red-500',
                };
            @endphp
            <div class="w-20 h-20 rounded-full {{ $colorClass }} flex items-center justify-center text-white">
                <div>
                    <div class="text-2xl font-bold">{{ $score }}</div>
                    <div class="text-xs">/100</div>
                </div>
            </div>
            <div class="mt-2 text-lg font-bold">Grade {{ $grade }}</div>
        </div>
    </div>

    {{-- Détails du score --}}
    <div class="grid grid-cols-2 gap-4">
        @php
            $details = $scoring['details'] ?? [];
            $criteria = [
                ['key' => 'volume', 'label' => '📦 Volume', 'max' => 25, 'desc' => 'Transactions (3 mois)'],
                ['key' => 'regularite', 'label' => '📅 Régularité', 'max' => 20, 'desc' => 'Mois actifs (6 mois)'],
                ['key' => 'paiement', 'label' => '💰 Paiement', 'max' => 30, 'desc' => 'Ratio remboursement'],
                ['key' => 'dette', 'label' => '📊 Dette', 'max' => 25, 'desc' => 'Utilisation plafond'],
            ];
        @endphp

        @foreach ($criteria as $c)
            @php
                $value = $details[$c['key']] ?? 0;
                $percentage = ($value / $c['max']) * 100;
                $barColor = $percentage >= 60 ? 'bg-green-500' : ($percentage >= 40 ? 'bg-yellow-500' : 'bg-red-500');
            @endphp
            <div class="p-3 border rounded-lg">
                <div class="flex justify-between items-center mb-2">
                    <span class="font-medium">{{ $c['label'] }}</span>
                    <span class="text-sm font-bold">{{ $value }}/{{ $c['max'] }}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div class="{{ $barColor }} h-2.5 rounded-full" style="width: {{ $percentage }}%"></div>
                </div>
                <p class="text-xs text-gray-500 mt-1">{{ $c['desc'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Recommandations --}}
    @if (!empty($scoring['recommendations']))
        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-xl">
            <h4 class="font-semibold text-yellow-800 dark:text-yellow-200 mb-2">💡 Recommandations</h4>
            <ul class="space-y-2">
                @foreach ($scoring['recommendations'] as $rec)
                    <li class="flex items-start gap-2 text-sm text-yellow-700 dark:text-yellow-300">
                        <span class="mt-0.5">•</span>
                        <span>{{ $rec }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="p-4 bg-green-50 dark:bg-green-900/20 rounded-xl">
            <h4 class="font-semibold text-green-800 dark:text-green-200">✅ Excellent !</h4>
            <p class="text-sm text-green-700 dark:text-green-300">Cet agent a un très bon comportement. Continuez ainsi
                !</p>
        </div>
    @endif

    {{-- Informations financières --}}
    <div class="grid grid-cols-3 gap-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
        <div class="text-center">
            <p class="text-sm text-gray-500">Dette actuelle</p>
            <p class="text-lg font-bold text-red-600">{{ number_format($agent->current_debt, 0, ',', ' ') }} FCFA</p>
        </div>
        <div class="text-center">
            <p class="text-sm text-gray-500">Plafond</p>
            <p class="text-lg font-bold">{{ number_format($agent->debt_limit, 0, ',', ' ') }} FCFA</p>
        </div>
        <div class="text-center">
            <p class="text-sm text-gray-500">Utilisation</p>
            @php
                $usage = $agent->debt_limit > 0 ? round(($agent->current_debt / $agent->debt_limit) * 100) : 0;
            @endphp
            <p
                class="text-lg font-bold {{ $usage >= 90 ? 'text-red-600' : ($usage >= 70 ? 'text-yellow-600' : 'text-green-600') }}">
                {{ $usage }}%
            </p>
        </div>
    </div>
</div>
