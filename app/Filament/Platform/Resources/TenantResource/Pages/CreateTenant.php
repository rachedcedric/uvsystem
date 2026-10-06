<?php

namespace App\Filament\Platform\Resources\TenantResource\Pages;

use App\Filament\Platform\Resources\TenantResource;
use App\Tenancy\TenantProvisioner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** Passe par le provisionneur : le tenant naît avec ses données de référence et son admin. */
    protected function handleRecordCreation(array $data): Model
    {
        $admin = [
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
        ];
        unset($data['admin_name'], $data['admin_email'], $data['admin_password']);

        return app(TenantProvisioner::class)->provision($data, $admin);
    }
}
