<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Situation Financière Agents</title>
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
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            color: white;
            padding: 15px 30px;
        }

        .page-header-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .page-header-subtitle {
            font-size: 10px;
            opacity: 0.9;
        }

        .page-footer {
            position: fixed;
            bottom: -60px;
            left: 0;
            right: 0;
            height: 60px;
            border-top: 2px solid #8b5cf6;
            padding: 15px 30px;
            font-size: 9px;
            color: #666;
        }

        .page-footer-left {
            float: left;
            width: 60%;
        }

        .page-footer-right {
            float: right;
            width: 40%;
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
            font-size: 100px;
            color: rgba(139, 92, 246, 0.03);
            font-weight: bold;
            z-index: -1;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #8b5cf6;
        }

        .header h1 {
            margin: 0;
            color: #8b5cf6;
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
            background-color: #8b5cf6;
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

        .status-active {
            color: #22c55e;
            font-weight: bold;
        }

        .status-inactive {
            color: #ef4444;
            font-weight: bold;
        }

        .status-suspended {
            color: #f59e0b;
            font-weight: bold;
        }

        .debt-critical {
            background-color: #fef2f2 !important;
            color: #dc2626;
        }

        .debt-warning {
            background-color: #fffbeb !important;
        }

        .summary {
            margin-top: 20px;
            padding: 15px;
            background-color: #f5f3ff;
            border-radius: 5px;
        }

        .summary h3 {
            margin: 0 0 10px 0;
            color: #7c3aed;
        }

        .summary-item {
            display: inline-block;
            margin-right: 30px;
        }

        .confidential {
            color: #dc2626;
            font-weight: bold;
            font-size: 9px;
            margin-top: 5px;
        }
    </style>
</head>

<body>
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-title">@include('exports.partials.brand-mark'){{ config('branding.name') }} - RAPPORT FINANCIER</div>
        <div class="page-header-subtitle">
            Situation Financière des Agents | Généré le {{ now()->format('d/m/Y à H:i') }}
        </div>
        <div class="confidential">DOCUMENT CONFIDENTIEL - USAGE INTERNE UNIQUEMENT</div>
    </div>

    <!-- Page Footer -->
    <div class="page-footer">
        <div class="page-footer-left">
            <strong>{{ config('branding.tagline') }}</strong><br>
            Document automatisé - Ne pas distribuer
        </div>
        <div class="page-footer-right">
            <span class="page-number"></span><br>
            Imprimé le {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    <!-- Watermark -->
    <div class="watermark">CONFIDENTIEL</div>

    <!-- Content -->
    <div class="header">
        <h1>Situation Financière des Agents</h1>
        <div class="meta">
            Total Agents: {{ $agents->count() }} | Rapport au {{ now()->format('d/m/Y') }}
        </div>
    </div>

    <div class="summary">
        <h3>Résumé Global</h3>
        <span class="summary-item">Total Agents: <strong>{{ $agents->count() }}</strong></span>
        <span class="summary-item">Actifs: <strong>{{ $agents->where('status', 'active')->count() }}</strong></span>
        <span class="summary-item">Dette Totale:
            <strong>{{ number_format($agents->sum('current_debt'), 0, ',', ' ') }} FCFA</strong></span>
        <span class="summary-item">⚠Alertes:
            <strong>{{ $agents->filter(fn($a) => $a->debt_limit > 0 && ($a->current_debt / $a->debt_limit) * 100 > 80)->count() }}</strong></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Agent</th>
                <th>Téléphone</th>
                <th>Statut</th>
                <th style="text-align: right;">Plafond</th>
                <th style="text-align: right;">Dette</th>
                <th style="text-align: right;">Crédit Dispo</th>
                <th style="text-align: right;">Ratio %</th>
            </tr>
        </thead>
        <tbody>
            @forelse($agents as $agent)
                @php
                    $ratio = $agent->debt_limit > 0 ? ($agent->current_debt / $agent->debt_limit) * 100 : 0;
                    $rowClass = $ratio > 90 ? 'debt-critical' : ($ratio > 70 ? 'debt-warning' : '');
                @endphp
                <tr class="{{ $rowClass }}">
                    <td><strong>{{ $agent->name }}</strong></td>
                    <td>{{ $agent->phone }}</td>
                    <td class="status-{{ $agent->status }}">
                        {{ $agent->status === 'active' ? '✓ Actif' : ($agent->status === 'suspended' ? 'Suspendu' : '✗ Inactif') }}
                    </td>
                    <td style="text-align: right;">{{ number_format($agent->debt_limit, 0, ',', ' ') }}</td>
                    <td style="text-align: right;">{{ number_format($agent->current_debt, 0, ',', ' ') }}</td>
                    <td style="text-align: right;">
                        {{ number_format(max(0, $agent->debt_limit - $agent->current_debt), 0, ',', ' ') }}</td>
                    <td style="text-align: right; font-weight: bold;">{{ number_format($ratio, 1) }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; color: #999;">Aucun agent trouvé</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="background-color: #8b5cf6; color: white; font-weight: bold;">
                <td colspan="3">TOTAUX</td>
                <td style="text-align: right;">{{ number_format($agents->sum('debt_limit'), 0, ',', ' ') }} F</td>
                <td style="text-align: right;">{{ number_format($agents->sum('current_debt'), 0, ',', ' ') }} F</td>
                <td style="text-align: right;">
                    {{ number_format($agents->sum(fn($a) => max(0, $a->debt_limit - $a->current_debt)), 0, ',', ' ') }}
                    F</td>
                <td style="text-align: right;">-</td>
            </tr>
        </tfoot>
    </table>

    <!-- Notes et légende -->
    <div
        style="margin-top: 20px; padding: 10px; background-color: #fef3c7; border-left: 4px solid #f59e0b; font-size: 9px;">
        <strong>⚠Légende :</strong><br>
        Fond rouge : Dette critique (>90% du plafond) - Action immédiate requise<br>
        Fond jaune : Dette élevée (>70% du plafond) - Surveillance renforcée<br>
        Actif | Suspendu | ✗ Inactif
    </div>
</body>

</html>

</html>
