<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'code',
        'name',
        'icon',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Remboursements utilisant ce mode de paiement
     */
    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class, 'payment_method', 'code');
    }

    /**
     * Récupère tous les modes de paiement actifs pour les selects
     */
    public static function getActiveOptions(): array
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn ($method) => [
                $method->code => ($method->icon ? $method->icon.' ' : '').$method->name,
            ])
            ->toArray();
    }

    /**
     * Récupère le libellé complet (icône + nom)
     */
    public function getFullNameAttribute(): string
    {
        return ($this->icon ? $this->icon.' ' : '').$this->name;
    }

    /**
     * Scope pour les modes actifs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
