<?php

namespace App\Filament\Resources\RemboursementFournisseurResource\Pages;

use App\Filament\Resources\RemboursementFournisseurResource;
use App\Models\Fournisseur;
use App\Services\FournisseurDebtService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRemboursementFournisseur extends CreateRecord
{
    protected static string $resource = RemboursementFournisseurResource::class;

    protected function fillForm(): void
    {
        parent::fillForm();

        $fournisseurId = $this->resolvePreselectedFournisseurId();

        if ($fournisseurId) {
            $this->form->fill([
                ...(array) $this->form->getRawState(),
                'fournisseur_id' => $fournisseurId,
            ]);
        }
    }

    protected function resolvePreselectedFournisseurId(): ?int
    {
        $fournisseurId = request()->query('fournisseur_id');

        if (! $fournisseurId || ! is_numeric($fournisseurId)) {
            return null;
        }

        return Fournisseur::query()
            ->creditFournisseurs()
            ->where('is_active', true)
            ->where('id', (int) $fournisseurId)
            ->value('id');
    }

    /**
     * Tout remboursement fournisseur doit être créé via
     * FournisseurDebtService::process(), qui valide (montant > 0, montant
     * <= reste à payer réel) au moment même de la création plutôt que via
     * une règle de formulaire basée sur un instantané potentiellement
     * obsolète.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $fournisseur = Fournisseur::findOrFail($data['fournisseur_id']);

            return app(FournisseurDebtService::class)->process(
                $fournisseur,
                (float) $data['amount'],
                $data['payment_method'] ?? 'cash',
                $data['payment_date'] ?? null,
                $data['notes'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (Halt $e) {
            throw $e;
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title('Validation échouée')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->persistent()
                ->send();

            $this->halt();
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title('Remboursement impossible')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Remboursement fournisseur enregistré avec succès.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToDettesFournisseurs')
                ->label('← Retour aux Dettes Fournisseurs')
                ->color('gray')
                ->url('/master/dettes-fournisseurs'),
        ];
    }
}
