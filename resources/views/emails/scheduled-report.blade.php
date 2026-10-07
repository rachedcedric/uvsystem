<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $periodLabel }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .header .date {
            opacity: 0.9;
            margin-top: 10px;
            font-size: 14px;
        }

        .content {
            padding: 30px;
        }

        .greeting {
            font-size: 16px;
            margin-bottom: 20px;
        }

        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }

        .attachments {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .attachments h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #374151;
        }

        .attachments ul {
            margin: 0;
            padding-left: 20px;
        }

        .attachments li {
            padding: 5px 0;
            color: #6b7280;
        }

        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }

        .footer {
            text-align: center;
            padding: 20px;
            background: #f9fafb;
            color: #6b7280;
            font-size: 12px;
        }

        .footer a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>📊 {{ $periodLabel }}</h1>
            <div class="date">Généré le {{ $generatedAt->format('d/m/Y à H:i') }}</div>
        </div>

        <div class="content">
            <p class="greeting">Bonjour,</p>

            <p>
                Votre rapport
                {{ strtolower($type) === 'daily' ? 'journalier' : (strtolower($type) === 'weekly' ? 'hebdomadaire' : 'mensuel') }}
                MODELEC CI est disponible en pièce jointe.
            </p>

            <div class="info-box">
                <strong>📅 Période couverte :</strong><br>
                {{ $periodLabel }}
            </div>

            <div class="attachments">
                <h3>📎 Pièces jointes :</h3>
                <ul>
                    <li>📄 Rapport PDF - Vue d'ensemble et statistiques</li>
                    <li>📊 Fichier Excel - Détail des transactions</li>
                </ul>
            </div>

            <p>
                Ce rapport contient :
            </p>
            <ul>
                <li>✅ Statistiques clés de la période</li>
                <li>✅ Top 5 des meilleurs agents</li>
                <li>✅ Liste des agents avec dette</li>
                <li>✅ Résumé financier</li>
                <li>✅ Détail de toutes les transactions</li>
            </ul>

            <p style="text-align: center;">
                <a href="{{ url('/master') }}" class="btn">
                    Accéder au tableau de bord
                </a>
            </p>

            <p style="color: #6b7280; font-size: 14px;">
                Pour toute question concernant ce rapport, contactez votre administrateur système.
            </p>
        </div>

        <div class="footer">
            <p>
                <strong>MODELEC CI</strong> - Système de Gestion et Distribution UV<br>
                <a href="{{ url('/') }}">{{ url('/') }}</a>
            </p>
            <p style="font-size: 11px; color: #9ca3af;">
                Ce message a été envoyé automatiquement. Merci de ne pas y répondre directement.
            </p>
        </div>
    </div>
</body>

</html>
