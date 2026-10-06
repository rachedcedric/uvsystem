<?php

namespace App\Livewire\Mobile\Analytics;

use App\Services\AnalyticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Scoring des agents')]
class Scoring extends Component
{
    public const BANDS = [
        'all' => 'Tous',
        'excellent' => 'Excellent',
        'good' => 'Bon',
        'average' => 'Moyen',
        'poor' => 'Faible',
    ];

    #[Url(as: 'g', except: 'all')]
    public string $grade = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_statistics') || auth()->user()->can('view_agents'), 403);
        app(\App\Services\Subscriptions\SubscriptionEntitlements::class)->abortUnlessFeature('scoring_agents');
    }

    private static function band(string $grade): string
    {
        return match ($grade) {
            'A+', 'A' => 'excellent',
            'B+', 'B' => 'good',
            'C+', 'C' => 'average',
            default => 'poor',
        };
    }

    public function render()
    {
        $all = app(AnalyticsService::class)->getAgentScoring()->values();
        $ranked = $all->map(fn (array $s, int $i) => $s + ['rank' => $i + 1, 'band' => self::band($s['grade'])]);

        return view('livewire.mobile.analytics.scoring', [
            'rows' => $this->grade === 'all' ? $ranked : $ranked->where('band', $this->grade)->values(),
            'average' => $all->isEmpty() ? 0 : round($all->avg('score')),
            'counts' => $ranked->countBy('band'),
            'total' => $all->count(),
        ]);
    }
}
