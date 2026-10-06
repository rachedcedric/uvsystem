<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Commission extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /**
     * Commission calculée et à jour par rapport aux ventes réelles.
     */
    public const STATUT_CALCULATED = 'calculated';

    /**
     * Une vente touchant cette journée a été ajoutée/modifiée/supprimée
     * après la clôture : la commission doit être recalculée.
     */
    public const STATUT_RECALCULATION_REQUIRED = 'recalculation_required';

    protected $fillable = [
        'agent_id',
        'vente_uv_id',
        'commission_globale_id',
        'montant_vente',
        'montant_commission',
        'volume_total_jour',
        'commission_globale',
        'volume_agent',
        'part_agent',
        'pourcentage_contribution',
        'is_global',
        'nombre_transactions',
        'seuil_min',
        'seuil_max',
        'date_commission',
        'notes',
        'statut',
        'calculated_at',
    ];

    protected $casts = [
        'montant_vente' => 'decimal:2',
        'montant_commission' => 'decimal:2',
        'volume_total_jour' => 'decimal:2',
        'commission_globale' => 'decimal:2',
        'volume_agent' => 'decimal:2',
        'part_agent' => 'decimal:2',
        'pourcentage_contribution' => 'decimal:4',
        'seuil_min' => 'decimal:2',
        'seuil_max' => 'decimal:2',
        'is_global' => 'boolean',
        'nombre_transactions' => 'integer',
        'date_commission' => 'date',
        'calculated_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONS
    // ==========================================

    /**
     * Agent concerné par cette commission (NULL si commission globale)
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Vente UV associée (legacy)
     */
    public function venteUv(): BelongsTo
    {
        return $this->belongsTo(VenteUv::class);
    }

    /**
     * Commission globale parente (pour les parts agents)
     */
    public function commissionGlobale(): BelongsTo
    {
        return $this->belongsTo(Commission::class, 'commission_globale_id');
    }

    /**
     * Parts des agents (pour la commission globale)
     */
    public function partsAgents(): HasMany
    {
        return $this->hasMany(Commission::class, 'commission_globale_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    /**
     * Commissions globales uniquement
     */
    public function scopeGlobales($query)
    {
        return $query->where('is_global', true);
    }

    /**
     * Parts agents uniquement (exclut les lignes globales)
     */
    public function scopePartsOnly($query)
    {
        return $query->where('is_global', false);
    }

    /**
     * Commissions d'un agent spécifique
     */
    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId)->where('is_global', false);
    }

    /**
     * Commissions d'une période
     */
    public function scopeForPeriod($query, $dateDebut, $dateFin)
    {
        return $query->whereBetween('date_commission', [$dateDebut, $dateFin]);
    }

    /**
     * Commissions du jour
     */
    public function scopeToday($query)
    {
        return $query->whereDate('date_commission', today());
    }

    /**
     * Commissions du mois en cours
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('date_commission', now()->month)
            ->whereYear('date_commission', now()->year);
    }

    /**
     * Commissions globales nécessitant un recalcul (vente tardive détectée
     * après clôture, ou correction en attente).
     */
    public function scopeNeedsRecalculation($query)
    {
        return $query->where('is_global', true)
            ->where('statut', self::STATUT_RECALCULATION_REQUIRED);
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    /**
     * Label du palier de commission
     */
    public function getPalierLabelAttribute(): string
    {
        if ($this->seuil_min && $this->seuil_max) {
            return number_format($this->seuil_min, 0, ',', ' ').' - '.
                   number_format($this->seuil_max, 0, ',', ' ').' FCFA';
        }

        return 'N/A';
    }

    /**
     * Montant à afficher (part_agent pour les agents, commission_globale pour le global)
     */
    public function getMontantAffichageAttribute(): float
    {
        return $this->is_global ? (float) $this->commission_globale : (float) $this->part_agent;
    }

    /**
     * Pourcentage formaté
     */
    public function getPourcentageFormateAttribute(): string
    {
        return number_format($this->pourcentage_contribution, 2).'%';
    }

    // ==========================================
    // MÉTHODES STATIQUES
    // ==========================================

    /**
     * Vérifier si une commission globale existe pour une date
     */
    public static function existeCommissionGlobalePour(\Carbon\Carbon $date): bool
    {
        return self::where('is_global', true)
            ->whereDate('date_commission', $date)
            ->exists();
    }

    /**
     * Récupérer la commission globale d'une date
     */
    public static function getCommissionGlobalePour(\Carbon\Carbon $date): ?self
    {
        return self::where('is_global', true)
            ->whereDate('date_commission', $date)
            ->first();
    }
}
