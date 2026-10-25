<?php

namespace App\Filament\Resources\FournisseurResource\Pages;

use App\Filament\Resources\FournisseurResource;
use App\Filament\Resources\RemboursementFournisseurResource;
use App\Models\Fournisseur;
use App\Services\FournisseurDebtService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Fiche d'un fournisseur : totaux, dette, découvert (toujours signé « − »), solde net, dernière opération et
 * historique chronologique avec solde courant. Tout vient de FournisseurDebtService (même source que le mobile).
 */
class ViewFournisseur extends ViewRecord
{
    protected static string $resource = FournisseurResource::class;

    protected static string $view = 'filament.resources.fournisseur.view';

    public int $limit = 50;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    /** Historique, plus récent en premier. */
    public function getHistory(): Collection
    {
        return app(FournisseurDebtService::class)->history($this->record)->reverse()->values();
    }

    public function loadMore(): void
    {
        $this->limit += 50;
    }

    protected function getHeaderActions(): array
    {
        /** @var Fournisseur $fournisseur */
        $fournisseur = $this->record;

        return [
            Actions\Action::make('payer')
                ->label('Payer / verser une avance')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->url(RemboursementFournisseurResource::getUrl('create', ['fournisseur_id' => $fournisseur->id]))
                ->visible($fournisseur->isCreditFournisseur() && $fournisseur->is_active && (Auth::user()?->can('create_remboursements_fournisseurs') ?? false)),
            Actions\EditAction::make(),
        ];
    }
}
