<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Dette agent</title>
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
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
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
            border-top: 2px solid #ef4444;
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

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ef4444;
        }

        .header h1 {
            margin: 0;
            color: #b91c1c;
            font-size: 18px;
        }

        .header .subtitle {
            margin-top: 5px;
            font-size: 10px;
            color: #666;
        }

        .summary {
            margin: 15px 0 20px 0;
            padding: 12px;
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 5px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 6px;
        }

        .summary-value {
            font-size: 14px;
            font-weight: bold;
            color: #b91c1c;
        }

        .summary-label {
            font-size: 8px;
            color: #666;
            margin-top: 3px;
        }

        table.main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        table.main-table th {
            background-color: #ef4444;
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
    </style>
</head>

<body>
    <div class="page-header">
        <div class="page-header-title">🧾 MODELEC CI - Dette de {{ $agent->name }}</div>
        <div class="page-header-subtitle">
            Généré le {{ $generatedAt->format('d/m/Y à H:i') }} par {{ $generatedBy }}
        </div>
    </div>

    <div class="page-footer">
        <div class="page-footer-left">
            MODELEC CI &copy; {{ date('Y') }} - Document confidentiel
        </div>
        <div class="page-footer-right">
            <span class="page-number"></span>
        </div>
    </div>

    <div class="header">
        <h1>Dette de l'agent {{ $agent->name }}</h1>
        <div class="subtitle">
            Téléphone : {{ $agent->phone ?? 'N/A' }} &middot; {{ $history->count() }} mouvement(s)
        </div>
    </div>

    <div class="summary">
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ number_format($summary['detteDuJour'], 0, ',', ' ') }}</div>
                <div class="summary-label">Dette du jour (FCFA)</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format($summary['totalDette'], 0, ',', ' ') }}</div>
                <div class="summary-label">Total dette (FCFA)</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #16a34a;">{{ number_format($summary['rembourse'], 0, ',', ' ') }}</div>
                <div class="summary-label">Remboursé (FCFA)</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format($summary['resteAPayer'], 0, ',', ' ') }}</div>
                <div class="summary-label">Reste à payer (FCFA)</div>
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
            @foreach ($history as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row->date_operation)->format('d/m/Y H:i') }}</td>
                    <td>{{ $typeLabels[$row->type] ?? $row->type }}</td>
                    <td class="text-right">{{ $row->amount !== null ? number_format($row->amount, 0, ',', ' ') : '—' }}</td>
                    <td class="text-right">{{ $row->remboursement_amount !== null ? number_format($row->remboursement_amount, 0, ',', ' ') : '—' }}</td>
                    <td class="text-right">{{ $row->solde_uv_avant !== null ? number_format($row->solde_uv_avant, 0, ',', ' ') : '—' }}</td>
                    <td class="text-right">{{ $row->solde_uv_apres !== null ? number_format($row->solde_uv_apres, 0, ',', ' ') : '—' }}</td>
                    <td>{{ $row->reference }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
