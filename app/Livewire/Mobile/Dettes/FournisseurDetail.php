<?php

namespace App\Livewire\Mobile\Dettes;

use App\Livewire\Concerns\CancelsSupplierPayments;
use App\Models\Fournisseur;
use App\Services\FournisseurDebtService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fiche d'un fournisseur sur mobile : mêmes chiffres que la fiche Web (FournisseurDebtService::situation()) et
 * même historique chronologique avec solde courant, compensations et annulations.
 */
#[Layout('components.layouts.mobile')]
#[Title('Fiche fournisseur')]
class FournisseurDetail extends Component
{
    use CancelsSupplierPayments;

    private const PAGE = 30;

    public Fournisseur $fournisseur;

    public int $limit = self::PAGE;

    public function mount(Fournisseur $fournisseur): void
    {
        abort_unless(auth()->user()->can('view_remboursements_fournisseurs'), 403);

        $this->fournisseur = $fournisseur;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    public function render()
    {
        $service = app(FournisseurDebtService::class);
        $history = $service->history($this->fournisseur)->reverse()->values();

        return view('livewire.mobile.dettes.fournisseur-detail', [
            'situation' => $service->situation($this->fournisseur),
            'groups' => $history->take($this->limit)->groupBy(fn ($row) => $row['date']->toDateString()),
            'hasMore' => $history->count() > $this->limit,
            'canPay' => $this->fournisseur->isCreditFournisseur() && $this->fournisseur->is_active && auth()->user()->can('create_remboursements_fournisseurs'),
        ]);
    }
}
