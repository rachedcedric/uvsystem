<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Journal d'audit isolé par tenant. tenant_id reste nullable : les actions de plateforme
 * (super admin) ne appartiennent à aucun tenant et sont invisibles pour les tenants.
 */
class Activity extends SpatieActivity
{
    use BelongsToTenant;

    protected static function tenantIdRequired(): bool
    {
        return false;
    }
}
