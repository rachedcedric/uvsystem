<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Fournisseur extends Model
{
    use BelongsToTenant;
    use HasFactory, LogsActivity, SoftDeletes;

    public const TYPE_CREDIT = 'AGENT_PREMIUM';

    public const TYPE_BANQUE = 'BANQUE';

    /** Entrée système « Retour Agent » : un agent qui rend des UV au Master (rebalancing). Une seule par entreprise. */
    public const TYPE_AGENT = 'AGENT';

    public const RETOUR_AGENT_NAME = 'Retour Agent';

    public const TYPE_LABELS = [
        self::TYPE_BANQUE => 'Banque',
        self::TYPE_CREDIT => 'Agent Premium',
        self::TYPE_AGENT => 'Agent',
    ];

    /** Types qu'un utilisateur peut choisir à la création/modification (« Agent » est réservé à l'entrée système). */
    public const SELECTABLE_TYPES = [
        self::TYPE_CREDIT => 'Agent Premium',
        self::TYPE_BANQUE => 'Banque',
    ];

    protected $fillable = [
        'name',
        'type',
        'agent_id',
        'contact',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    // Relations
    public function approvisionnements(): HasMany
    {
        return $this->hasMany(Approvisionnement::class);
    }

    public function remboursementsFournisseurs(): HasMany
    {
        return $this->hasMany(RemboursementFournisseur::class);
    }

    /**
     * Fournisseurs qui approvisionnent à crédit (à rembourser), à la
     * différence d'un fournisseur "Banque" payé comptant. Base unique
     * utilisée par FournisseurDebtService et DettesFournisseursPage.
     */
    public function scopeCreditFournisseurs($query)
    {
        // Agent Premium, et compte « Retour Agent » d'un agent (UV rendus = approvisionnement à crédit à payer à l'agent).
        return $query->where(fn ($q) => $q->whereRaw('UPPER(type) = ?', [self::TYPE_CREDIT])
            ->orWhere(fn ($a) => $a->whereRaw('UPPER(type) = ?', [self::TYPE_AGENT])->whereNotNull('agent_id')));
    }

    public function isCreditFournisseur(): bool
    {
        return strtoupper((string) $this->type) === self::TYPE_CREDIT || $this->isAgentFournisseur();
    }

    /** Entrée de sélection « Retour Agent » (sans agent) : choisie dans le formulaire, jamais approvisionnée elle-même. */
    public function isRetourAgent(): bool
    {
        return strtoupper((string) $this->type) === self::TYPE_AGENT && $this->agent_id === null;
    }

    /** Compte fournisseur d'un agent qui rend des UV (créé automatiquement, un par agent). */
    public function isAgentFournisseur(): bool
    {
        return strtoupper((string) $this->type) === self::TYPE_AGENT && $this->agent_id !== null;
    }

    /** Entrée système (sélecteur ou compte d'agent) : gérée par l'application, ni renommée ni supprimée à la main. */
    public function isSystemAgentEntry(): bool
    {
        return strtoupper((string) $this->type) === self::TYPE_AGENT;
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** Fournisseurs auxquels un approvisionnement peut être rattaché (tout sauf l'entrée de sélection « Retour Agent »). */
    public function scopeApprovisionnables($query)
    {
        return $query->where(fn ($q) => $q->whereRaw('UPPER(type) <> ?', [self::TYPE_AGENT])->orWhereNotNull('agent_id'));
    }

    /** Fournisseurs proposés dans le formulaire de création : les fournisseurs « réels » et l'entrée « Retour Agent ». */
    public function scopeSelectionnables($query)
    {
        return $query->whereNull('agent_id');
    }

    /**
     * Entrée « Retour Agent » de l'entreprise courante, créée si besoin (provisionnement, migration, ou rattrapage
     * pour une entreprise créée avant la fonctionnalité). Jamais plus d'une par entreprise.
     */
    public static function retourAgent(): self
    {
        $existing = static::withTrashed()->whereRaw('UPPER(type) = ?', [self::TYPE_AGENT])->whereNull('agent_id')->orderBy('id')->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return static::create([
            'name' => self::RETOUR_AGENT_NAME,
            'type' => self::TYPE_AGENT,
            'notes' => 'Entrée système : retours d\'UV des agents (rebalancing). L\'agent est choisi à chaque retour.',
            'is_active' => true,
        ]);
    }

    /** Compte fournisseur de cet agent (créé au premier retour d'UV), traité comme un Agent Premium. */
    public static function pourAgent(Agent $agent): self
    {
        $existing = static::withTrashed()->where('agent_id', $agent->getKey())->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return static::create([
            'name' => self::RETOUR_AGENT_NAME.' — '.$agent->name,
            'type' => self::TYPE_AGENT,
            'agent_id' => $agent->getKey(),
            'contact' => $agent->phone,
            'notes' => 'Compte créé automatiquement : UV rendus par l\'agent '.$agent->name.', à lui payer.',
            'is_active' => true,
        ]);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[strtoupper((string) $this->type)] ?? (string) $this->type;
    }

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Fournisseur {$eventName}")
            ->useLogName('fournisseurs')
            ->logExcept(['updated_at']);
    }
}
