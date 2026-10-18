@php
    // Le barème affiché est CELUI DU MASTER (paliers actifs en base), jamais une copie écrite ici : si le master ajuste ses
    // paliers, ou si le barème de la plateforme change, cet écran suit.
    $paliers = \App\Models\PalierCommission::where('is_active', true)->orderBy('seuil_min')->get();
    $premier = $paliers->first();
    $dernier = $paliers->sortByDesc('seuil_max')->first();
@endphp
<div class="space-y-4">
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
        <p class="text-sm text-blue-700 dark:text-blue-300">
            <strong>⚠️ Important :</strong> Les commissions sont calculées automatiquement à <strong>18h00</strong>
            chaque jour.
            Les ventes effectuées après 18h sont comptabilisées pour le jour suivant.
        </p>
    </div>

    @if ($paliers->isEmpty())
        <p class="text-sm text-gray-600 dark:text-gray-400">Aucun palier de commission n'est défini pour le moment.</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-primary-500 text-white">
                    <th class="px-4 py-2 text-left rounded-tl-lg">Volume de Ventes (FCFA)</th>
                    <th class="px-4 py-2 text-right rounded-tr-lg">Commission (FCFA)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($paliers as $palier)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                        <td class="px-4 py-3">{{ number_format((float) $palier->seuil_min, 0, ',', ' ') }} — {{ number_format((float) $palier->seuil_max, 0, ',', ' ') }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-success-600">{{ number_format((float) $palier->commission, 0, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
            <p class="text-sm text-amber-700 dark:text-amber-300">
                <strong>💡 Note :</strong> Les volumes de ventes inférieurs à {{ number_format((float) $premier->seuil_min, 0, ',', ' ') }} FCFA ne génèrent pas de commission.
                Au-delà de {{ number_format((float) $dernier->seuil_max, 0, ',', ' ') }} FCFA, la commission du dernier palier ({{ number_format((float) $dernier->commission, 0, ',', ' ') }} FCFA) s'applique.
            </p>
        </div>
    @endif
</div>
