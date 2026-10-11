<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Rapport des Charges</title>
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
            width: 33.33%;
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
    <div class="page-header">
        <div class="page-header-title">💰 {{ config('branding.name') }} - Rapport des Charges</div>
        <div class="page-header-subtitle">
            Généré le {{ $generatedAt->format('d/m/Y à H:i') }} par {{ $generatedBy }}
        </div>
    </div>

    <div class="page-footer">
        <div class="page-footer-left">
            {{ config('branding.name') }} &copy; {{ date('Y') }} - Document confidentiel
        </div>
        <div class="page-footer-right">
            <span class="page-number"></span>
        </div>
    </div>

    <div class="watermark">{{ config('branding.name') }}</div>

    <div class="header">
        <h1>📊 Gestion des Charges</h1>
        <div class="subtitle">Revenu (commissions) - Charges d'exploitation = Revenu net</div>
    </div>

    <div class="filters-box">
        <span><strong>Période:</strong> {{ $summary['period_label'] }}</span>
        <span><strong>Total:</strong> {{ $charges->count() }} charge(s)</span>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 10%;">Date</th>
                <th style="width: 8%;">Heure</th>
                <th style="width: 22%;">Libellé</th>
                <th style="width: 15%;">Catégorie</th>
                <th style="width: 15%;" class="text-right">Montant (FCFA)</th>
                <th style="width: 25%;">Enregistré par</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($charges as $index => $charge)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $charge->date_charge?->format('d/m/Y') }}</td>
                    <td>{{ $charge->heure_charge ? \Carbon\Carbon::parse($charge->heure_charge)->format('H:i') : '—' }}</td>
                    <td><strong>{{ $charge->libelle }}</strong></td>
                    <td>{{ $charge->category?->name ?? '—' }}</td>
                    <td class="text-right" style="font-weight: bold; color: #dc2626;">
                        {{ number_format($charge->montant, 0, ',', ' ') }}</td>
                    <td>{{ $charge->creator?->name ?? 'Système' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <h3>📈 Résumé de la période</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value" style="color: #22c55e;">
                    {{ number_format($summary['revenue'], 0, ',', ' ') }}</div>
                <div class="summary-label">Total Revenu (FCFA)</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #dc2626;">
                    {{ number_format($summary['charges'], 0, ',', ' ') }}</div>
                <div class="summary-label">Total Charges (FCFA)</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: {{ $summary['net'] >= 0 ? '#22c55e' : '#dc2626' }};">
                    {{ number_format($summary['net'], 0, ',', ' ') }}</div>
                <div class="summary-label">Revenu net (FCFA)</div>
            </div>
        </div>
    </div>

</body>

</html>
