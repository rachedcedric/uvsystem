<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Surcharge de contenu du CMS (voir App\Cms\Content). Volontairement NON tenantisé : contenu de plateforme, écrit
 * uniquement depuis /platform par le super admin.
 */
class CmsEntry extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];
}
