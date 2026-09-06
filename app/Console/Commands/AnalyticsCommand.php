<?php

namespace App\Console\Commands;

use App\Services\AnalyticsService;
use Illuminate\Console\Command;

class AnalyticsCommand extends Command
{
    protected $signature = 'analytics:show
                            {type=kpis : Type d\'analyse (kpis, cashflow, scoring, trends)}
                            {--period=month : Période (today, week, month, quarter, year)}
                            {--days=30 : Jours de prédiction pour cashflow}
                            {--export : Exporter en JSON}';

    protected $description = 'Afficher les analytics et prédictions du système';

    public function __construct(protected AnalyticsService $analytics)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = $this->argument('type');

        $this->info('');
        $this->info('📊 Analytics MODELEC CI');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        return match ($type) {
            'kpis' => $this->showKpis(),
            'cashflow' => $this->showCashFlow(),
            'scoring' => $this->showScoring(),
            'trends' => $this->showTrends(),
            default => $this->showKpis(),
        };
    }

    protected function showKpis(): int
    {
        $period = $this->option('period');
        $this->info("📈 KPIs - Période: {$period}");
        $this->info('');

        $kpis = $this->analytics->getDashboardKpis($period);

        // Chiffre d'affaires
        $caIcon = $kpis['chiffre_affaires']['trend'] === 'up' ? '📈' : '📉';
        $caGrowth = $kpis['chiffre_affaires']['growth'] >= 0
            ? "+{$kpis['chiffre_affaires']['growth']}%"
            : "{$kpis['chiffre_affaires']['growth']}%";

        $this->table(
            ['Indicateur', 'Valeur', 'Évolution'],
            [
                [
                    'Chiffre d\'affaires',
                    number_format($kpis['chiffre_affaires']['value'], 0, ',', ' ').' FCFA',
                    "{$caIcon} {$caGrowth}",
                ],
                [
                    'UV distribués',
                    number_format($kpis['uv_distribues']['value'], 0, ',', ' '),
                    ($kpis['uv_distribues']['growth'] >= 0 ? '+' : '').$kpis['uv_distribues']['growth'].'%',
                ],
                [
                    'Remboursements',
                    number_format($kpis['remboursements']['value'], 0, ',', ' ').' FCFA',
                    $kpis['remboursements']['count'].' opérations',
                ],
                [
                    'Transactions',
                    $kpis['transactions']['count'],
                    'Moy. '.number_format($kpis['transactions']['avg_amount'], 0, ',', ' ').' FCFA',
                ],
                [
                    'Agents actifs',
                    $kpis['agents_actifs']['count'],
                    '-',
                ],
                [
                    'Dette totale',
                    number_format($kpis['dette_totale']['value'], 0, ',', ' ').' FCFA',
                    $kpis['dette_totale']['agents_count'].' agents',
                ],
            ]
        );

        if ($this->option('export')) {
            $this->exportJson('kpis', $kpis);
        }

        return self::SUCCESS;
    }

    protected function showCashFlow(): int
    {
        $days = (int) $this->option('days');
        $this->info("💰 Prédiction Cash-Flow ({$days} jours)");
        $this->info('');

        $prediction = $this->analytics->predictCashFlow($days);

        // Résumé
        $trendIcon = match ($prediction['trend']) {
            'up' => '📈',
            'down' => '📉',
            default => '➡️',
        };

        $this->info("🎯 Tendance: {$trendIcon} {$prediction['trend']}");
        $this->info("📊 Confiance: {$prediction['confidence']}%");
        $this->info('📅 Moyenne journalière: '.number_format($prediction['avg_daily'], 0, ',', ' ').' FCFA');
        $this->info("💵 Total prédit ({$days}j): ".number_format($prediction['total_predicted'], 0, ',', ' ').' FCFA');
        $this->info('');

        // Tableau des prédictions (premiers 10 jours)
        $data = array_slice($prediction['predictions'], 0, 10);

        $this->table(
            ['Date', 'Prédit (FCFA)', 'Min', 'Max'],
            array_map(fn ($p) => [
                $p['day'],
                number_format($p['predicted'], 0, ',', ' '),
                number_format($p['lower'], 0, ',', ' '),
                number_format($p['upper'], 0, ',', ' '),
            ], $data)
        );

        if (count($prediction['predictions']) > 10) {
            $this->info('... et '.(count($prediction['predictions']) - 10).' jours supplémentaires');
        }

        if ($this->option('export')) {
            $this->exportJson('cashflow', $prediction);
        }

        return self::SUCCESS;
    }

