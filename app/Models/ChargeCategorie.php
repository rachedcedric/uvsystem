<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChargeCategorie extends Model
{
    protected $table = 'charge_categories';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'category_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Options prêtes pour un Filament Select (id => nom), catégories
     * actives seulement, dans l'ordre d'affichage configuré.
     */
    public static function getActiveOptions(): array
    {
        return self::active()->orderBy('sort_order')->pluck('name', 'id')->all();
    }
}
