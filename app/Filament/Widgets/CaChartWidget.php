<?php

namespace App\Filament\Widgets;

use App\Models\UvTransaction;
use App\Support\DailyBarChart;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class CaChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Chiffre d\'Affaires — 7 derniers jours';

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    /**
     * @return array<int, float> montant du CA (ventes) pour chacun des 7 derniers
     *                           jours calendaires (aujourd'hui inclus), les jours
     *                           sans vente valent explicitement 0 (jamais omis).
     */
    private function getDailyRevenue(): array
    {
        $rows = UvTransaction::where('transaction_type', 'distribution')
            ->where('transaction_date', '>=', Carbon::today()->subDays(6))
            ->selectRaw('DATE(transaction_date) as jour, SUM(amount) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        return collect(range(6, 0))
            ->mapWithKeys(function ($daysAgo) use ($rows) {
                $date = Carbon::today()->subDays($daysAgo)->toDateString();

                return [$date => (float) ($rows[$date] ?? 0)];
            })
            ->toArray();
    }

    protected function getData(): array
    {
        $revenue = $this->getDailyRevenue();

        $labels = array_map(
            fn (string $date) => Carbon::parse($date)->format('d/m/Y'),
            array_keys($revenue)
        );
        $values = array_values($revenue);
        $colors = DailyBarChart::colors($values, 'rgba(59, 130, 246, 0.68)', 'rgb(37, 99, 235)');

        return [
            'datasets' => [
                [
                    'label' => 'CA Journalier (FCFA)',
                    'data' => $values,
                    'backgroundColor' => $colors['background'],
                    'borderColor' => $colors['border'],
                    'borderWidth' => 1,
                    'borderRadius' => 4,
                    'borderSkipped' => false,
                    'barPercentage' => 0.72,
                    'categoryPercentage' => 0.82,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        $revenue = array_values($this->getDailyRevenue());
        $total = array_sum($revenue);
        $moyenne = count($revenue) > 0 ? $total / count($revenue) : 0;

        $totalPrecedent = (float) UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [Carbon::today()->subDays(13), Carbon::today()->subDays(7)->endOfDay()])
            ->sum('amount');

        $variation = $totalPrecedent > 0
            ? (($total - $totalPrecedent) / $totalPrecedent) * 100
            : ($total > 0 ? 100.0 : 0.0);

        $sign = $variation > 0 ? '+' : '';
        $labels = array_map(
            fn (string $date) => Carbon::parse($date)->format('d/m/Y'),
            array_keys($this->getDailyRevenue())
        );
        $indicators = DailyBarChart::indicators($labels, $revenue);

        return sprintf(
            'Total : %s FCFA · Moyenne/jour : %s FCFA · %s%% %s vs 7 jours précédents · %s',
            number_format($total, 0, ',', ' '),
            number_format($moyenne, 0, ',', ' '),
            $sign.number_format($variation, 1, ',', ' '),
            $variation >= 0 ? '📈' : '📉',
            $indicators,
        );
    }

    protected function getOptions(): RawJs
    {
        return DailyBarChart::options();
    }
}
