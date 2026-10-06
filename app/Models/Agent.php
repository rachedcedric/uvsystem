<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Agent extends Model
{
    use BelongsToTenant;
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'zone',
        'type',
        'debt_limit',
        'current_debt',
        'commission_rate',
        'status',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'debt_limit' => 'decimal:2',
        'current_debt' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'email_valid' => 'boolean',
        'created_at' => 'datetime',
    ];

    // ==================== RELATIONS ====================

    public function transactions(): HasMany
    {
        return $this->hasMany(UvTransaction::class);
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class);
    }

    public function solde(): HasOne
    {
        return $this->hasOne(AgentSolde::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sous-distributeurs de cet agent
     */
    public function sousDistributeurs(): HasMany
    {
        return $this->hasMany(SousDistributeur::class);
    }

    /**
     * Transactions vers les sous-distributeurs
     */
    public function sousDistributeurTransactions(): HasMany
    {
        return $this->hasMany(SousDistributeurTransaction::class);
    }

    /**
     * Remboursements des sous-distributeurs
     */
    public function sousDistributeurRemboursements(): HasMany
    {
        return $this->hasMany(SousDistributeurRemboursement::class);
    }

    // ==================== TWILIO ====================

    public function routeNotificationForTwilio(): ?string
    {
        if (! $this->phone) {
            return null;
        }

        $phone = preg_replace('/[\s\-\.]/', '', $this->phone);

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        if (str_starts_with($phone, '00')) {
            return '+'.substr($phone, 2);
        }

        return config('services.sms.default_country_code', '+225').$phone;
    }

    // ==================== ACCESSORS ====================

    public function getNomCompletAttribute(): string
    {
        return $this->name;
    }

    public function getDebtRatioAttribute(): float
    {
        if ($this->debt_limit <= 0) {
            return 0;
        }

        return round(($this->current_debt / $this->debt_limit) * 100, 2);
    }

    public function getAvailableCreditAttribute(): float
    {
        return max(0, $this->debt_limit - $this->current_debt);
    }

    public function getSousDistributeursCountAttribute(): int
    {
        return $this->sousDistributeurs()->count();
    }

    public function getSousDistributeursActifsCountAttribute(): int
    {
        return $this->sousDistributeurs()->where('statut', 'actif')->count();
    }

    public function getTotalDetteSousDistributeursAttribute(): float
    {
        return $this->sousDistributeurs()
            ->with('solde')
            ->get()
            ->sum(fn ($sd) => $sd->solde?->solde_restant ?? 0);
    }

    // ==================== SCOPES ====================

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /** Un agent est considéré inactif quand il n'a effectué aucun achat depuis ce nombre de jours. */
    public const INACTIVITY_DAYS = 30;

    /**
     * Agents sans achat (vente d'UV non annulée) depuis $days jours.
     *
     * Un agent créé il y a moins de $days jours qui n'a encore rien acheté n'est pas inactif : il vient
     * d'arriver. En revanche, un agent récemment importé qui a un historique d'achats anciens l'est.
     */
    public function scopeInactiveForDays(Builder $query, int $days = self::INACTIVITY_DAYS): void
    {
        $since = now()->subDays($days);
        $purchases = fn (Builder $q) => $q->where('transaction_type', 'distribution')->where('status', '!=', 'cancelled');

        $query
            ->whereDoesntHave('transactions', fn (Builder $q) => $purchases($q)->where('transaction_date', '>=', $since))
            ->where(fn (Builder $q) => $q
                ->where('agents.created_at', '<=', $since)
                ->orWhereHas('transactions', $purchases));
    }

    public function scopeWithDebt(Builder $query): void
    {
        $query->where('current_debt', '>', 0);
    }

    public function scopePremium(Builder $query): void
    {
        $query->where('type', 'premium');
    }

    /**
     * Agents avec dette critique (>= 90% du plafond)
     */
    public function scopeCriticalDebt(Builder $query, float $threshold = 90.0): void
    {
        $query->whereRaw('debt_limit > 0 AND (current_debt / debt_limit * 100) >= ?', [$threshold]);
    }

    /**
     * Agents avec dette en avertissement (70-89% du plafond)
     */
    public function scopeWarningDebt(Builder $query, float $minThreshold = 70.0, float $maxThreshold = 90.0): void
    {
        $query->whereRaw('debt_limit > 0 AND (current_debt / debt_limit * 100) >= ? AND (current_debt / debt_limit * 100) < ?', [$minThreshold, $maxThreshold]);
    }

    /**
     * Agents sans dette
     */
    public function scopeCleanDebt(Builder $query): void
    {
        $query->where('current_debt', '<=', 0);
    }

    public function scopeWithSousDistributeurs(Builder $query): void
    {
        $query->has('sousDistributeurs');
    }

    // ==================== METHODS ====================

    /**
     * Totaux réels agrégés depuis les mouvements de cet agent (ventes et
     * retours actifs — jamais annulés —, remboursements). Source UNIQUE
     * utilisée par recalculateDebt(), debtAsOf() et AgentSolde::recalculate() :
     * toute autre formule de dette/solde d'agent ailleurs dans le code doit
     * passer par ici plutôt que resommer les transactions elle-même.
     *
     * @return array{distribue: float, retourne: float, rembourse: float}
     */
    public function soldeTotals(?\Carbon\Carbon $asOf = null): array
    {
        $distributedQuery = $this->transactions()
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled');

        $returnedQuery = $this->transactions()
            ->where('transaction_type', 'retour')
            ->where('status', '!=', 'cancelled');

        $repaidQuery = $this->remboursements();

        if ($asOf) {
            $distributedQuery->where('transaction_date', '<=', $asOf);
            $returnedQuery->where('transaction_date', '<=', $asOf);
            $repaidQuery->where('payment_date', '<=', $asOf);
        }

        return [
            'distribue' => (float) $distributedQuery->sum('amount'),
            'retourne' => (float) $returnedQuery->sum('amount'),
            'rembourse' => (float) $repaidQuery->sum('amount'),
        ];
    }

    public function recalculateDebt(): void
    {
        $totaux = $this->soldeTotals();

        $this->current_debt = $totaux['distribue'] - $totaux['retourne'] - $totaux['rembourse'];
        $this->saveQuietly();
    }

    /**
     * Dette de cet agent telle qu'elle se présentait à un instant T donné
     * (ou maintenant si omis), avec la même formule exacte que
     * recalculateDebt() : distribué - retourné - remboursé. Contrairement à
     * current_debt (mis en cache), toujours recalculée en direct ; non
     * plafonnée à 0 (reflète fidèlement l'historique, y compris un éventuel
     * trop-perçu).
     */
    public function debtAsOf(?\Carbon\Carbon $asOf = null): float
    {
        $totaux = $this->soldeTotals($asOf);

        return $totaux['distribue'] - $totaux['retourne'] - $totaux['rembourse'];
    }

    public function canAcceptDebt(float $amount): bool
    {
        if ($this->debt_limit <= 0) {
            return true;
        }

        return ($this->current_debt + $amount) <= $this->debt_limit;
    }

    /**
     * Reconstitue le montant total des dettes en cours (agents actifs, restant
     * dû > 0) à un instant T donné, à partir des mouvements réels (distributions
     * - retours - remboursements), en l'absence de table d'historique dédiée.
     * Sans argument, retourne le total actuel (équivalent à la somme de
     * current_debt sur les agents actifs endettés).
     */
    public static function totalDetteActiveAsOf(?\Carbon\Carbon $asOf = null): float
    {
        $distributedQuery = TenantContext::table('uv_transactions')
            ->select('agent_id', \Illuminate\Support\Facades\DB::raw('SUM(amount) as total'))
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at');

        $returnedQuery = TenantContext::table('uv_transactions')
            ->select('agent_id', \Illuminate\Support\Facades\DB::raw('SUM(amount) as total'))
            ->where('transaction_type', 'retour')
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at');

        $repaidQuery = TenantContext::table('remboursements')
            ->select('agent_id', \Illuminate\Support\Facades\DB::raw('SUM(amount) as total'))
            ->whereNull('deleted_at');

        if ($asOf) {
            $distributedQuery->where('transaction_date', '<=', $asOf);
            $returnedQuery->where('transaction_date', '<=', $asOf);
            $repaidQuery->where('payment_date', '<=', $asOf);
        }

        $distributed = $distributedQuery->groupBy('agent_id')->pluck('total', 'agent_id');
        $returned = $returnedQuery->groupBy('agent_id')->pluck('total', 'agent_id');
        $repaid = $repaidQuery->groupBy('agent_id')->pluck('total', 'agent_id');

        $total = 0.0;

        foreach (self::where('status', 'active')->pluck('id') as $agentId) {
            $net = (float) ($distributed[$agentId] ?? 0)
                - (float) ($returned[$agentId] ?? 0)
                - (float) ($repaid[$agentId] ?? 0);

            if ($net > 0) {
                $total += $net;
            }
        }

        return $total;
    }

    public function isDebtCritical(): bool
    {
        return $this->debt_ratio >= 90;
    }

    public function isDebtWarning(): bool
    {
        return $this->debt_ratio >= 70 && $this->debt_ratio < 90;
    }
}
