<?php

namespace App\Filament\Platform\Resources\TenantResource\Pages;

use App\Filament\Platform\Resources\TenantResource;
use App\Models\Tenant;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // status/suspended_at ne sont pas mass-assignables : on passe par suspend()/activate().
        $status = $data['status'] ?? $record->status;
        unset($data['status']);

        $record->update($data);

        if ($status !== $record->status) {
            $status === Tenant::STATUS_SUSPENDED ? $record->suspend() : $record->activate();
        }

        return $record;
    }
}
