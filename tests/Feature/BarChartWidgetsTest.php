<?php

namespace Tests\Feature;

use App\Filament\Agent\Widgets\AgentDebtChartWidget;
use App\Filament\Widgets\CaChartWidget;
use App\Filament\Widgets\CashFlowPredictionWidget;
use App\Filament\Widgets\CommissionMonthlyChartWidget;
use App\Filament\Widgets\MonthlyChartWidget;
use App\Filament\Widgets\TopAgentsWidget;
use App\Support\DailyBarChart;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BarChartWidgetsTest extends TestCase
{
    public function test_all_full_size_charts_use_bars(): void
    {
        $widgets = [
            CaChartWidget::class,
            MonthlyChartWidget::class,
            CommissionMonthlyChartWidget::class,
            CashFlowPredictionWidget::class,
            AgentDebtChartWidget::class,
            TopAgentsWidget::class,
        ];

        foreach ($widgets as $widgetClass) {
            $type = (fn () => $this->getType())->call(new $widgetClass);

            $this->assertSame('bar', $type, "{$widgetClass} doit utiliser des barres.");
        }
    }

    public function test_daily_colors_highlight_best_and_worst_days(): void
    {
        $colors = DailyBarChart::colors(
            [100, 300, 50],
            'rgba(59, 130, 246, 0.68)',
            'rgb(37, 99, 235)',
        );

        $this->assertSame('rgba(34, 197, 94, 0.78)', $colors['background'][1]);
        $this->assertSame('rgba(239, 68, 68, 0.72)', $colors['background'][2]);
    }

    public function test_lower_debt_is_marked_as_the_better_day(): void
    {
        $colors = DailyBarChart::colors(
            [100, 300, 50],
            'rgba(59, 130, 246, 0.68)',
            'rgb(37, 99, 235)',
            false,
        );

        $this->assertSame('rgba(34, 197, 94, 0.78)', $colors['background'][2]);
        $this->assertSame('rgba(239, 68, 68, 0.72)', $colors['background'][1]);
    }

    public function test_tooltip_displays_date_and_exact_amount(): void
    {
        $options = DailyBarChart::options();
        $javascript = (string) $options;

        $this->assertInstanceOf(RawJs::class, $options);
        $this->assertStringContainsString("'Date' + ' : '", $javascript);
        $this->assertStringContainsString("Intl.NumberFormat('fr-FR')", $javascript);
        $this->assertStringContainsString("+ ' FCFA'", $javascript);
    }

    public function test_specialized_tooltips_keep_their_context(): void
    {
        $predictionOptions = (string) DailyBarChart::options(showPredictionRange: true);
        $agentOptions = (string) DailyBarChart::options(title: 'Agent', horizontal: true);

        $this->assertStringContainsString('Fourchette', $predictionOptions);
        $this->assertStringContainsString("indexAxis: 'y'", $agentOptions);
        $this->assertStringContainsString("'Agent' + ' : '", $agentOptions);
    }

    public function test_raw_options_do_not_break_the_blade_html_attribute(): void
    {
        $variants = [
            DailyBarChart::options(),
            DailyBarChart::options(showPredictionRange: true),
            DailyBarChart::options(title: 'Agent', horizontal: true),
        ];

        foreach ($variants as $options) {
            $html = Blade::render(<<<'BLADE'
                <div x-data="chart({ options: @js($options), type: @js($type) })">CANVAS</div>
                BLADE, [
                'options' => $options,
                'type' => 'bar',
            ]);

            $this->assertSame(2, substr_count($html, '"'));
            $this->assertStringContainsString('title: (items)', $html);
            $this->assertStringEndsWith('})">CANVAS</div>', trim($html));
        }
    }
}
