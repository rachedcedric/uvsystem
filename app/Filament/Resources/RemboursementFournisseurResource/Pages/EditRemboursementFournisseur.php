<?php

namespace App\Filament\Resources\RemboursementFournisseurResource\Pages;

use App\Filament\Resources\RemboursementFournisseurResource;
use App\Models\Fournisseur;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditRemboursementFournisseur extends EditRecord
{
    protected static string $resource = RemboursementFournisseurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Enveloppe la mise à jour dans une transaction avec verrou sur le
     * fournisseur concerné, pour rester cohérent même en cas d'édition
     * concurrente (même garantie que EditRemboursement côté agent).
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            if ($record->fournisseur_id) {
                Fournisseur::whereKey($record->fournisseur_id)->lockForUpdate()->first();
            }

            return parent::handleRecordUpdate($record, $data);
        });
    }
}
