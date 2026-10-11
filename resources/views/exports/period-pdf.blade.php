<!DOCTYPE html>
<html>
{{-- Modèle volontairement léger (pas d'en-tête fixe, de dégradé ni de filigrane) : DomPDF consomme
     beaucoup moins de mémoire, ce qui évite l'erreur 500 sur les hébergements mutualisés. --}}
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 30px 40px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #333; }
        h1 { margin: 0; font-size: 16px; color: #0b6b3a; }
        .sub { margin: 3px 0 10px; color: #666; }
        .box { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 6px 10px; margin-bottom: 8px; }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; padding: 6px 10px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0b6b3a; color: #fff; text-align: left; padding: 5px; font-size: 9px; }
        td { padding: 4px 5px; border-bottom: 1px solid #e5e7eb; }
        .r { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #0b6b3a; border-bottom: 0; }
    </style>
</head>

<body>
    <h1>{{ config('branding.name') }} — {{ $title }}</h1>
    <div class="sub">Généré le {{ $generatedAt->format('d/m/Y à H:i') }} par {{ $generatedBy }}</div>

    <div class="box">
        Période : <strong>{{ $period }}</strong> ({{ $from }} → {{ $to }}) —
        <strong>{{ $count }}</strong> ligne(s) — Total : <strong>{{ $total }} FCFA</strong>
    </div>

    @isset($extra)
        <div class="box">
            @foreach ($extra as $label => $value)
                {{ $label }} : <strong>{{ $value }}</strong>@if (! $loop->last) &nbsp;&nbsp;·&nbsp;&nbsp; @endif
            @endforeach
        </div>
    @endisset

    @if ($truncated)
        <div class="warn">
            Seules les {{ $truncated }} lignes les plus récentes sur {{ $count }} sont listées (limite de mémoire du PDF).
            Le nombre de lignes et le total ci-dessus portent sur l'ensemble de la période ; l'export Excel contient tout le détail.
        </div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th class="{{ ($column['right'] ?? false) ? 'r' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td class="{{ ($columns[$i]['right'] ?? false) ? 'r' : '' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="{{ count($columns) - 1 }}">Total de la période</td>
                <td class="r">{{ $total }}</td>
            </tr>
        </tfoot>
    </table>
</body>

</html>