    protected function showScoring(): int
    {
        $this->info('🏆 Scoring Agents');
        $this->info('');

        $scoring = $this->analytics->getAgentScoring();

        if ($scoring->isEmpty()) {
            $this->warn('Aucun agent trouvé');

            return self::SUCCESS;
        }

        // Top 10 agents
        $top10 = $scoring->take(10);

        $this->table(
            ['#', 'Agent', 'Score', 'Grade', 'Volume', 'Régularité', 'Paiement', 'Dette'],
            $top10->map(fn ($a, $i) => [
                $i + 1,
                $a['name'],
                $a['score'].'/100',
                $a['grade'],
                $a['details']['volume'].'/25',
                $a['details']['regularite'].'/20',
                $a['details']['paiement'].'/30',
                $a['details']['dette'].'/25',
            ])
        );

        $this->info('');

        // Légende des grades
        $this->info('📋 Légende des grades:');
        $this->line('   A+/A : Excellent (80-100) - Considérer augmentation plafond');
        $this->line('   B+/B : Bon (60-79) - Agent fiable');
        $this->line('   C+/C : Moyen (40-59) - Surveillance normale');
        $this->line('   D/E  : Faible (0-39) - Surveillance renforcée');

        // Statistiques
        $this->info('');
        $this->info('📊 Répartition:');
        $grades = $scoring->groupBy('grade')->map->count();
        foreach (['A+', 'A', 'B+', 'B', 'C+', 'C', 'D', 'E'] as $grade) {
            $count = $grades[$grade] ?? 0;
            if ($count > 0) {
                $bar = str_repeat('█', min(20, $count));
                $this->line("   {$grade}: {$bar} ({$count})");
            }
        }

        if ($this->option('export')) {
            $this->exportJson('scoring', $scoring->toArray());
        }

        return self::SUCCESS;
    }

    protected function showTrends(): int
    {
        $this->info('📈 Tendances (6 derniers mois)');
        $this->info('');

        $trends = $this->analytics->getTrends(6);

        $this->table(
            ['Mois', 'Transactions', 'CA (FCFA)', 'UV', 'Rembours.', 'Agents'],
            array_map(fn ($t) => [
                $t['month'],
                $t['transactions']['count'],
                number_format($t['transactions']['total'], 0, ',', ' '),
                number_format($t['transactions']['uv'], 0, ',', ' '),
                number_format($t['remboursements']['total'], 0, ',', ' '),
                $t['agents_actifs'],
            ], $trends)
        );

        // Graphique ASCII simple
        $this->info('');
        $this->info('📊 Évolution CA:');
        $maxCA = max(array_column(array_column($trends, 'transactions'), 'total'));

        foreach ($trends as $t) {
            $value = $t['transactions']['total'];
            $barLength = $maxCA > 0 ? round(($value / $maxCA) * 30) : 0;
            $bar = str_repeat('█', (int) $barLength);
            $this->line(sprintf('   %s: %s %s',
                substr($t['month'], 0, 7),
                $bar,
                number_format($value, 0, ',', ' ')
            ));
        }

        if ($this->option('export')) {
            $this->exportJson('trends', $trends);
        }

        return self::SUCCESS;
    }

    protected function exportJson(string $type, $data): void
    {
        $filename = "analytics_{$type}_".now()->format('Y-m-d_His').'.json';
        $path = storage_path("app/exports/{$filename}");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('');
        $this->info("📁 Exporté: {$filename}");
    }
}
