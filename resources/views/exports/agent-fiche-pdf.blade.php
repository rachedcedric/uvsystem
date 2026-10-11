<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Fiche Agent - {{ $agent->name }}</title>
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
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white;
            padding: 15px 30px;
            display: table;
            width: 100%;
        }

        .page-header-left {
            display: table-cell;
            vertical-align: middle;
            width: 70%;
        }

        .page-header-right {
            display: table-cell;
            vertical-align: middle;
            width: 30%;
            text-align: right;
        }

        .logo-placeholder {
            display: inline-block;
            width: 40px;
            height: 40px;
            background-color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            font-size: 20px;
            vertical-align: middle;
            margin-right: 10px;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            display: inline-block;
            vertical-align: middle;
        }

        .page-header-subtitle {
            font-size: 10px;
            opacity: 0.9;
            margin-top: 3px;
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

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(59, 130, 246, 0.05);
            font-weight: bold;
            z-index: -1;
        }

        .header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #3b82f6;
        }

        .header h1 {
            margin: 0;
            color: #3b82f6;
            font-size: 22px;
        }

        .header .subtitle {
            margin-top: 5px;
            font-size: 11px;
            color: #666;
        }

        .info-box {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .info-box h3 {
            margin: 0 0 10px 0;
            color: #0369a1;
            font-size: 14px;
            border-bottom: 1px solid #bae6fd;
            padding-bottom: 5px;
        }

        .info-grid {
            display: table;
            width: 100%;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            padding: 4px 10px 4px 0;
            font-weight: bold;
            width: 150px;
            color: #555;
        }

        .info-value {
            display: table-cell;
            padding: 4px 0;
        }

        .financial-box {
            background-color: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .financial-box h3 {
            margin: 0 0 10px 0;
            color: #92400e;
            font-size: 14px;
        }

        .amount-big {
            font-size: 18px;
            font-weight: bold;
            color: #dc2626;
        }

        .amount-success {
            color: #16a34a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 10px;
        }

        th {
            background-color: #3b82f6;
            color: white;
            padding: 8px;
            text-align: left;
        }

        td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .section-title {
            margin: 25px 0 10px 0;
            color: #1e40af;
            font-size: 14px;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 5px;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }

        .badge-active {
            background-color: #dcfce7;
            color: #166534;
        }

        .badge-inactive {
            background-color: #fef9c3;
            color: #854d0e;
        }

        .badge-suspended {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .signature-section {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px dashed #ccc;
        }

        .signature-box {
            display: inline-block;
            width: 45%;
            text-align: center;
            padding: 20px 0;
        }

        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 10px;
        }
    </style>
</head>

<body>
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <span class="logo-placeholder">🏢</span>
            <div style="display: inline-block; vertical-align: middle;">
                <div class="company-name">{{ config('branding.name') }}</div>
                <div class="page-header-subtitle">Gestion de la distribution des UV</div>
            </div>
        </div>
        <div class="page-header-right">
            <div style="font-size: 12px; font-weight: bold;">FICHE AGENT</div>
            <div style="font-size: 9px; margin-top: 3px;">{{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <!-- Page Footer -->
    <div class="page-footer">
        <div class="page-footer-left">
            <strong>{{ config('branding.tagline') }}</strong><br>
            Document confidentiel - Usage interne uniquement
        </div>
        <div class="page-footer-right">
            <span class="page-number"></span><br>
            Généré le {{ now()->format('d/m/Y à H:i') }}
        </div>
    </div>

    <!-- Watermark -->
    <div class="watermark">{{ config('branding.name') }}</div>

    <!-- Content -->
    <div class="header">
        <h1>📋 Fiche Agent - {{ $agent->name }}</h1>
        <div class="subtitle">Rapport détaillé de l'activité</div>
    </div>

    <div class="info-box">
        <h3>👤 Informations Générales</h3>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nom complet:</div>
                <div class="info-value"><strong>{{ $agent->name }}</strong></div>
            </div>
            <div class="info-row">
                <div class="info-label">Téléphone:</div>
                <div class="info-value">{{ $agent->phone }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Type:</div>
                <div class="info-value">{{ ucfirst($agent->type ?? 'Standard') }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Statut:</div>
                <div class="info-value">
                    <span class="badge badge-{{ $agent->status }}">
                        {{ $agent->status === 'active' ? '✓ Actif' : ($agent->status === 'suspended' ? '⏸ Suspendu' : '✗ Inactif') }}
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Inscrit depuis:</div>
                <div class="info-value">{{ $agent->created_at->format('d/m/Y') }}
                    ({{ $agent->created_at->diffForHumans() }})</div>
            </div>
        </div>
    </div>

    <div class="financial-box">
        <h3>💰 Situation Financière</h3>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Plafond de dette:</div>
                <div class="info-value"><strong>{{ number_format($agent->debt_limit, 0, ',', ' ') }} FCFA</strong>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Dette actuelle:</div>
                <div class="info-value">
                    <span class="amount-big {{ $agent->current_debt == 0 ? 'amount-success' : '' }}">
                        {{ number_format($agent->current_debt, 0, ',', ' ') }} FCFA
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Crédit disponible:</div>
                <div class="info-value">
                    <span
                        class="amount-success">{{ number_format(max(0, $agent->debt_limit - $agent->current_debt), 0, ',', ' ') }}
                        FCFA</span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Ratio d'utilisation:</div>
                <div class="info-value">
                    @php
                        $ratio = $agent->debt_limit > 0 ? ($agent->current_debt / $agent->debt_limit) * 100 : 0;
                    @endphp
                    <strong style="color: {{ $ratio > 80 ? '#dc2626' : ($ratio > 50 ? '#f59e0b' : '#16a34a') }}">
                        {{ number_format($ratio, 1) }}%
                    </strong>
                </div>
            </div>
        </div>
    </div>

    @if ($agent->transactions->count() > 0)
        <h3 class="section-title">📦 Dernières Transactions ({{ $agent->transactions->count() }} total)</h3>
        <table>
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th style="text-align: right;">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($agent->transactions->sortByDesc('transaction_date')->take(10) as $transaction)
                    <tr>
                        <td>{{ $transaction->reference }}</td>
                        <td>{{ $transaction->transaction_date->format('d/m/Y') }}</td>
                        <td
                            style="color: {{ $transaction->transaction_type === 'distribution' ? '#16a34a' : '#dc2626' }}">
                            {{ $transaction->transaction_type === 'distribution' ? '↗ Distribution' : '↙ Retour' }}
                        </td>
                        <td style="text-align: right;">{{ number_format($transaction->amount, 0, ',', ' ') }} F</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($agent->transactions->count() > 10)
            <p style="text-align: center; color: #666; font-style: italic;">
                ... et {{ $agent->transactions->count() - 10 }} autres transactions
            </p>
        @endif
    @else
        <p style="color: #666; font-style: italic;">Aucune transaction enregistrée</p>
    @endif

    @if ($agent->remboursements->count() > 0)
        <h3 class="section-title">💵 Derniers Remboursements ({{ $agent->remboursements->count() }} total)</h3>
        <table>
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Date</th>
                    <th>Mode</th>
                    <th style="text-align: right;">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($agent->remboursements->sortByDesc('payment_date')->take(10) as $remboursement)
                    <tr>
                        <td>{{ $remboursement->reference }}</td>
                        <td>{{ $remboursement->payment_date->format('d/m/Y') }}</td>
                        <td>{{ match ($remboursement->payment_method) {
                            'cash' => 'Espèces',
                            'mobile_money' => 'Mobile Money',
                            'bank_transfer' => 'Virement',
                            'cheque' => 'Chèque',
                            default => $remboursement->payment_method,
                        } }}
                        </td>
                        <td style="text-align: right; color: #16a34a; font-weight: bold;">
                            +{{ number_format($remboursement->amount, 0, ',', ' ') }} F
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <!-- Signature Section -->
    <div class="signature-section">
        <div class="signature-box" style="margin-right: 8%;">
            <div style="font-weight: bold; margin-bottom: 10px;">Agent</div>
            <div class="signature-line">{{ $agent->name }}</div>
        </div>
        <div class="signature-box" style="float: right;">
            <div style="font-weight: bold; margin-bottom: 10px;">Responsable</div>
            <div class="signature-line">Signature & Cachet</div>
        </div>
    </div>

    <div style="clear: both;"></div>
</body>

</html>
