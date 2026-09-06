<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçu de Remboursement - {{ $numero_recu }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            padding: 20px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #2563eb;
            font-size: 24px;
            margin-bottom: 5px;
        }

        .header p {
            color: #666;
            font-size: 11px;
        }

        .receipt-number {
            background: #2563eb;
            color: white;
            padding: 8px 15px;
            display: inline-block;
            border-radius: 4px;
            margin: 10px 0;
            font-weight: bold;
        }

        .section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .section-title {
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 10px;
            font-size: 14px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 5px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .info-label {
            color: #666;
            width: 40%;
        }

        .info-value {
            font-weight: bold;
            width: 60%;
            text-align: right;
        }

        .amount-box {
            background: #10b981;
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }

        .amount-label {
            font-size: 12px;
            opacity: 0.9;
        }

        .amount-value {
            font-size: 28px;
            font-weight: bold;
            margin-top: 5px;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            color: #666;
            font-size: 10px;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
        }

        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }

        .signature-box {
            width: 45%;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
        }

        .stamp {
            border: 2px solid #10b981;
            color: #10b981;
            padding: 5px 15px;
            display: inline-block;
            border-radius: 4px;
            font-weight: bold;
            transform: rotate(-5deg);
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>🏦 UV Distribution System</h1>
        <p>Système de Gestion des Unités de Valeur</p>
        <div class="receipt-number">{{ $numero_recu }}</div>
    </div>

    <h2 style="text-align: center; margin-bottom: 20px;">REÇU DE REMBOURSEMENT</h2>

    <div class="section">
        <div class="section-title">📋 Informations Agent</div>
        <div class="info-row">
            <span class="info-label">Nom:</span>
            <span class="info-value">{{ $agent->name ?? 'N/A' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Téléphone:</span>
            <span class="info-value">{{ $agent->phone ?? 'N/A' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Email:</span>
            <span class="info-value">{{ $agent->email ?? 'N/A' }}</span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">💳 Détails du Remboursement</div>
        <div class="info-row">
            <span class="info-label">Date de paiement:</span>
            <span class="info-value">{{ $date }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Mode de paiement:</span>
            <span
                class="info-value">{{ $remboursement->paymentMethod->name ?? ($remboursement->payment_method ?? 'N/A') }}</span>
        </div>
        @if ($remboursement->reference)
            <div class="info-row">
                <span class="info-label">Référence:</span>
                <span class="info-value">{{ $remboursement->reference }}</span>
            </div>
        @endif
    </div>

    <div class="amount-box">
        <div class="amount-label">MONTANT REMBOURSÉ</div>
        <div class="amount-value">{{ number_format($remboursement->amount, 0, ',', ' ') }} FCFA</div>
    </div>

    @if ($remboursement->notes)
        <div class="section">
            <div class="section-title">📝 Notes</div>
            <p>{{ $remboursement->notes }}</p>
        </div>
    @endif

    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line">Signature Agent</div>
        </div>
        <div class="signature-box">
            <div class="signature-line">Signature Master</div>
            <div class="stamp">PAYÉ ✓</div>
        </div>
    </div>

    <div class="footer">
        <p>Ce reçu a été généré automatiquement le {{ $generated_at }}</p>
        <p>Document à conserver pour vos archives</p>
        <p style="margin-top: 10px;">UV Distribution System - Tous droits réservés © {{ date('Y') }}</p>
    </div>
</body>

</html>
