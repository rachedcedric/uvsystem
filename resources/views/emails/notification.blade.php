<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .content {
            background: #f9fafb;
            padding: 30px;
            border-radius: 0 0 10px 10px;
        }

        .priority-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .priority-high {
            background: #fee2e2;
            color: #dc2626;
        }

        .priority-normal {
            background: #dbeafe;
            color: #2563eb;
        }

        .priority-low {
            background: #f3f4f6;
            color: #6b7280;
        }

        .message {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin: 20px 0;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-size: 14px;
        }

        .footer a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>{{ $company['name'] ?? config('branding.name') }}</h1>
    </div>

    <div class="content">
        @if ($priority === 'high')
            <span class="priority-badge priority-high">⚠️ Priorité Haute</span>
        @elseif($priority === 'normal')
            <span class="priority-badge priority-normal">ℹ️ Notification</span>
        @else
            <span class="priority-badge priority-low">📌 Information</span>
        @endif

        <h2>{{ $subject }}</h2>

        <div class="message">
            {!! nl2br(e($message)) !!}
        </div>
    </div>

    <div class="footer">
        <p>
            {{ $company['name'] ?? config('branding.name') }}<br>
            Email: <a href="mailto:{{ $company['email'] ?? '' }}">{{ $company['email'] ?? '' }}</a><br>
            Téléphone: {{ $company['phone'] ?? '' }}
        </p>
        <p style="font-size: 12px; color: #9ca3af;">
            Cet email a été envoyé automatiquement. Merci de ne pas y répondre directement.
        </p>
    </div>
</body>

</html>
