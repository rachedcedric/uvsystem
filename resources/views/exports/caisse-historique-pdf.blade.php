<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Historique de la caisse</title>
    <style>@include('exports.partials.caisse-pdf-style')</style>
</head>
<body>
    <div class="banner">
        <h1>Historique de la caisse</h1>
        <p>{{ $tenant }} — Période : {{ $periode }}</p>
        <p>Généré le {{ $generatedAt->format('d/m/Y à H:i') }}</p>
    </div>

    <table>
        <thead>
            <tr><th>Date</th><th>Mouvements</th><th class="r">Entrées</th><th class="r">Sorties</th><th class="r">Solde caisse</th></tr>
        </thead>
        <tbody>
            @forelse ($days as $day)
                <tr>
                    <td>{{ $day['date']->format('d/m/Y') }}</td>
                    <td>{{ $day['count'] }}</td>
                    <td class="r in">{{ \App\Services\CaisseService::fcfa($day['entrees']) }}</td>
                    <td class="r out">{{ \App\Services\CaisseService::fcfa($day['sorties']) }}</td>
                    <td class="r">{{ \App\Services\CaisseService::fcfa($day['solde']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Aucun mouvement sur la période.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="2">Total général</td>
                <td class="r in">{{ \App\Services\CaisseService::fcfa($totalEntrees) }}</td>
                <td class="r out">{{ \App\Services\CaisseService::fcfa($totalSorties) }}</td>
                <td class="r">{{ \App\Services\CaisseService::fcfa($solde) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Répartition des soldes par catégorie</h2>
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
