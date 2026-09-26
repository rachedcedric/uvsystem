<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Caisse du {{ $date->format('d/m/Y') }}</title>
    <style>@include('exports.partials.caisse-pdf-style')</style>
</head>
<body>
    <div class="banner">
        <h1>Caisse du {{ $date->format('d/m/Y') }}</h1>
        <p>{{ $tenant }} — Journée de caisse : {{ $periode }}</p>
        <p>Généré le {{ $generatedAt->format('d/m/Y à H:i') }}</p>
    </div>

    <table>
        <thead>
            <tr><th>Date et heure</th><th>Type</th><th>Catégorie</th><th>Libellé</th><th class="r">Montant</th><th class="r">Solde après</th></tr>
        </thead>
        <tbody>
            @forelse ($mouvements as $m)
                @php($in = $m->type === \App\Models\CaisseMouvement::TYPE_ENTREE)
                <tr>
                    <td>{{ $m->date_mouvement->format('d/m/Y H:i') }}</td>
                    <td>{{ $in ? 'Entrée' : 'Sortie' }}</td>
                    <td>{{ $m->categorie?->name ?? 'Non catégorisé' }}</td>
                    <td>{{ $m->libelle }}</td>
                    <td class="r {{ $in ? 'in' : 'out' }}">{{ $in ? '+' : '-' }}{{ \App\Services\CaisseService::fcfa($m->amount) }}</td>
                    <td class="r">{{ \App\Services\CaisseService::fcfa($balances[$m->id]['solde_apres'] ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Aucun mouvement.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="4">Total de la journée</td>
                <td class="r">+{{ \App\Services\CaisseService::fcfa($totals['entrees']) }} / -{{ \App\Services\CaisseService::fcfa($totals['sorties']) }}</td>
                <td class="r">{{ \App\Services\CaisseService::fcfa($totals['solde']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Soldes par catégorie</h2>
    <table>
        <thead><tr><th>Catégorie</th><th class="r">Entrées</th><th class="r">Sorties</th><th class="r">Solde</th></tr></thead>
        <tbody>
            @foreach ($categories as $c)
                <tr>
                    <td>{{ $c['name'] }}</td>
                    <td class="r in">{{ \App\Services\CaisseService::fcfa($c['entrees']) }}</td>
                    <td class="r out">{{ \App\Services\CaisseService::fcfa($c['sorties']) }}</td>
                    <td class="r">{{ \App\Services\CaisseService::fcfa($c['solde']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="foot">{{ $tenant }} — Document généré automatiquement.</div>
</body>
</html>
