<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Liste des Agents - {{ config('branding.name') }}</title>
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
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
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
            border-top: 2px solid #3b82f6;
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
            color: rgba(59, 130, 246, 0.03);
            font-weight: bold;
            z-index: -1;
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3b82f6;
        }

        .header h1 {
            margin: 0;
            color: #3b82f6;
            font-size: 18px;
        }

        .header .subtitle {
            margin-top: 5px;
            font-size: 10px;
            color: #666;
        }

        .filters-box {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 5px;
            padding: 8px 15px;
            margin-bottom: 15px;
            font-size: 9px;
        }

        .filters-box span {
            margin-right: 20px;
        }

        .filters-box strong {
            color: #0369a1;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #3b82f6;
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

        .status-active {
            color: #22c55e;
            font-weight: bold;
        }

        .status-inactive {
            color: #6b7280;
            font-weight: bold;
        }

        .status-suspended {
            color: #f59e0b;
            font-weight: bold;
        }

        .status-blocked {
            color: #ef4444;
            font-weight: bold;
        }

        .type-standard {
            color: #6b7280;
        }

        .type-premium {
            color: #22c55e;
            font-weight: bold;
        }

        .type-vip {
            color: #f59e0b;
            font-weight: bold;
        }

        .debt-critical {
            background-color: #fef2f2 !important;
        }

        .debt-warning {
            background-color: #fffbeb !important;
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
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 5px;
        }

        .summary h3 {
            margin: 0 0 10px 0;
            color: #0369a1;
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
            color: #3b82f6;
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
        <div class="page-header-title">@include('exports.partials.brand-mark'){{ config('branding.name') }} - Gestion des Agents</div>
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
        <h1>Liste des Agents</h1>
        <div class="subtitle">
            Export complet du répertoire agents
        </div>
    </div>

    <!-- Filtres appliqués -->
    <div class="filters-box">
        <span><strong>Statut:</strong> {{ $filters['status'] }}</span>
        <span><strong>Type:</strong> {{ $filters['type'] }}</span>
        <span><strong>Total:</strong> {{ $agents->count() }} agent(s)</span>
    </div>

    <!-- Tableau des agents -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 18%;">Nom</th>
                <th style="width: 12%;">Téléphone</th>
                <th style="width: 10%;">Zone</th>
                <th style="width: 8%;">Type</th>
                <th style="width: 8%;">Statut</th>
                <th style="width: 8%;" class="text-right">Comm. %</th>
                <th style="width: 12%;" class="text-right">Plafond</th>
                <th style="width: 12%;" class="text-right">Dette</th>
                <th style="width: 7%;" class="text-center">Ratio</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($agents as $index => $agent)
                @php
                    $debtRatio = $agent->debt_limit > 0 ? ($agent->current_debt / $agent->debt_limit) * 100 : 0;
                    $rowClass = '';
                    if ($debtRatio > 80) {
                        $rowClass = 'debt-critical';
                    } elseif ($debtRatio > 50) {
                        $rowClass = 'debt-warning';
                    }
                @endphp
                <tr class="{{ $rowClass }}">
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $agent->name }}</strong></td>
                    <td>{{ $agent->phone }}</td>
                    <td>{{ $agent->zone ?? '-' }}</td>
                    <td class="type-{{ $agent->type ?? 'standard' }}">
                        @switch($agent->type)
                            @case('premium')
                                Premium
                            @break

                            @case('vip')
                                VIP
                            @break

                            @default
                                Standard
                        @endswitch
                    </td>
                    <td class="status-{{ $agent->status }}">
                        @switch($agent->status)
                            @case('active')
                                Actif
                            @break

                            @case('inactive')
                                Inactif
                            @break

                            @case('suspended')
                                ⚠Suspendu
                            @break

                            @case('blocked')
                                Bloqué
                            @break

                            @default
                                {{ $agent->status }}
                        @endswitch
                    </td>
                    <td class="text-right">{{ number_format($agent->commission_rate ?? 0, 1) }}%</td>
                    <td class="text-right">{{ number_format($agent->debt_limit ?? 0, 0, ',', ' ') }}</td>
                    <td class="text-right"
                        style="color: {{ $agent->current_debt > 0 ? '#dc2626' : '#22c55e' }}; font-weight: bold;">
                        {{ number_format($agent->current_debt ?? 0, 0, ',', ' ') }}
                    </td>
                    <td class="text-center"
                        style="color: {{ $debtRatio > 80 ? '#dc2626' : ($debtRatio > 50 ? '#f59e0b' : '#22c55e') }}; font-weight: bold;">
                        {{ number_format($debtRatio, 1) }}%
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Résumé -->
    <div class="summary">
        <h3>Résumé</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ $agents->count() }}</div>
                <div class="summary-label">Total Agents</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #22c55e;">{{ $agents->where('status', 'active')->count() }}
                </div>
                <div class="summary-label">Actifs</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #dc2626;">
                    {{ $agents->where('current_debt', '>', 0)->count() }}</div>
                <div class="summary-label">Avec Dette</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format($agents->sum('debt_limit'), 0, ',', ' ') }}</div>
                <div class="summary-label">Total Plafonds (FCFA)</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: #dc2626;">
                    {{ number_format($agents->sum('current_debt'), 0, ',', ' ') }}</div>
                <div class="summary-label">Dette Totale (FCFA)</div>
            </div>
        </div>
    </div>

</body>

</html>
