<?php

namespace App\Models;

use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\UserTenantScope;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    /** Rôle de consultation : voit les données autorisées, ne peut jamais rien modifier (voir App\Security\ReadOnlyGuard). */
    public const ROLE_READ_ONLY = 'lecture_seule';

    /** Rôles métier d'un tenant (le rôle super_admin n'appartient à aucun tenant). */
    public const TENANT_ROLES = ['master', 'admin', 'comptable', 'commercial', self::ROLE_READ_ONLY];

    /** Capacités globales ou protégées qu'aucun compte de tenant ne doit recevoir. */
    public const FORBIDDEN_TENANT_PERMISSIONS = [
        'view_roles',
        'create_roles',
        'edit_roles',
        'delete_roles',
        'delete_activity_log',
        'manage_backups',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_confirmed_at',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
            'two_factor_secret' => 'encrypted',
        ];
    }

    protected static function tenantScope(): Scope
    {
        return new UserTenantScope;
    }

    /** Comptes : 2FA, mot de passe, déconnexion restent possibles en accès restreint (la CRÉATION reste limitée). */
    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    protected static function enforcesEntitlements(): bool
    {
        return true;
    }

    /** Limite d'utilisateurs de l'offre : refus réel côté serveur, quel que soit le point d'entrée. */
    protected function enforceEntitlementsOnInsert(): void
    {
        if ($this->tenant_id !== null) {
            app(SubscriptionEntitlements::class)->assertCanCreateUser((int) $this->tenant_id);
        }
    }

    /** Un super admin de plateforme n'a pas de tenant : tenant_id peut rester vide. */
    protected static function tenantIdRequired(): bool
    {
        return false;
    }

    /**
     * Super admin de plateforme : rôle super_admin ET aucun tenant. Les deux conditions sont
     * exigées : un compte de tenant qui recevrait ce rôle par erreur n'obtient aucun pouvoir.
     */
    public function isSuperAdmin(): bool
    {
        return $this->tenant_id === null && $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    /**
     * Coupe tous les accès du compte : sessions web/mobile (driver « database »), tokens API et
     * cookies « rester connecté ». À appeler après un changement de mot de passe, une suspension
     * ou une demande de déconnexion partout. $exceptSessionId conserve la session courante.
     */
    public function revokeAllAccess(?string $exceptSessionId = null): void
    {
        $this->tokens()->delete();
        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $this->getKey())
                ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
                ->delete();
        }
    }

    /** Le tenant de cet utilisateur est-il actif (non suspendu) ? L'expiration d'abonnement n'ôte pas l'accès : voir SubscriptionAccessPolicy. */
    public function hasActiveTenant(): bool
    {
        return $this->tenant_id !== null && (bool) $this->tenant?->isActive();
    }

    /**
     * Rôles qu'un utilisateur de tenant peut attribuer : jamais super_admin.
     *
     * @return list<string>
     */
    public static function assignableRoleNames(): array
    {
        return self::TENANT_ROLES;
    }

    /**
     * Panneau /platform : super admin uniquement. Panneaux /master et /agent : rôle métier
     * dans un tenant actif. Fail closed : sans tenant (hors super admin) ou tenant suspendu => refus.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'platform') {
            return $this->isSuperAdmin();
        }

        return $this->hasActiveTenant() && $this->hasAnyRole(self::TENANT_ROLES);
    }

    /**
     * Get the agent associated with this user (if any).
     */
    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }

    /**
     * Check if user is a master.
     */
    public function isMaster(): bool
    {
        return $this->hasRole('master');
    }

    /** Compte en lecture seule : refus d'écriture côté serveur, quel que soit le chemin (web, mobile, API). */
    public function isReadOnly(): bool
    {
        return $this->hasRole(self::ROLE_READ_ONLY);
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['master', 'admin']);
    }

    /**
     * Check if 2FA is enabled for this user.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_secret;
    }
}
