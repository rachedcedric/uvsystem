<?php

namespace App\Livewire\Mobile\Dettes;

use App\Filament\Pages\DettesPage;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Services\FournisseurDebtService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Dettes en cours : agents (reste dû à l'entreprise) et fournisseurs à crédit (reste dû par l'entreprise). */
#[Layout('components.layouts.mobile')]
#[Title('Dettes')]
class Index extends Component
{
    private const PAGE = 30;

    #[Url(as: 'v', except: 'agents')]
    public string $scope = 'agents';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_remboursements') || auth()->user()->can('view_remboursements_fournisseurs'), 403);

        if ($this->scope === 'agents' && ! auth()->user()->can('view_remboursements')) {
            $this->scope = 'fournisseurs';
        }
    }

    public function updatedScope(): void
    {
        $this->limit = self::PAGE;
        $this->search = '';
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    /** Envoie les rappels de dette du cycle en cours (mêmes règles que « Envoyer les rappels maintenant »). */
    public function sendReminders(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['master', 'admin']), 403);

        try {
            $result = app(\App\Services\NotificationService::class)->checkAndSendDebtReminders();
            $this->dispatch('m-toast', message: "{$result['sent']} rappel(s) envoyé(s), {$result['skipped_duplicate']} déjà notifié(s) pendant ce cycle.");
        } catch (\Throwable $e) {
            $this->dispatch('m-toast', message: 'Rappels impossibles : '.$e->getMessage(), type: 'error');
        }
    }

    public function render()
    {
        $user = auth()->user();
        $data = [
            'scopes' => array_filter([
                'agents' => $user->can('view_remboursements') ? 'Agents' : null,
                'fournisseurs' => $user->can('view_remboursements_fournisseurs') ? 'Fournisseurs' : null,
            ]),
        ];

        if ($this->scope === 'fournisseurs' && $user->can('view_remboursements_fournisseurs')) {
            $service = app(FournisseurDebtService::class);
            $term = trim($this->search);

            $rows = Fournisseur::query()->creditFournisseurs()->where('is_active', true)
                ->when($term !== '', fn (Builder $q) => $q->where('name', 'like', "%{$term}%"))
                ->orderBy('name')->get()
                ->map(fn (Fournisseur $f) => ['model' => $f, 'reste' => $service->detteRestante($f)])
                ->sortByDesc('reste')->values();

            return view('livewire.mobile.dettes.index', $data + [
                'mode' => 'fournisseurs',
                'summary' => $service->globalSummary(),
                'rows' => $rows->take($this->limit),
                'hasMore' => $rows->count() > $this->limit,
                'canPay' => $user->can('create_remboursements_fournisseurs'),
            ]);
        }

        $term = trim($this->search);
        $query = DettesPage::agentsEndettesQuery()
            ->when($term !== '', fn (Builder $q) => $q->where('name', 'like', "%{$term}%"))
            ->withSum(['transactions as debt_today' => fn (Builder $t) => $t->where('transaction_type', 'distribution')->where('status', '!=', 'cancelled')->whereBetween('transaction_date', [today(), now()])], 'amount')
            ->withSum(['remboursements as repaid_today' => fn (Builder $r) => $r->whereBetween('payment_date', [today(), now()])], 'amount')
            ->orderByDesc('current_debt');

        $rows = $query->limit($this->limit + 1)->get();

        return view('livewire.mobile.dettes.index', $data + [
            'mode' => 'agents',
            'summary' => DettesPage::getSummary(),
            'rows' => $rows->take($this->limit),
            'hasMore' => $rows->count() > $this->limit,
            'canPay' => $user->can('create_remboursements'),
            'canRemind' => $user->hasAnyRole(['master', 'admin']),
        ]);
    }
}
