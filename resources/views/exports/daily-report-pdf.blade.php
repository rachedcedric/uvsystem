<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Rapport Quotidien - {{ $date->format('d/m/Y') }}</title>
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
        }

        .page-header-title {
            font-size: 20px;
            font-weight: bold;
        }

        .page-header-subtitle {
            font-size: 11px;
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
            text-align: center;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 120px;
            color: rgba(59, 130, 246, 0.03);
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
            font-size: 24px;
        }

        .header .date {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }

        .section {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 8px;
        }

        .section-blue {
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
        }

        .section-green {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
        }

        .section-yellow {
            background-color: #fefce8;
            border: 1px solid #fef08a;
        }

        .section-red {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
        }

        .section h3 {
            margin: 0 0 15px 0;
            font-size: 14px;
        }

        .stat-grid {
            display: table;
            width: 100%;
        }

        .stat-row {
            display: table-row;
        }

        .stat-label {
            display: table-cell;
            padding: 5px 10px 5px 0;
            width: 180px;
        }

        .stat-value {
            display: table-cell;
            padding: 5px 0;
            font-weight: bold;
            font-size: 14px;
        }

        .big-number {
            font-size: 28px;
            color: #1e40af;
        }

        .money {
            color: #059669;
        }

        .danger {
            color: #dc2626;
        }

        .kpi-box {
            display: inline-block;
            width: 22%;
            margin: 0 1.5% 15px 0;
            padding: 15px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .kpi-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .kpi-value {
            font-size: 20px;
            font-weight: bold;
            color: #1e40af;
        }
    </style>
</head>

<body>
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-title">📊 RAPPORT QUOTIDIEN - {{ config('app.name', 'Système UV') }}</div>
        <div class="page-header-subtitle">
            Synthèse de l'activité du {{ $date->translatedFormat('l d F Y') }}
        </div>
    </div>

    <!-- Page Footer -->
    <div class="page-footer">
        <strong>Système de Gestion UV - Document Confidentiel</strong><br>
        Rapport généré automatiquement le {{ now()->format('d/m/Y à H:i') }}
    </div>

    <!-- Watermark -->
    <div class="watermark">RAPPORT QUOTIDIEN</div>

    <!-- Content -->
    <div class="header">
        <h1>📊 Rapport Quotidien UV</h1>
        <div class="date">{{ $date->translatedFormat('l d F Y') }}</div>
    </div>

    <!-- KPI Dashboard Style -->
    <div style="margin-bottom: 20px;">
        <div class="kpi-box">
            <div class="kpi-label">Transactions</div>
            <div class="kpi-value">{{ $transactions['count'] }}</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-label">Chiffre d'Affaires</div>
            <div class="kpi-value" style="color: #059669;">{{ number_format($transactions['total_amount'] / 1000, 0) }}k
            </div>
        </div>
        <div class="kpi-box">
            <div class="kpi-label">Remboursements</div>
            <div class="kpi-value" style="color: #f59e0b;">{{ $remboursements['count'] }}</div>
        </div>
        <div class="kpi-box" style="margin-right: 0;">
            <div class="kpi-label">Dette Totale</div>
            <div class="kpi-value" style="color: #dc2626;">{{ number_format($agents['total_debt'] / 1000, 0) }}k</div>
        </div>
    </div>

    <div class="section section-blue">
        <h3>📦 Transactions du jour</h3>
        <div class="stat-grid">
            <div class="stat-row">
                <div class="stat-label">Nombre de transactions:</div>
                <div class="stat-value big-number">{{ $transactions['count'] }}</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">UV distribués:</div>
                <div class="stat-value">{{ number_format($transactions['total_uv'], 0, ',', ' ') }} UV</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Chiffre d'affaires:</div>
                <div class="stat-value money big-number">
                    {{ number_format($transactions['total_amount'], 0, ',', ' ') }} FCFA</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Retours:</div>
                <div class="stat-value">{{ $transactions['retours'] }}</div>
            </div>
        </div>
    </div>

    <div class="section section-green">
        <h3>💵 Remboursements du jour</h3>
        <div class="stat-grid">
            <div class="stat-row">
                <div class="stat-label">Nombre:</div>
                <div class="stat-value">{{ $remboursements['count'] }}</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Montant total:</div>
                <div class="stat-value money">{{ number_format($remboursements['total'], 0, ',', ' ') }} FCFA</div>
            </div>
        </div>
    </div>

    <div class="section section-yellow">
        <h3>👥 Situation des Agents</h3>
        <div class="stat-grid">
            <div class="stat-row">
                <div class="stat-label">Agents actifs:</div>
                <div class="stat-value">{{ $agents['active'] }}</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Agents avec dette:</div>
                <div class="stat-value">{{ $agents['with_debt'] }}</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Dette totale en cours:</div>
                <div class="stat-value danger">{{ number_format($agents['total_debt'], 0, ',', ' ') }} FCFA</div>
            </div>
        </div>
    </div>

    @if ($agents['critical'] > 0)
        <div class="section section-red">
            <h3>🚨 ALERTES CRITIQUES</h3>
            <p style="margin: 0;">
                <strong>{{ $agents['critical'] }} agent(s)</strong> ont atteint un niveau de dette critique (>90% du
                plafond).
                ⚠️ Action immédiate recommandée : relance, suspension ou réajustement du plafond.
            </p>
        </div>
    @endif

    <!-- Performance Indicator -->
    <div style="margin-top: 20px; padding: 15px; background-color: #f0f9ff; border-left: 4px solid #3b82f6;">
        <h3 style="margin: 0 0 10px 0; color: #1e40af;">📈 Indicateurs de Performance</h3>
        <div class="stat-grid">
            <div class="stat-row">
                <div class="stat-label">Taux de recouvrement :</div>
                <div class="stat-value">
                    @php
                        $recoveryRate =
                            $transactions['total_amount'] > 0
                                ? ($remboursements['total'] / $transactions['total_amount']) * 100
                                : 0;
                    @endphp
                    <span
                        style="color: {{ $recoveryRate > 50 ? '#059669' : '#dc2626' }}">{{ number_format($recoveryRate, 1) }}%</span>
                </div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Balance nette du jour :</div>
                <div
                    class="stat-value {{ $transactions['total_amount'] > $remboursements['total'] ? 'danger' : 'money' }}">
                    {{ number_format($transactions['total_amount'] - $remboursements['total'], 0, ',', ' ') }} FCFA
                </div>
            </div>
        </div>
    </div>
</body>

</html>
