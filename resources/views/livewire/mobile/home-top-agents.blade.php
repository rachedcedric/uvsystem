@php($max = max(1, collect($agents)->max('total')))
{{-- Racine <div> comme le squelette de chargement : Livewire échoue à remplacer un <div> par un <section>. --}}
<div>
<x-m.section title="Meilleurs agents du mois">
    <div class="m-group">
        @forelse ($agents as $i => $agent)
            <x-m.row :lead="$i + 1" :title="$agent['name']" :chevron="false">
                <x-slot:body>
                    <span class="m-meter" style="display:block"><i style="width: {{ round($agent['total'] / $max * 100) }}%"></i></span>
                </x-slot:body>
                <x-slot:end>
                    <span class="m-row__amount">{{ \App\Support\Mobile\Fcfa::format($agent['total']) }}</span>
                </x-slot:end>
            </x-m.row>
        @empty
            <x-m.empty icon="trophy" title="Pas encore de ventes ce mois-ci" />
        @endforelse
    </div>
</x-m.section>
</div>
