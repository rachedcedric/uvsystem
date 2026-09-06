<?php

namespace App\Filament\Agent\Pages;

use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class AgentDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.agent.pages.agent-dashboard';

    public ?Agent $agent = null;

    public array $stats = [];

    public array $recentTransactions = [];

    public array $recentRemboursements = [];

    public function mount(): void
    {
        $user = Auth::user();

        // Get the agent linked to this user
        $this->agent = Agent::where('user_id', $user->id)->first();

        if (! $this->agent) {
            // Try to find agent by email
            $this->agent = Agent::where('email', $user->email)->first();
        }

        if ($this->agent) {
            $this->loadStats();
            $this->loadRecentActivity();
        }
    }

    protected function loadStats(): void
    {
        $solde = $this->agent->solde;

        $this->stats = [
            'total_uv' => $solde?->total_uv_distribues ?? 0,
            'total_remboursements' => $solde?->total_rembourse ?? 0,
            'dette_actuelle' => $solde?->solde_restant ?? 0,
            'plafond' => $this->agent->debt_limit,
            'credit_disponible' => $this->agent->debt_limit
                ? max(0, $this->agent->debt_limit - ($solde?->solde_restant ?? 0))
                : null,
            'ratio_dette' => $this->agent->debt_limit && $this->agent->debt_limit > 0
                ? round(($solde?->solde_restant ?? 0) / $this->agent->debt_limit * 100, 1)
                : 0,
        ];
    }

    protected function loadRecentActivity(): void
    {
        $this->recentTransactions = UvTransaction::where('agent_id', $this->agent->id)
            ->with('fournisseur')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'date' => $t->created_at->format('d/m/Y H:i'),
                'quantity' => $t->amount,
                'amount' => number_format($t->amount, 0, ',', ' ').' FCFA',
                'fournisseur' => $t->fournisseur?->name ?? '-',
            ])
            ->toArray();

        $this->recentRemboursements = Remboursement::where('agent_id', $this->agent->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'date' => $r->created_at->format('d/m/Y H:i'),
                'amount' => number_format($r->amount, 0, ',', ' ').' FCFA',
                'method' => $r->payment_method ?? 'Espèces',
                'reference' => $r->reference ?? '-',
            ])
            ->toArray();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }
}
