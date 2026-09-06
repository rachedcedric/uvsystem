<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Dette agent</title>
    <style>
        {{--
            IMPORTANT : ne jamais utiliser "position: fixed" pour un en-tête/
            pied de page répété sur ce template. dompdf (v3.1.4, cette
            installation) a un bug de mise en page reproductible où un
            élément position:fixed combiné à du texte contenant certains
            caractères (apostrophe notamment — fréquente dans les noms
            d'agents ivoiriens, ex. "N'Guessan") déclenche une boucle de
            pages blanches (jusqu'à 8 pages générées pour 3 lignes de
            données réelles, constaté et reproduit). Les margin boxes CSS
            @page (@bottom-left/@bottom-right) évitent bien ce bug mais ne
            sont pas rendues du tout par cette version de dompdf (testé,
            aucun texte affiché) : le pied de page reste donc une simple
            ligne en flux normal, imprimée une seule fois à la fin du
            document plutôt que répétée sur chaque page.
        --}}
        @page {
            margin: 60px 45px 50px 45px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #333;
        }

        .banner {
            background-color: #1e293b;
            color: white;
            padding: 16px 24px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .banner-title {
            font-size: 18px;
            font-weight: bold;
        }

        .banner-subtitle {
            font-size: 9px;
            color: #cbd5e1;
            margin-top: 4px;
        }

        .summary {
            margin-bottom: 20px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 10px 6px;
            background-color: #eff6ff;
            border: 1px solid #dbeafe;
        }

        .summary-value {
            font-size: 15px;
            font-weight: bold;
            color: #1e3a8a;
        }

        .summary-label {
            font-size: 8px;
            color: #64748b;
            margin-top: 4px;
            text-transform: uppercase;
        }

        table.main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        table.main-table th {
            background-color: #1e293b;
            color: white;
            padding: 6px 4px;
            text-align: left;
            font-size: 8px;
            font-weight: bold;
        }

        table.main-table td {
            padding: 5px 4px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 8px;
        }

        table.main-table tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .text-right {
            text-align: right;
        }

        .footer-note {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-size: 8px;
            color: #999;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="banner">
        <div class="banner-title">Historique de dette — {{ $agent->name }}</div>
        <div class="banner-subtitle">
            Téléphone : {{ $agent->phone ?? 'N/A' }} — Généré le {{ $generatedAt->format('d/m/Y') }} à {{ $generatedAt->format('H:i') }} par {{ $generatedBy }}
        </div>
    </div>

    <div class="summary">
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ number_format($summary['detteDuJour'], 0, ',', ' ') }} FCFA</div>
                <div class="summary-label">Dette du jour</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format($summary['totalDette'], 0, ',', ' ') }} FCFA</div>
                <div class="summary-label">Total dette</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #16a34a;">{{ number_format($summary['rembourse'], 0, ',', ' ') }} FCFA</div>
                <div class="summary-label">Remboursé</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #dc2626;">{{ number_format($summary['resteAPayer'], 0, ',', ' ') }} FCFA</div>
                <div class="summary-label">Reste à payer</div>
            </div>
        </div>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 16%;">Date et heure</th>
                <th style="width: 12%;">Type</th>
                <th style="width: 13%;" class="text-right">Dette</th>
                <th style="width: 15%;" class="text-right">Remboursement</th>
                <th style="width: 14%;" class="text-right">Solde avant</th>
                <th style="width: 14%;" class="text-right">Solde après</th>
                <th style="width: 16%;">Référence</th>
            </tr>
        </thead>
        <tbody>
            @php
                $typeLabels = ['distribution' => 'Vente', 'retour' => 'Retour', 'remboursement' => 'Remboursement'];
            @endphp
            @forelse ($history as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row->date_operation)->format('d/m/Y H:i') }}</td>
                    <td>{{ $typeLabels[$row->type] ?? $row->type }}</td>
                    <td class="text-right">{{ $row->amount !== null ? number_format($row->amount, 0, ',', ' ') : '-' }}</td>
                    <td class="text-right">{{ $row->remboursement_amount !== null ? number_format($row->remboursement_amount, 0, ',', ' ') : '-' }}</td>
                    <td class="text-right">{{ $row->solde_uv_avant !== null ? number_format($row->solde_uv_avant, 0, ',', ' ') : '-' }}</td>
                    <td class="text-right">{{ $row->solde_uv_apres !== null ? number_format($row->solde_uv_apres, 0, ',', ' ') : '-' }}</td>
                    <td>{{ $row->reference }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 12px;">Aucun mouvement enregistré pour cet agent.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer-note">
        MODELEC CI &copy; {{ date('Y') }} - Document confidentiel - Généré le {{ $generatedAt->format('d/m/Y') }} à {{ $generatedAt->format('H:i') }} par {{ $generatedBy }}
    </div>
</body>

</html>
