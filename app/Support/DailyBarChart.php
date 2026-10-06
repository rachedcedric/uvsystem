<?php

namespace App\Support;

use Filament\Support\RawJs;

class DailyBarChart
{
    /**
     * @return array{background: array<int, string>, border: array<int, string>}
     */
    public static function colors(
        array $values,
        string $baseBackground,
        string $baseBorder,
        bool $higherIsBetter = true,
    ): array {
        $values = array_map('floatval', $values);
        $background = array_fill(0, count($values), $baseBackground);
        $border = array_fill(0, count($values), $baseBorder);

        if (count($values) < 2 || max($values) === min($values)) {
            return compact('background', 'border');
        }

        $bestIndex = array_search($higherIsBetter ? max($values) : min($values), $values, true);
        $worstIndex = array_search($higherIsBetter ? min($values) : max($values), $values, true);

        $background[$bestIndex] = 'rgba(34, 197, 94, 0.78)';
        $border[$bestIndex] = 'rgb(22, 163, 74)';
        $background[$worstIndex] = 'rgba(239, 68, 68, 0.72)';
        $border[$worstIndex] = 'rgb(220, 38, 38)';

        return compact('background', 'border');
    }

    public static function indicators(array $labels, array $values, bool $higherIsBetter = true): ?string
    {
        if ($values === []) {
            return null;
        }

        $values = array_map('floatval', $values);
        $bestIndex = array_search($higherIsBetter ? max($values) : min($values), $values, true);
        $worstIndex = array_search($higherIsBetter ? min($values) : max($values), $values, true);

        return sprintf(
            'Meilleure : %s (%s FCFA) · Moins bonne : %s (%s FCFA)',
            $labels[$bestIndex],
            number_format($values[$bestIndex], 0, ',', ' '),
            $labels[$worstIndex],
            number_format($values[$worstIndex], 0, ',', ' '),
        );
    }

    public static function options(
        bool $showLegend = false,
        string $title = 'Date',
        bool $horizontal = false,
        bool $showPredictionRange = false,
    ): RawJs {
        $legendDisplay = $showLegend ? 'true' : 'false';
        $encodedTitle = json_encode(
            $title,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
        $title = "'".substr($encodedTitle, 1, -1)."'";
        $indexAxis = $horizontal ? "indexAxis: 'y'," : '';
        $afterLabel = $showPredictionRange
            ? <<<'JS'
                afterLabel: (context) => 'Fourchette : '
                    + new Intl.NumberFormat('fr-FR').format(context.dataset.minimums[context.dataIndex])
                    + ' à '
                    + new Intl.NumberFormat('fr-FR').format(context.dataset.maximums[context.dataIndex])
                    + ' FCFA',
                JS
            : '';
        $scales = $horizontal
            ? <<<'JS'
                {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(value) + ' F',
                        },
                    },
                    y: {
                        grid: { display: false },
                    },
                }
                JS
            : <<<'JS'
                {
                    x: {
                        grid: { display: false },
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 10,
                            maxRotation: 0,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(value) + ' F',
                        },
                    },
                }
                JS;

        return RawJs::make(<<<JS
            {
                maintainAspectRatio: false,
                {$indexAxis}
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: {$legendDisplay},
                        position: 'top',
                    },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            title: (items) => items.length ? {$title} + ' : ' + items[0].label : '',
                            label: (context) => (context.dataset.label || 'Montant') + ' : '
                                + new Intl.NumberFormat('fr-FR').format(context.raw)
                                + ' FCFA',
                            {$afterLabel}
                        },
                    },
                },
                scales: {$scales},
            }
            JS);
    }
}
