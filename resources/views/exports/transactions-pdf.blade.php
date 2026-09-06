<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Rapport Transactions UV</title>
    <style>
        @page {
            margin: 100px 50px 80px 50px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #333;
        }

        .page-header {
            position: fixed;
            top: -80px;
            left: 0;
            right: 0;
            height: 80px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            padding: 15px 30px;
        }

        .page-header-title {
            font-size: 18px;
            font-weight: bold;
        }

        .page-header-subtitle {
            font-size: 10px;
            opacity: 0.9;
            margin-top: 5px;
        }

        .page-footer {
            position: fixed;
            bottom: -60px;
            left: 0;
            right: 0;
            height: 60px;
            border-top: 2px solid #3b82f6;
            padding: 15px 30px;
            font-size: 9px;
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
            content: "Page " counter(page);
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3b82f6;
        }

        .header h1 {
            margin: 0;
            color: #3b82f6;
            font-size: 20px;
        }

        .meta {
            margin-top: 10px;
            font-size: 10px;
            color: #666;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th {
            background-color: #3b82f6;
            color: white;
            padding: 8px;
            text-align: left;
            font-size: 10px;
        }

        td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10px;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .type-distribution {
            color: #22c55e;
            font-weight: bold;
        }

        .type-retour {
            color: #ef4444;
            font-weight: bold;
        }

        .total-row {
            background-color: #eff6ff !important;
            font-weight: bold;
        }

        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 9px;
            color: #666;
            padding: 10px 0;
            border-top: 1px solid #e5e7eb;
        }

        .summary {
            margin-top: 20px;
            padding: 15px;
            background-color: #f0f9ff;
            border-radius: 5px;
        }

        .summary h3 {
            margin: 0 0 10px 0;
            color: #0369a1;
        }

        .summary-item {
            display: inline-block;
            margin-right: 30px;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            color: rgba(59, 130, 246, 0.03);
            font-weight: bold;
            z-index: -1;
        }
    </style>
</head>

<body>
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-title">📊 {{ config('app.name', 'Système UV') }} - RAPPORT TRANSACTIONS</div>
        <div class="page-header-subtitle">
            @if ($agent)
                Agent: {{ $agent->name }} |
            @endif
            Période: {{ $startDate ?? 'Début' }} au {{ $endDate ?? now()->format('d/m/Y') }}
        </div>
    </div>

    <!-- Page Footer -->
    <div class="page-footer">
        <div class="page-footer-left">
            <strong>Système de Gestion UV</strong><br>
            Rapport généré le {{ now()->format('d/m/Y à H:i') }}
        </div>
        <div class="page-footer-right">
            <span class="page-number"></span><br>
            Document officiel
        </div>
    </div>

    <!-- Watermark -->
    <div class="watermark">TRANSACTIONS</div>

    <!-- Content -->
    <div class="header">
        <h1>📊 Rapport des Transactions UV</h1>
        <div class="meta">
            @if ($agent)
                Agent: <strong>{{ $agent->name }}</strong> |
            @endif
            Période: {{ $startDate ?? 'Début' }} au {{ $endDate ?? 'Aujourd\'hui' }} |
            Généré le: {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    <div class="summary">
        <h3>Résumé</h3>
        <span class="summary-item">📦 Total Transactions: <strong>{{ $transactions->count() }}</strong></span>
        <span class="summary-item">💰 Montant Total:
            <strong>{{ number_format($transactions->sum('amount'), 0, ',', ' ') }} FCFA</strong></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Référence</th>
                <th>Date</th>
                <th>Agent</th>
                <th>Fournisseur</th>
                <th>Type</th>
                <th style="text-align: right;">Montant</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->reference }}</td>
                    <td>{{ $transaction->transaction_date->format('d/m/Y') }}</td>
                    <td>{{ $transaction->agent->name ?? '-' }}</td>
                    <td>{{ $transaction->fournisseur->name ?? '-' }}</td>
                    <td class="type-{{ $transaction->transaction_type }}">
                        {{ $transaction->transaction_type === 'distribution' ? '↗ Distribution' : '↙ Retour' }}
                    </td>
                    <td style="text-align: right;">{{ number_format($transaction->amount, 0, ',', ' ') }} F</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: #999;">Aucune transaction trouvée</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="background-color: #3b82f6; color: white; font-weight: bold;">
                <td colspan="5">TOTAUX</td>
                <td style="text-align: right;">{{ number_format($transactions->sum('amount'), 0, ',', ' ') }} F</td>
            </tr>
            @if ($transactions->where('transaction_type', 'distribution')->count() > 0)
                <tr style="background-color: #dcfce7; font-weight: bold;">
                    <td colspan="5">Distributions
                        ({{ $transactions->where('transaction_type', 'distribution')->count() }})</td>
                    <td style="text-align: right; color: #16a34a;">
                        {{ number_format($transactions->where('transaction_type', 'distribution')->sum('amount'), 0, ',', ' ') }}
                        F</td>
                </tr>
            @endif
            @if ($transactions->where('transaction_type', 'retour')->count() > 0)
                <tr style="background-color: #fee2e2; font-weight: bold;">
                    <td colspan="5">Retours ({{ $transactions->where('transaction_type', 'retour')->count() }})</td>
                    <td style="text-align: right; color: #dc2626;">
                        {{ number_format($transactions->where('transaction_type', 'retour')->sum('amount'), 0, ',', ' ') }}
                        F</td>
                </tr>
            @endif
        </tfoot>
    </table>

    <!-- Notes -->
    <div
        style="margin-top: 20px; padding: 10px; background-color: #fffbeb; border-left: 4px solid #f59e0b; font-size: 9px;">
        <strong>📝 Notes :</strong><br>
        ↗ Distribution : UV vendus aux agents (augmente la dette)<br>
        ↙ Retour : UV retournés par les agents (diminue la dette)
    </div>
</body>

</html>
