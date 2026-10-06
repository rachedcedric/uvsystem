<?php

namespace App\Livewire\Mobile\Commissions;

use App\Models\Commission;
use App\Models\PalierCommission;
use App\Services\BusinessPeriodResolver;
use App\Services\CommissionService;
use App\Services\Mobile\MonthOverview;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Commissions')]
class Index extends Component
{
    private const PAGE = 30;

    #[Url(as: 'p', except: 'month')]
    public string $period = 'month';

    public int $limit = self::PAGE;

    // Recalcul
    public string $recalcMode = 'date';

    public string $recalcDate = '';

    public string $recalcStart = '';

    public string $recalcEnd = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Commission::class);

        $this->recalcDate = today()->toDateString();
        $this->recalcEnd = today()->toDateString();
        $this->recalcStart = today()->startOfMonth()->toDateString();
    }

    public function updatedPeriod(): void
    {
        $this->limit = self::PAGE;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    private function filtered(): Builder
    {
        return Commission::query()->globales()
            ->when($this->period === 'week', fn (Builder $q) => $q->whereBetween('date_commission', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when($this->period === 'month', fn (Builder $q) => $q->thisMonth());
    }

    public function calculate(): void
    {
        abort_unless(auth()->user()->can('create', Commission::class), 403);

        try {
            $stats = app(CommissionService::class)->calculerCommissionsPourDate(Carbon::today());
        } catch (\Throwable $e) {
            $this->dispatch('m-toast', message: 'Erreur de calcul : '.$e->getMessage(), type: 'error');

            return;
        }

        if (! empty($stats['erreurs'])) {
            $this->dispatch('m-toast', message: implode(', ', $stats['erreurs']), type: 'error');
        } elseif ($stats['deja_calcule']) {
            $this->dispatch('m-toast', message: 'La commission du '.$stats['date_commission'].' est déjà enregistrée.');
        } else {
            $this->dispatch('m-toast', message: sprintf('Volume %s F → commission %s F', number_format($stats['volume_total'], 0, ',', ' '), number_format($stats['commission_globale'], 0, ',', ' ')));
        }
    }

    public function recalculate(): void
    {
        abort_unless(auth()->user()->can('create', Commission::class), 403);

        $this->validate([
            'recalcMode' => ['required', 'in:date,periode,incomplete'],
            'recalcDate' => ['required_if:recalcMode,date', 'nullable', 'date'],
            'recalcStart' => ['required_if:recalcMode,periode', 'nullable', 'date'],
            'recalcEnd' => ['required_if:recalcMode,periode', 'nullable', 'date', 'after_or_equal:recalcStart'],
        ], [
            'recalcDate.required_if' => 'Choisissez une date.',
            'recalcStart.required_if' => 'Choisissez le début de la période.',
            'recalcEnd.required_if' => 'Choisissez la fin de la période.',
            'recalcEnd.after_or_equal' => 'La fin doit suivre le début.',
        ]);

        $service = app(CommissionService::class);

        try {
            $results = match ($this->recalcMode) {
                'date' => [Carbon::parse($this->recalcDate)->toDateString() => $service->verifierEtCorrigerCommission(Carbon::parse($this->recalcDate), 'manual')],
                'periode' => $service->recalculerCommissionsPourPeriode(Carbon::parse($this->recalcStart), Carbon::parse($this->recalcEnd), 'manual'),
                default => $service->rattraperCommissionsManquantes(),
            };
        } catch (\Throwable $e) {
            $this->dispatch('m-toast', message: 'Erreur de recalcul : '.$e->getMessage(), type: 'error');

            return;
        }

        $results = collect($results);
        $this->dispatch('m-toast', message: sprintf(
            '%d journée(s) vérifiée(s), %d recalculée(s)%s',
            $results->count(),
            $results->whereIn('statut', ['created', 'updated'])->count(),
            $results->where('statut', 'failed')->count() ? ', '.$results->where('statut', 'failed')->count().' échec(s)' : ''
        ));
    }

    public function render()
    {
        $resolver = app(BusinessPeriodResolver::class);
        $service = app(CommissionService::class);
        $today = $resolver->currentBusinessDate();
        $overview = app(MonthOverview::class);

        $rows = $this->filtered()->orderByDesc('date_commission')->orderByDesc('id')->limit($this->limit + 1)->get();
        $summary = $this->filtered()->selectRaw('COUNT(*) as n, COALESCE(SUM(commission_globale), 0) as total, COALESCE(SUM(volume_total_jour), 0) as volume')->first();
        $user = auth()->user();

        return view('livewire.mobile.commissions.index', [
            'forecast' => $service->commissionPourJourneeMetier($today),
            'volume' => $service->volumePourJourneeMetier($today),
            'week' => (float) Commission::globales()->forPeriod($today->copy()->startOfWeek(), $today)->sum('commission_globale'),
            'month' => (float) Commission::globales()->forPeriod($today->copy()->startOfMonth(), $today)->sum('commission_globale'),
            'cumul' => (float) Commission::globales()->sum('commission_globale'),
            'nextCutoff' => $resolver->nextCutoff(),
            'yesterday' => $overview->commissionYesterday(),
            'dailyCommissions' => $overview->dailyCommissions(),
            'dailySales' => $overview->dailySales(),
            'items' => $rows->take($this->limit),
            'hasMore' => $rows->count() > $this->limit,
            'count' => (int) $summary->n,
            'total' => (float) $summary->total,
            'paliers' => PalierCommission::where('is_active', true)->orderBy('seuil_min')->get(),
            'canCompute' => $user->can('create', Commission::class),
            'canExport' => $user->can('export_commissions'),
        ]);
    }
}
