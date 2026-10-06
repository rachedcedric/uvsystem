<?php

namespace App\Services\Mobile;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'une période pour l'écran mobile. Réunit « Statistiques » et
 * « Rapports & Statistiques » de l'interface complète.
 *
 * Chiffre d'affaires = ventes (distributions) non annulées. Les bornes de la
 * période sont inclusives jusqu'à la fin de la dernière journée.
 */
class StatisticsService
{
    public readonly Carbon $from;

    public readonly Carbon $to;

    public function __construct(Carbon $from, Carbon $to, public readonly ?int $agentId = null)
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->endOfDay();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $now = now();

        return match ($period) {
            'day' => [$now->copy(), $now->copy()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()],
            'year' => [$now->copy()->startOfYear(), $now->copy()],
            'custom' => [
                $from ? Carbon::parse($from) : $now->copy()->startOfMonth(),
                $to ? Carbon::parse($to) : $now->copy(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()],
        };
    }

    private function sales()
    {
        return UvTransaction::query()
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$this->from, $this->to])
            ->when($this->agentId, fn ($q) => $q->where('agent_id', $this->agentId));
    }

    private function repayments(?Carbon $from = null, ?Carbon $to = null)
    {
        return Remboursement::query()
            ->whereBetween('payment_date', [$from ?? $this->from, $to ?? $this->to])
            ->when($this->agentId, fn ($q) => $q->where('agent_id', $this->agentId));
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    /** @return array<string,float|int> */
    public function overview(): array
    {
        $ca = (float) $this->sales()->sum('amount');
        $count = $this->sales()->count();
        $rembourse = (float) $this->repayments()->sum('amount');
        $repayCount = $this->repayments()->count();
        $commissions = (float) Commission::globales()->whereBetween('date_commission', [$this->from->toDateString(), $this->to->toDateString()])->sum('commission_globale');
        $appro = (float) Approvisionnement::whereBetween('date_approvisionnement', [$this->from, $this->to])->sum('amount');

        return [
            'ca' => $ca,
            'ventes' => $count,
            'ticket_moyen' => $count > 0 ? $ca / $count : 0,
            'rembourse' => $rembourse,
            'paiements' => $repayCount,
            'recouvrement' => $ca > 0 ? round($rembourse / $ca * 100, 1) : 0,
            'commissions' => $commissions,
            'approvisionnements' => $appro,
            'agents_actifs' => (int) $this->sales()->distinct()->count('agent_id'),
        ];
    }

    /** Évolution du CA et des remboursements par rapport à la période précédente de même durée. @return array{ca: float, rembourse: float} */
    public function trends(): array
    {
        $days = $this->days();
        $prevFrom = $this->from->copy()->subDays($days);
        $prevTo = $this->from->copy()->subSecond();

        $prevCa = (float) UvTransaction::query()->where('transaction_type', 'distribution')->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$prevFrom, $prevTo])->when($this->agentId, fn ($q) => $q->where('agent_id', $this->agentId))->sum('amount');
        $prevRemb = (float) $this->repayments($prevFrom, $prevTo)->sum('amount');
        $overview = $this->overview();

        $pct = fn (float $now, float $before): float => $before > 0 ? round(($now - $before) / $before * 100, 1) : 0.0;

        return ['ca' => $pct($overview['ca'], $prevCa), 'rembourse' => $pct($overview['rembourse'], $prevRemb)];
    }

    /**
     * CA et remboursements par jour (par mois si la période dépasse 62 jours).
     *
     * @return list<array{label:string, ca:float, rembourse:float}>
     */
    public function series(): array
    {
        $monthly = $this->days() > 62;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $sqlFormat = $monthly ? "strftime('%Y-%m', %s)" : 'DATE(%s)';
        $driver = DB::connection()->getDriverName();
        $expr = fn (string $col) => $driver === 'sqlite'
            ? ($monthly ? "strftime('%Y-%m', {$col})" : "date({$col})")
            : ($monthly ? "DATE_FORMAT({$col}, '%Y-%m')" : "DATE({$col})");

        $ca = $this->sales()->selectRaw($expr('transaction_date').' as k, SUM(amount) as total')->groupBy('k')->pluck('total', 'k');
        $re = $this->repayments()->selectRaw($expr('payment_date').' as k, SUM(amount) as total')->groupBy('k')->pluck('total', 'k');

        $points = [];
        $cursor = $monthly ? $this->from->copy()->startOfMonth() : $this->from->copy();
        while ($cursor <= $this->to) {
            $key = $cursor->format($format);
            $points[] = [
                'label' => $monthly ? mb_substr($cursor->translatedFormat('M'), 0, 3) : $cursor->format('d'),
                'ca' => (float) ($ca[$key] ?? 0),
                'rembourse' => (float) ($re[$key] ?? 0),
            ];
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return $points;
    }

    /** @return Collection<int, array{id:int,name:string,ca:float,ventes:int,dette:float}> */
    public function topAgents(int $limit = 10): Collection
    {
        $rows = $this->sales()->select('agent_id', DB::raw('SUM(amount) as ca'), DB::raw('COUNT(*) as n'))
            ->groupBy('agent_id')->orderByDesc('ca')->limit($limit)->get();
        $agents = Agent::withTrashed()->whereIn('id', $rows->pluck('agent_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->agent_id,
            'name' => $agents[$r->agent_id]->name ?? 'Agent #'.$r->agent_id,
            'ca' => (float) $r->ca,
            'ventes' => (int) $r->n,
            'dette' => (float) ($agents[$r->agent_id]->current_debt ?? 0),
        ]);
    }

    /** @return Collection<int, array{label:string,total:float,count:int}> */
    public function paymentModes(): Collection
    {
        $names = PaymentMethod::pluck('name', 'code');

        return $this->repayments()->select('payment_method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as n'))
            ->groupBy('payment_method')->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => $names[$r->payment_method] ?? (string) $r->payment_method, 'total' => (float) $r->total, 'count' => (int) $r->n]);
    }

    /** Situation de la dette (état actuel, indépendant de la période). @return array<string,float|int> */
    public function debt(): array
    {
        return [
            'total' => (float) Agent::active()->sum('current_debt'),
            'avec_dette' => Agent::active()->withDebt()->count(),
            'critiques' => Agent::active()->where('debt_limit', '>', 0)->whereRaw('current_debt * 100 >= debt_limit * 90')->count(),
            'surveiller' => Agent::active()->where('debt_limit', '>', 0)->whereRaw('current_debt * 100 >= debt_limit * 70 AND current_debt * 100 < debt_limit * 90')->count(),
            'sans_dette' => Agent::active()->where('current_debt', '<=', 0)->count(),
        ];
    }

    /** @return Collection<int, array{id:int,name:string,debt:float,limit:float,pct:float}> */
    public function worstDebtors(int $limit = 5): Collection
    {
        return Agent::active()->withDebt()->orderByDesc('current_debt')->limit($limit)->get()->map(fn (Agent $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'debt' => (float) $a->current_debt,
            'limit' => (float) $a->debt_limit,
            'pct' => $a->debt_limit > 0 ? round($a->current_debt / $a->debt_limit * 100, 1) : 100.0,
        ]);
    }
}
