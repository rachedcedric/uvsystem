<!DOCTYPE html>
<html>
{{-- Rapport d'une catégorie (Rapports et exports) : modèle léger pour DomPDF (mémoire limitée des hébergements). --}}
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
        .r { text-align: right; white-space: nowrap; }
        .x { color: #9ca3af; text-decoration: line-through; }
        .neg { color: #15803d; }
        .pos { color: #b91c1c; }
        tfoot td { font-weight: bold; border-top: 2px solid #0b6b3a; border-bottom: 0; }
    </style>
</head>
<body>
    <h1>{{ $company }} — {{ $title }}</h1>
    <div class="sub">Généré le {{ $generatedAt->format('d/m/Y à H:i') }} par {{ $generatedBy }}</div>

    <div class="box">
        @if ($dated)Période : <strong>{{ $period }}</strong> ({{ $from }} → {{ $to }}, fin incluse) — @endif
        <strong>{{ $count }}</strong> ligne(s) — {{ $totalLabel }} : <strong>{{ $total }} FCFA</strong>
        @if ($filtersLabel) <br>Filtres : {{ $filtersLabel }} @endif
    </div>

    @if (! empty($extra))
        <div class="box">
            @foreach ($extra as $label => $value){{ $label }} : <strong>{{ $value }}</strong>@if (! $loop->last) &nbsp;·&nbsp; @endif @endforeach
        </div>
    @endif

    @if ($truncated)
        <div class="warn">Seules les {{ $truncated }} premières lignes sur {{ $count }} sont listées (limite de mémoire du PDF). Le nombre de lignes et les totaux portent sur toute la période ; l'export Excel contient tout le détail.</div>
    @endif

    <table>
        <thead>
            <tr>@foreach ($columns as $column)<th class="{{ in_array($column['type'], ['money', 'signed'], true) ? 'r' : '' }}">{{ $column['label'] }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row['cells'] as $i => $cell)
                        @php($type = $columns[$i]['type'])
                        <td class="{{ in_array($type, ['money', 'signed'], true) ? 'r' : '' }} {{ ! empty($row['cancelled']) ? 'x' : '' }} {{ $type === 'signed' ? ((float) $cell > 0 ? 'pos' : ((float) $cell < 0 ? 'neg' : '')) : '' }}">{{ \App\Services\Reports\ReportPresenter::text($cell, $type) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">Aucune opération sur cette période.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                @foreach ($columns as $i => $column)
                    <td class="{{ isset($footer[$i]) ? 'r' : '' }}">{{ $i === 0 ? 'Total' : (isset($footer[$i]) ? \App\Services\Reports\ReportPresenter::money($footer[$i]) : '') }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
</body>
</html>
