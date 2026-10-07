<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AnalyticsService
{
    /**
     * Cache TTL en minutes
     */
    protected int $cacheTtl = 30;

    /**
     * Obtenir les KPIs du dashboard
     */
    public function getDashboardKpis(string $period = 'month'): array
    {
        $cacheKey = TenantContext::cacheKey("analytics_kpis_{$period}");

        return Cache::remember($cacheKey, $this->cacheTtl * 60, function () use ($period) {
            [$startDate, $endDate] = $this->getPeriodDates($period);

            // Transactions (ventes actives uniquement : ni annulées, ni retours,
            // pour que le chiffre d'affaires reflète les ventes réelles)
            $transactions = UvTransaction::where('transaction_type', 'distribution')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('transaction_date', [$startDate, $endDate])->get();
            $previousTransactions = UvTransaction::where('transaction_type', 'distribution')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('transaction_date', [
                    $startDate->copy()->sub($this->getPeriodInterval($period)),
                    $startDate->copy()->subDay(),
                ])->get();

            // Remboursements
            $remboursements = Remboursement::whereBetween('payment_date', [$startDate, $endDate])->get();

            // Calculs
            $currentCA = $transactions->sum('amount');
            $previousCA = $previousTransactions->sum('amount');
            $caGrowth = $previousCA > 0 ? round((($currentCA - $previousCA) / $previousCA) * 100, 1) : 0;

            // Nombre de transactions au lieu de quantity_uv
            $currentCount = $transactions->count();
            $previousCount = $previousTransactions->count();
            $countGrowth = $previousCount > 0 ? round((($currentCount - $previousCount) / $previousCount) * 100, 1) : 0;

            return [
                'chiffre_affaires' => [
                    'value' => $currentCA,
                    'previous' => $previousCA,
                    'growth' => $caGrowth,
                    'trend' => $caGrowth >= 0 ? 'up' : 'down',
                ],
                'transactions' => [
                    'value' => $currentCount,
                    'previous' => $previousCount,
                    'growth' => $countGrowth,
                    'trend' => $countGrowth >= 0 ? 'up' : 'down',
                ],
                'remboursements' => [
                    'value' => $remboursements->sum('amount'),
                    'count' => $remboursements->count(),
                ],
                'transactions' => [
                    'count' => $transactions->count(),
                    'avg_amount' => $transactions->count() > 0 ? round($currentCA / $transactions->count()) : 0,
                ],
                'agents_actifs' => [
                    'count' => $transactions->pluck('agent_id')->unique()->count(),
                ],
                'dette_totale' => [
                    'value' => Agent::sum('current_debt'),
                    'agents_count' => Agent::where('current_debt', '>', 0)->count(),
                ],
            ];
        });
    }

    /**
     * Prédiction du cash-flow
     */
    public function predictCashFlow(int $days = 30): array
    {
        $cacheKey = TenantContext::cacheKey("analytics_cashflow_prediction_{$days}");

        return Cache::remember($cacheKey, $this->cacheTtl * 60, function () use ($days) {
            // Historique des 90 derniers jours
            $history = $this->getDailyRevenue(90);

            if ($history->isEmpty()) {
                return [
                    'predictions' => [],
                    'confidence' => 0,
                    'trend' => 'stable',
                    'avg_daily' => 0,
                    'total_predicted' => 0,
                ];
            }

            // Calcul de la moyenne mobile (7 jours)
            $avgDaily = $history->avg('total');
            $stdDev = $this->calculateStdDev($history->pluck('total')->toArray());

            // Tendance (régression linéaire simple)
            $trend = $this->calculateTrend($history->pluck('total')->toArray());

            // Prédictions
            $predictions = [];
            $currentAvg = $history->take(-7)->avg('total'); // Moyenne des 7 derniers jours

            for ($i = 1; $i <= $days; $i++) {
                $date = now()->addDays($i);

                // Ajustement pour week-end (moins d'activité)
                $weekendFactor = $date->isWeekend() ? 0.6 : 1.0;

                // Prédiction = moyenne + tendance + bruit
                $predicted = ($currentAvg + ($trend * $i)) * $weekendFactor;
                $predicted = max(0, round($predicted));

                $predictions[] = [
                    'date' => $date->format('Y-m-d'),
                    'day' => $date->translatedFormat('D d M'),
                    'predicted' => $predicted,
                    'lower' => max(0, round($predicted - $stdDev)),
                    'upper' => round($predicted + $stdDev),
                ];
            }

            // Confiance basée sur l'écart-type relatif
            $confidence = $avgDaily > 0
                ? max(0, min(100, round(100 - ($stdDev / $avgDaily * 50))))
                : 0;

            return [
                'predictions' => $predictions,
                'confidence' => $confidence,
                'trend' => $trend > 1000 ? 'up' : ($trend < -1000 ? 'down' : 'stable'),
                'avg_daily' => round($avgDaily),
                'total_predicted' => array_sum(array_column($predictions, 'predicted')),
            ];
        });
    }

    /**
     * Scoring des agents
     */
    public function getAgentScoring(): Collection
    {
        $cacheKey = TenantContext::cacheKey('analytics_agent_scoring');

        return Cache::remember($cacheKey, $this->cacheTtl * 60, function () {
            $agents = Agent::with(['transactions', 'remboursements'])->get();

            return $agents->map(function ($agent) {
                $score = $this->calculateAgentScore($agent);

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'phone' => $agent->phone,
                    'score' => $score['total'],
                    'grade' => $this->getGrade($score['total']),
                    'details' => $score,
                    'recommendations' => $this->getRecommendations($score),
                ];
            })->sortByDesc('score')->values();
        });
    }

    /**
     * Calculer le score d'un agent
     */
    protected function calculateAgentScore(Agent $agent): array
    {
        // Critères de scoring (sur 100)
        $scores = [
            'volume' => 0,        // Volume de transactions (25 pts)
            'regularite' => 0,    // Régularité des achats (20 pts)
            'paiement' => 0,      // Comportement de paiement (30 pts)
            'dette' => 0,         // Gestion de la dette (25 pts)
        ];

        // 1. Volume de transactions (25 pts)
        $txCount = $agent->transactions()->where('created_at', '>=', now()->subMonths(3))->count();
        $scores['volume'] = min(25, round($txCount / 2)); // 1 pt pour 2 transactions

        // 2. Régularité (20 pts)
        $monthsActive = $agent->transactions()
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw('DATE_FORMAT(transaction_date, "%Y-%m") as month')
            ->distinct()
            ->count();
        $scores['regularite'] = min(20, round($monthsActive * 3.33));

        // 3. Comportement de paiement (30 pts)
        $remboursements = $agent->remboursements()->where('created_at', '>=', now()->subMonths(3))->get();
        $totalRembourse = $remboursements->sum('amount');
        $totalTransactions = $agent->transactions()
            ->where('created_at', '>=', now()->subMonths(3))
            ->sum('amount');

        if ($totalTransactions > 0) {
            $ratioRemboursement = $totalRembourse / $totalTransactions;
            $scores['paiement'] = min(30, round($ratioRemboursement * 30));
        }

        // 4. Gestion de la dette (25 pts)
        if ($agent->debt_limit > 0) {
            $debtRatio = ($agent->current_debt / $agent->debt_limit);
            $scores['dette'] = max(0, min(25, round((1 - $debtRatio) * 25)));
        } else {
            $scores['dette'] = 25; // Pas de dette = score max
        }

        $scores['total'] = array_sum($scores);

        return $scores;
    }

    /**
     * Obtenir la note (grade) selon le score
     */
    protected function getGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B+',
            $score >= 60 => 'B',
            $score >= 50 => 'C+',
            $score >= 40 => 'C',
            $score >= 30 => 'D',
            default => 'E',
        };
    }

    /**
     * Obtenir les recommandations selon le score
     */
    protected function getRecommendations(array $scores): array
    {
        $recommendations = [];

        if ($scores['volume'] < 15) {
            $recommendations[] = 'Encourager à augmenter le volume de transactions';
        }

        if ($scores['regularite'] < 12) {
            $recommendations[] = 'Améliorer la régularité des achats';
        }

        if ($scores['paiement'] < 20) {
            $recommendations[] = 'Améliorer le taux de remboursement';
        }

        if ($scores['dette'] < 15) {
            $recommendations[] = 'Réduire le niveau de dette';
        }

        if ($scores['total'] >= 80) {
            $recommendations[] = '⭐ Excellent agent - Considérer une augmentation de plafond';
        }

        return $recommendations;
    }

    /**
     * Analyse des tendances
     */
    public function getTrends(int $months = 6): array
    {
        $cacheKey = TenantContext::cacheKey("analytics_trends_{$months}");

        return Cache::remember($cacheKey, $this->cacheTtl * 60, function () use ($months) {
            $trends = [];

            for ($i = $months - 1; $i >= 0; $i--) {
                $start = now()->subMonths($i)->startOfMonth();
                $end = now()->subMonths($i)->endOfMonth();

                $transactions = UvTransaction::whereBetween('transaction_date', [$start, $end])->get();
                $remboursements = Remboursement::whereBetween('payment_date', [$start, $end])->get();

                $trends[] = [
                    'month' => $start->translatedFormat('M Y'),
                    'month_key' => $start->format('Y-m'),
                    'transactions' => [
                        'count' => $transactions->count(),
                        'total' => $transactions->sum('amount'),
                    ],
                    'remboursements' => [
                        'count' => $remboursements->count(),
                        'total' => $remboursements->sum('amount'),
                    ],
                    'agents_actifs' => $transactions->pluck('agent_id')->unique()->count(),
                    'dette_fin_mois' => $this->getDebtAtDate($end),
                ];
            }

            return $trends;
        });
    }

    /**
     * Revenus journaliers pour graphique (public)
     */
    public function getDailyRevenueChart(int $days): array
    {
        $data = $this->getDailyRevenue($days);

        if ($data->isEmpty()) {
            return array_fill(0, $days, 0);
        }

        // Convertir en tableau simple pour le chart
        // Normaliser les valeurs pour le mini-chart (échelle 0-100)
        $values = $data->pluck('total')->toArray();
        $max = max($values) ?: 1;

        return array_map(fn ($v) => round(($v / $max) * 100), $values);
    }

    /**
     * Revenus journaliers
     */
    protected function getDailyRevenue(int $days): Collection
    {
        return UvTransaction::query()
            ->where('transaction_date', '>=', now()->subDays($days))
            ->groupBy('date')
            ->selectRaw('DATE(transaction_date) as date, SUM(amount) as total')
            ->orderBy('date')
            ->get();
    }

    /**
     * Dette à une date donnée
     */
    protected function getDebtAtDate(Carbon $date): int
    {
        // Simplification : on retourne la dette actuelle
        // En production, il faudrait calculer historiquement
        return (int) Agent::sum('current_debt');
    }

    /**
     * Calculer l'écart-type
     */
    protected function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn ($x) => pow($x - $mean, 2), $values)) / $count;

        return sqrt($variance);
    }

    /**
     * Calculer la tendance (pente)
     */
    protected function calculateTrend(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $xMean = ($count - 1) / 2;
        $yMean = array_sum($values) / $count;

        $numerator = 0;
        $denominator = 0;

        foreach ($values as $i => $y) {
            $numerator += ($i - $xMean) * ($y - $yMean);
            $denominator += pow($i - $xMean, 2);
        }

        return $denominator != 0 ? $numerator / $denominator : 0;
    }

    /**
     * Obtenir les dates de période
     */
    protected function getPeriodDates(string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    /**
     * Obtenir l'intervalle de période
     */
    protected function getPeriodInterval(string $period): \DateInterval
    {
        return match ($period) {
            'today' => new \DateInterval('P1D'),
            'week' => new \DateInterval('P1W'),
            'month' => new \DateInterval('P1M'),
            'quarter' => new \DateInterval('P3M'),
            'year' => new \DateInterval('P1Y'),
            default => new \DateInterval('P1M'),
        };
    }

    /**
     * Vider le cache analytics
     */
    public function clearCache(): void
    {
        $patterns = [
            'analytics_kpis_*',
            'analytics_cashflow_*',
            'analytics_agent_scoring',
            'analytics_trends_*',
        ];

        // Simplification : on vide les clés connues
        foreach (['today', 'week', 'month', 'quarter', 'year'] as $period) {
            Cache::forget(TenantContext::cacheKey("analytics_kpis_{$period}"));
        }

        foreach ([7, 14, 30, 60, 90] as $days) {
            Cache::forget(TenantContext::cacheKey("analytics_cashflow_prediction_{$days}"));
        }

        foreach ([3, 6, 12] as $months) {
            Cache::forget(TenantContext::cacheKey("analytics_trends_{$months}"));
        }

        Cache::forget(TenantContext::cacheKey('analytics_agent_scoring'));
    }
}
