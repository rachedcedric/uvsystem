<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Rapport des Commissions</title>
    <style>
        @page {
            margin: 100px 50px 80px 50px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #333;
        }

        .page-header {
            position: fixed;
            top: -80px;
            left: 0;
            right: 0;
            height: 70px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 12px 30px;
        }

        .page-header-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .page-header-subtitle {
            font-size: 9px;
            opacity: 0.9;
        }

        .page-footer {
            position: fixed;
            bottom: -60px;
            left: 0;
            right: 0;
            height: 50px;
            border-top: 2px solid #f59e0b;
            padding: 10px 30px;
            font-size: 8px;
            color: #666;
        }

        .page-footer-left {
            float: left;
            width: 50%;
        }

        .page-footer-right {
            float: right;
            width: 50%;
            text-align: right;
        }

        .page-number:before {
            content: "Page " counter(page) " sur " counter(pages);
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(245, 158, 11, 0.03);
            font-weight: bold;
            z-index: -1;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f59e0b;
        }

        .header h1 {
            margin: 0;
            color: #d97706;
            font-size: 18px;
        }

        .header .subtitle {
            margin-top: 5px;
            font-size: 10px;
            color: #666;
        }

        .filters-box {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 5px;
            padding: 8px 15px;
            margin-bottom: 15px;
            font-size: 9px;
        }

        .filters-box span {
            margin-right: 20px;
        }

        .filters-box strong {
            color: #d97706;
        }

        .bareme-box {
            background-color: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 5px;
            padding: 10px 15px;
            margin-bottom: 15px;
        }

        .bareme-box h4 {
            margin: 0 0 8px 0;
            color: #b45309;
            font-size: 11px;
        }

        .bareme-table {
            width: 100%;
            font-size: 8px;
        }

        .bareme-table td {
            padding: 2px 5px;
        }

        table.main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table.main-table th {
            background-color: #f59e0b;
            color: white;
            padding: 8px 5px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
        }

        table.main-table td {
            padding: 6px 5px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9px;
        }

        table.main-table tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .summary {
            margin-top: 25px;
            padding: 15px;
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 5px;
        }

        .summary h3 {
            margin: 0 0 12px 0;
            color: #d97706;
            font-size: 12px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 8px;
        }

        .summary-value {
            font-size: 16px;
            font-weight: bold;
            color: #d97706;
        }

        .summary-label {
            font-size: 9px;
            color: #666;
            margin-top: 4px;
        }
    </style>
</head>

<body>
    <!-- En-tête fixe -->
    <div class="page-header">
        <div class="page-header-title">@include('exports.partials.brand-mark'){{ config('branding.name') }} - Rapport des Commissions</div>
        <div class="page-header-subtitle">
            Généré le {{ $generatedAt->format('d/m/Y à H:i') }} par {{ $generatedBy }}
        </div>
    </div>

    <!-- Pied de page fixe -->
    <div class="page-footer">
        <div class="page-footer-left">
            {{ config('branding.name') }} &copy; {{ date('Y') }} - Document confidentiel
        </div>
        <div class="page-footer-right">
            <span class="page-number"></span>
        </div>
    </div>

    <!-- Watermark -->
    <div class="watermark">{{ config('branding.name') }}</div>

    <!-- Contenu -->
    <div class="header">
        <h1>Rapport des Commissions</h1>
        <div class="subtitle">
            Commissions calculées par volume de transactions
        </div>
    </div>

    <!-- Filtres appliqués -->
    <div class="filters-box">
        <span><strong>Agent:</strong> {{ $filters['agent'] }}</span>
        <span><strong>Période:</strong> {{ $filters['start_date'] }} - {{ $filters['end_date'] }}</span>
        <span><strong>Total:</strong> {{ $commissions->count() }} commission(s)</span>
    </div>

    <!-- Tableau des commissions -->
    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 12%;">Date</th>
                <th style="width: 25%;">Agent</th>
                <th style="width: 20%;">Palier</th>
                <th style="width: 18%;" class="text-right">Volume (FCFA)</th>
                <th style="width: 15%;" class="text-right">Commission (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($commissions as $index => $commission)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $commission->date_commission?->format('d/m/Y') }}</td>
                    <td><strong>{{ $commission->agent?->name ?? 'N/A' }}</strong></td>
                    <td style="font-size: 8px;">{{ number_format($commission->seuil_min, 0, ',', ' ') }} -
                        {{ number_format($commission->seuil_max, 0, ',', ' ') }}</td>
                    <td class="text-right">{{ number_format($commission->montant_vente, 0, ',', ' ') }}</td>
                    <td class="text-right" style="font-weight: bold; color: #22c55e;">
                        {{ number_format($commission->montant_commission, 0, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Résumé -->
    <div class="summary">
        <h3>Résumé des Commissions</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ $commissions->count() }}</div>
                <div class="summary-label">Commissions</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ $commissions->pluck('agent_id')->unique()->count() }}</div>
                <div class="summary-label">Agents</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format($commissions->sum('montant_vente'), 0, ',', ' ') }}</div>
                <div class="summary-label">Volume Total (FCFA)</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #22c55e;">
                    {{ number_format($commissions->sum('montant_commission'), 0, ',', ' ') }}</div>
                <div class="summary-label">Total Commissions (FCFA)</div>
            </div>
        </div>
    </div>

    <!-- Barème de référence -->
    <div class="bareme-box" style="margin-top: 20px;">
        <h4>Barème des Commissions (Référence)</h4>
        @php
            // Barème du master (paliers actifs en base), jamais une copie écrite ici. Seuils abrégés en millions : le maximum d'un
            // palier vaut « presque » le minimum du suivant (1 499 999 → 1.5M), on l'arrondit au millier le plus proche puis on ajoute 1.
            $millions = fn (float $montant): string => rtrim(rtrim(number_format($montant / 1_000_000, 2, '.', ''), '0'), '.').'M';
            $lignes = \App\Models\PalierCommission::where('is_active', true)->orderBy('seuil_min')->get()
                ->map(fn ($p) => $millions((float) $p->seuil_min).' - '.$millions(round((float) $p->seuil_max, -3) + 1).' → '.number_format((float) $p->commission, 0, ',', ' '))
                ->chunk(3);
        @endphp
        <table class="bareme-table">
            @forelse ($lignes as $ligne)
                <tr>
                    @foreach ($ligne as $cellule)
                        <td>{{ $cellule }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td>Aucun palier défini.</td>
                </tr>
            @endforelse
        </table>
    </div>

</body>

</html>
