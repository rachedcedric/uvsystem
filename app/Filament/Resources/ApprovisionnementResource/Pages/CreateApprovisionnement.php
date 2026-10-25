<?php

namespace App\Filament\Resources\ApprovisionnementResource\Pages;

use App\Filament\Resources\ApprovisionnementResource;
use App\Models\Fournisseur;
use App\Services\ApprovisionnementService;
use App\Services\RetourAgentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateApprovisionnement extends CreateRecord
{
    protected static string $resource = ApprovisionnementResource::class;

    /**
     * Mutate les données avant la création pour ajouter automatiquement le user_id
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ajouter automatiquement l'utilisateur connecté
        $data['user_id'] = Auth::id();

        return $data;
    }

    /**
     * Passe par ApprovisionnementService plutôt que Approvisionnement::create()
     * directement, pour que la capture de solde_uv_avant/solde_uv_apres soit
     * protégée par le même verrou que les ventes (UvDistributionService).
     */
    protected function handleRecordCreation(array $data): Model
    {
        $fournisseur = Fournisseur::find($data['fournisseur_id'] ?? null);

        // « Retour Agent » : approvisionnement à crédit sur le compte fournisseur de l'agent choisi (RetourAgentService).
        if ($fournisseur?->isRetourAgent()) {
            try {
                $this->isRetour = true;

                return app(RetourAgentService::class)->enregistrer(
                    (int) ($data['agent_id'] ?? 0),
                    (float) $data['amount'],
                    $data['date_approvisionnement'] ?? null,
                    $data['notes'] ?? null,
                );
            } catch (ValidationException $e) {
                Notification::make()->danger()->title('Retour impossible')->body(collect($e->errors())->flatten()->implode(' '))->send();
                $this->halt();
            }
        }

        unset($data['agent_id']);

        return app(ApprovisionnementService::class)->create($data);
    }

    public bool $isRetour = false;

    protected function getRedirectUrl(): string
    {
        return $this->isRetour ? static::getResource()::getUrl('index') : parent::getRedirectUrl();
    }

    /**
     * Message de succès personnalisé
     */
    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->isRetour ? 'Retour d\'UV de l\'agent enregistré : stock UV recrédité, montant dû à l\'agent ajouté aux dettes fournisseurs' : 'Approvisionnement créé avec succès';
    }
}
