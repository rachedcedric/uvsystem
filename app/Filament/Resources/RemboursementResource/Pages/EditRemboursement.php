<?php

namespace App\Filament\Resources\RemboursementResource\Pages;

use App\Filament\Resources\RemboursementResource;
use App\Models\Agent;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditRemboursement extends EditRecord
{
    protected static string $resource = RemboursementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Enveloppe la mise à jour dans une transaction avec verrou sur l'agent
     * concerné : RemboursementObserver::updated() recalcule ensuite
     * AgentSolde/current_debt (Agent::soldeTotals()) à partir d'un état
     * garanti non concurrent, plutôt que d'une lecture non verrouillée.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            if ($record->agent_id) {
                Agent::whereKey($record->agent_id)->lockForUpdate()->first();
            }

            return parent::handleRecordUpdate($record, $data);
        });
    }
}
