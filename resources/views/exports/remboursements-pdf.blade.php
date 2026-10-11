<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Rapport des Remboursements</title>
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
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
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
            border-top: 2px solid #8b5cf6;
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
            color: rgba(139, 92, 246, 0.03);
            font-weight: bold;
            z-index: -1;
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #8b5cf6;
        }

        .header h1 {
            margin: 0;
            color: #8b5cf6;
            font-size: 18px;
        }

        .header .subtitle {
            margin-top: 5px;
            font-size: 10px;
            color: #666;
        }

        .filters-box {
            background-color: #f5f3ff;
            border: 1px solid #c4b5fd;
            border-radius: 5px;
            padding: 8px 15px;
            margin-bottom: 15px;
            font-size: 9px;
        }

        .filters-box span {
            margin-right: 20px;
        }

        .filters-box strong {
            color: #6d28d9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #8b5cf6;
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

        .method-cash {
            color: #22c55e;
        }

        .method-mobile_money {
            color: #3b82f6;
        }

        .method-bank_transfer {
            color: #6366f1;
        }

        .method-cheque {
            color: #f59e0b;
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
            background-color: #f5f3ff;
            border: 1px solid #c4b5fd;
            border-radius: 5px;
        }

        .summary h3 {
            margin: 0 0 10px 0;
            color: #6d28d9;
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
            color: #8b5cf6;
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
        <div class="page-header-title">💳 {{ config('branding.name') }} - Rapport des Remboursements</div>
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
        <h1>📊 Rapport des Remboursements</h1>
        <div class="subtitle">
            Historique des paiements reçus des agents
        </div>
    </div>

    <!-- Filtres appliqués -->
    <div class="filters-box">
        <span><strong>Agent:</strong> {{ $filters['agent'] }}</span>
        <span><strong>Période:</strong> {{ $filters['start_date'] }} - {{ $filters['end_date'] }}</span>
        <span><strong>Mode:</strong> {{ $filters['payment_method'] }}</span>
        <span><strong>Total:</strong> {{ $remboursements->count() }} remboursement(s)</span>
    </div>

    <!-- Tableau des remboursements -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 12%;">Référence</th>
                <th style="width: 10%;">Date</th>
                <th style="width: 20%;">Agent</th>
                <th style="width: 13%;">Téléphone</th>
                <th style="width: 12%;">Mode</th>
                <th style="width: 15%;" class="text-right">Montant (FCFA)</th>
                <th style="width: 13%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @php
                $methodLabels = [
                    'cash' => '💵 Espèces',
                    'mobile_money' => '📱 Mobile Money',
                    'bank_transfer' => '🏦 Virement',
                    'cheque' => '📝 Chèque',
                ];
            @endphp
            @foreach ($remboursements as $index => $remboursement)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $remboursement->reference }}</strong></td>
                    <td>{{ $remboursement->payment_date?->format('d/m/Y') }}</td>
                    <td>{{ $remboursement->agent?->name ?? '-' }}</td>
                    <td>{{ $remboursement->agent?->phone ?? '-' }}</td>
                    <td class="method-{{ $remboursement->payment_method }}">
                        {{ $methodLabels[$remboursement->payment_method] ?? $remboursement->payment_method }}
                    </td>
                    <td class="text-right" style="font-weight: bold; color: #22c55e;">
                        {{ number_format($remboursement->amount, 0, ',', ' ') }}
                    </td>
                    <td style="font-size: 8px;">{{ Str::limit($remboursement->notes, 30) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Résumé -->
    <div class="summary">
        <h3>📈 Résumé des Encaissements</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ $remboursements->count() }}</div>
                <div class="summary-label">Remboursements</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #22c55e;">
                    {{ $remboursements->where('payment_method', 'cash')->count() }}</div>
                <div class="summary-label">Espèces</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #3b82f6;">
                    {{ $remboursements->where('payment_method', 'mobile_money')->count() }}</div>
                <div class="summary-label">Mobile Money</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #6366f1;">
                    {{ $remboursements->where('payment_method', 'bank_transfer')->count() }}</div>
                <div class="summary-label">Virements</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #8b5cf6;">
                    {{ number_format($remboursements->sum('amount'), 0, ',', ' ') }}</div>
                <div class="summary-label">Total Encaissé (FCFA)</div>
            </div>
        </div>
    </div>

</body>

</html>
