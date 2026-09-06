<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $periodLabel }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            margin: -20px -20px 20px -20px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 22px;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .header .date {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 10px;
        }
        
        .company-info {
            text-align: right;
            font-size: 10px;
            margin-bottom: 20px;
            color: #666;
        }
        
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .section-title {
            background: #f3f4f6;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: bold;
            color: #374151;
            border-left: 4px solid #667eea;
            margin-bottom: 15px;
        }
        
        .stats-grid {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .stats-row {
            display: table-row;
        }
        
        .stat-box {
            display: table-cell;
            width: 25%;
            padding: 10px;
            text-align: center;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        
        .stat-box .value {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-box .label {
            font-size: 10px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .stat-box.success .value { color: #10b981; }
        .stat-box.warning .value { color: #f59e0b; }
        .stat-box.danger .value { color: #ef4444; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10px;
        }
        
        table th {
            background: #374151;
            color: white;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
        }
        
        table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        table tr:nth-child(even) {
            background: #f9fafb;
        }
        
        table tr:hover {
            background: #f3f4f6;
        }
        
        .amount {
            text-align: right;
            font-family: 'DejaVu Sans Mono', monospace;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }
        
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        
        .summary-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .summary-box h3 {
            color: #0369a1;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #bae6fd;
        }
        
        .summary-row:last-child {
            border-bottom: none;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .alert-box {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        
        .alert-warning {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        
        .alert-danger {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        
        .highlight {
            background: #fef3c7;
            padding: 2px 5px;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <!-- En-tête -->
    <div class="header">
        <h1>📊 {{ $periodLabel }}</h1>
        <div class="subtitle">Système de Gestion UV</div>
        <div class="date">Généré le {{ $generatedAt->format('d/m/Y à H:i') }}</div>
    </div>
    
    <div class="company-info">
        <strong>{{ $company['name'] }}</strong><br>
        {{ $company['email'] }}
    </div>
    
    <!-- Statistiques clés -->
    <div class="section">
        <div class="section-title">📈 Statistiques Clés</div>
        
        <table>
            <tr>
                <td style="width: 25%; text-align: center; background: #f9fafb; padding: 15px;">
                    <div style="font-size: 24px; font-weight: bold; color: #667eea;">
                        {{ number_format($data['stats']['total_uv_distribues'], 0, ',', ' ') }}
                    </div>
                    <div style="font-size: 10px; color: #6b7280; margin-top: 5px;">UV Distribués</div>
                </td>
                <td style="width: 25%; text-align: center; background: #f9fafb; padding: 15px;">
                    <div style="font-size: 24px; font-weight: bold; color: #10b981;">
                        {{ number_format($data['stats']['total_montant_transactions'], 0, ',', ' ') }}
                    </div>
                    <div style="font-size: 10px; color: #6b7280; margin-top: 5px;">FCFA Transactions</div>
                </td>
                <td style="width: 25%; text-align: center; background: #f9fafb; padding: 15px;">
                    <div style="font-size: 24px; font-weight: bold; color: #3b82f6;">
                        {{ number_format($data['stats']['total_remboursements'], 0, ',', ' ') }}
                    </div>
                    <div style="font-size: 10px; color: #6b7280; margin-top: 5px;">FCFA Remboursés</div>
                </td>
                <td style="width: 25%; text-align: center; background: #f9fafb; padding: 15px;">
                    <div style="font-size: 24px; font-weight: bold; color: #ef4444;">
                        {{ number_format($data['stats']['dette_totale'], 0, ',', ' ') }}
                    </div>
                    <div style="font-size: 10px; color: #6b7280; margin-top: 5px;">FCFA Dette Totale</div>
                </td>
            </tr>
        </table>
        
        <table>
            <tr>
                <td style="width: 33%; text-align: center; padding: 10px;">
                    <strong>{{ $data['stats']['nb_transactions'] }}</strong> transactions
                </td>
                <td style="width: 33%; text-align: center; padding: 10px;">
                    <strong>{{ $data['stats']['nb_remboursements'] }}</strong> remboursements
                </td>
                <td style="width: 33%; text-align: center; padding: 10px;">
                    <strong>{{ $data['stats']['nb_agents_actifs'] }}</strong> agents actifs
                </td>
            </tr>
        </table>
    </div>
    
    <!-- Top 5 Agents -->
    @if($data['top_agents']->count() > 0)
    <div class="section">
        <div class="section-title">🏆 Top 5 Agents (par volume)</div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 35%;">Agent</th>
                    <th style="width: 20%;">Téléphone</th>
                    <th style="width: 20%;" class="amount">UV Distribués</th>
                    <th style="width: 20%;" class="amount">Montant (FCFA)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['top_agents'] as $index => $item)
                <tr>
                    <td><strong>{{ $index + 1 }}</strong></td>
                    <td>{{ $item['agent']->name ?? 'N/A' }}</td>
                    <td>{{ $item['agent']->phone ?? 'N/A' }}</td>
                    <td class="amount">{{ number_format($item['total_uv'], 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format($item['total_montant'], 0, ',', ' ') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    
    <!-- Agents avec dette critique -->
    @if($data['agents_with_debt']->count() > 0)
    <div class="section">
        <div class="section-title">⚠️ Agents avec dette ({{ $data['agents_with_debt']->count() }})</div>
        
        @if($data['agents_with_debt']->where('current_debt', '>', function($q) { return $q->debt_limit * 0.9; })->count() > 0)
        <div class="alert-box alert-danger">
            <strong>⚠️ Attention :</strong> {{ $data['stats']['nb_agents_endettes'] }} agents ont une dette en cours.
            Certains approchent de leur plafond autorisé.
        </div>
        @endif
        
        <table>
            <thead>
                <tr>
                    <th style="width: 25%;">Agent</th>
                    <th style="width: 15%;">Téléphone</th>
                    <th style="width: 15%;" class="amount">Dette (FCFA)</th>
                    <th style="width: 15%;" class="amount">Plafond (FCFA)</th>
                    <th style="width: 15%;" class="amount">Ratio</th>
                    <th style="width: 15%;">Statut</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['agents_with_debt']->take(10) as $agent)
                @php
                    $ratio = $agent->debt_limit > 0 ? round(($agent->current_debt / $agent->debt_limit) * 100, 1) : 0;
                    $badgeClass = $ratio >= 90 ? 'danger' : ($ratio >= 70 ? 'warning' : 'success');
                @endphp
                <tr>
                    <td>{{ $agent->name }}</td>
                    <td>{{ $agent->phone }}</td>
                    <td class="amount">{{ number_format($agent->current_debt, 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format($agent->debt_limit, 0, ',', ' ') }}</td>
                    <td class="amount">
                        <span class="badge badge-{{ $badgeClass }}">{{ $ratio }}%</span>
                    </td>
                    <td>
                        @if($ratio >= 90)
                            <span class="badge badge-danger">Critique</span>
                        @elseif($ratio >= 70)
                            <span class="badge badge-warning">Attention</span>
                        @else
                            <span class="badge badge-success">OK</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        @if($data['agents_with_debt']->count() > 10)
        <p style="text-align: center; color: #6b7280; font-size: 10px; margin-top: 10px;">
            ... et {{ $data['agents_with_debt']->count() - 10 }} autres agents avec dette
        </p>
        @endif
    </div>
    @endif
    
    <!-- Résumé financier -->
    <div class="section">
        <div class="section-title">💰 Résumé Financier</div>
        
        <div class="summary-box">
            <h3>Bilan de la période</h3>
            <table style="margin: 0;">
                <tr>
                    <td style="border: none;">Total des distributions</td>
                    <td style="border: none; text-align: right;">{{ number_format($data['stats']['total_montant_transactions'], 0, ',', ' ') }} FCFA</td>
                </tr>
                <tr>
                    <td style="border: none;">Total des remboursements</td>
                    <td style="border: none; text-align: right;">{{ number_format($data['stats']['total_remboursements'], 0, ',', ' ') }} FCFA</td>
                </tr>
                <tr style="border-top: 2px solid #0369a1;">
                    <td style="border: none; font-weight: bold;">Solde net</td>
                    <td style="border: none; text-align: right; font-weight: bold;">
                        {{ number_format($data['stats']['total_montant_transactions'] - $data['stats']['total_remboursements'], 0, ',', ' ') }} FCFA
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Pied de page -->
    <div class="footer">
        <p>
            <strong>{{ $company['name'] }}</strong> • {{ $company['email'] }}<br>
            Ce rapport a été généré automatiquement par le système UV.<br>
            © {{ date('Y') }} - Tous droits réservés
        </p>
    </div>
</body>
</html>
