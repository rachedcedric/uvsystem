<?php

namespace App\Filament\Resources\ApprovisionnementResource\Pages;

use App\Filament\Resources\ApprovisionnementResource;
use App\Services\ApprovisionnementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

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
        return app(ApprovisionnementService::class)->create($data);
    }

    /**
     * Message de succès personnalisé
     */
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Approvisionnement créé avec succès';
    }
}
