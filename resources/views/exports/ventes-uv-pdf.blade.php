<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Rapport des Ventes UV</title>
    <style>
        @page {
            margin: 100px 40px 80px 40px;
            size: landscape;
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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
            border-top: 2px solid #10b981;
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
            color: rgba(16, 185, 129, 0.03);
            font-weight: bold;
            z-index: -1;
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #10b981;
        }

        .header h1 {
            margin: 0;
            color: #10b981;
            font-size: 18px;
        }

        .header .subtitle {
            margin-top: 5px;
            font-size: 10px;
            color: #666;
        }

        .filters-box {
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 5px;
            padding: 8px 15px;
            margin-bottom: 15px;
            font-size: 9px;
        }

        .filters-box span {
            margin-right: 20px;
        }

        .filters-box strong {
            color: #059669;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #10b981;
            color: white;
            padding: 6px 5px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
        }

        td {
            padding: 5px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9px;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .type-distribution {
            color: #3b82f6;
            font-weight: bold;
        }

        .type-retour {
            color: #f59e0b;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .summary {
            margin-top: 20px;
            padding: 12px;
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 5px;
        }

        .summary h3 {
            margin: 0 0 10px 0;
            color: #059669;
            font-size: 12px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            width: 20%;
            text-align: center;
            padding: 5px;
        }

        .summary-value {
            font-size: 14px;
            font-weight: bold;
            color: #10b981;
        }

        .summary-label {
            font-size: 9px;
            color: #666;
            margin-top: 3px;
        }
    </style>
</head>

<body>
    <!-- En-tête fixe -->
    <div class="page-header">
        <div class="page-header-title">@include('exports.partials.brand-mark'){{ config('branding.name') }} - Rapport des Ventes</div>
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
        <h1>Rapport des Ventes UV</h1>
        <div class="subtitle">
            Historique des transactions de distribution
        </div>
    </div>

    <!-- Filtres appliqués -->
    <div class="filters-box">
        <span><strong>Agent:</strong> {{ $filters['agent'] }}</span>
        <span><strong>Période:</strong> {{ $filters['start_date'] }} - {{ $filters['end_date'] }}</span>
        <span><strong>Type:</strong> {{ $filters['type'] }}</span>
        <span><strong>Total:</strong> {{ $transactions->count() }} transaction(s)</span>
    </div>

    <!-- Tableau des transactions -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 12%;">Référence</th>
                <th style="width: 10%;">Date</th>
                <th style="width: 18%;">Agent</th>
                <th style="width: 15%;">Fournisseur</th>
                <th style="width: 10%;">Type</th>
                <th style="width: 10%;" class="text-right">Qté UV</th>
                <th style="width: 15%;" class="text-right">Montant (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transactions as $index => $transaction)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $transaction->reference }}</strong></td>
                    <td>{{ $transaction->transaction_date?->format('d/m/Y') }}</td>
                    <td>{{ $transaction->agent?->name ?? '-' }}</td>
                    <td>{{ $transaction->fournisseur?->name ?? '-' }}</td>
                    <td class="type-{{ $transaction->transaction_type }}">
                        @if ($transaction->transaction_type === 'distribution')
                            Distribution
                        @elseif($transaction->transaction_type === 'retour')
                            Retour
                        @else
                            {{ $transaction->transaction_type }}
                        @endif
                    </td>
                    <td class="text-right">{{ number_format($transaction->amount, 0, ',', ' ') }}</td>
                    <td class="text-right" style="font-weight: bold;">
                        {{ number_format($transaction->amount, 0, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Résumé -->
    <div class="summary">
        <h3>Résumé Financier</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ $transactions->count() }}</div>
                <div class="summary-label">Transactions</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #3b82f6;">
                    {{ $transactions->where('transaction_type', 'distribution')->count() }}</div>
                <div class="summary-label">Distributions</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #f59e0b;">
                    {{ $transactions->where('transaction_type', 'retour')->count() }}</div>
                <div class="summary-label">Retours</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format($transactions->sum('amount'), 0, ',', ' ') }}</div>
                <div class="summary-label">Total Montant</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #10b981;">
                    {{ number_format($transactions->where('transaction_type', 'distribution')->sum('amount'), 0, ',', ' ') }}
                </div>
                <div class="summary-label">CA Distributions (FCFA)</div>
            </div>
        </div>
    </div>

</body>

</html>
