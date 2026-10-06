<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Source unique de vérité du tenant courant. Le tenant n'est JAMAIS lu dans la requête
 * (paramètre, en-tête, corps JSON) : il découle de l'utilisateur authentifié.
 *
 * Ordre de résolution :
 *   1. un contexte explicite posé par du code serveur de confiance (runAs / withoutTenancy) ;
 *   2. le tenant de l'utilisateur authentifié ;
 *   3. (tests uniquement) un tenant de repli ;
 * sinon : AUCUN tenant => les modèles tenant-owned ne renvoient rien et refusent l'écriture
 * (fail closed). Les commandes artisan (cron, queue) tournent en « mode système » : lecture
 * non filtrée, écriture refusée sans tenant explicite.
 */
class TenantContext
{
    /** @var list<array{id: int|null, bypass: bool}> */
    private static array $stack = [];

    private static ?int $fallback = null;

    private static ?bool $console = null;

    /** Tenant courant, ou null s'il n'y en a pas (visiteur, super admin, plateforme). */
    public static function id(): ?int
    {
        if ($frame = end(self::$stack)) {
            return $frame['id'];
        }

        $user = Auth::guard()->user();

        if ($user) {
            return $user->tenant_id !== null ? (int) $user->tenant_id : null;
        }

        return self::$fallback;
    }

    /**
     * Variante pour le scope du modèle User. Le guard charge l'utilisateur via une requête User :
     * on ne peut donc ni demander l'utilisateur au guard (boucle) ni filtrer pendant cette phase.
     * Retourne [filtrer ?, tenant_id].
     *
     * @return array{0: bool, 1: int|null}
     */
    public static function userScope(): array
    {
        if ($frame = end(self::$stack)) {
            return [! $frame['bypass'], $frame['id']];
        }

        $guard = Auth::guard();

        if (! $guard->hasUser()) {
            // Phase d'authentification (login, chargement de session/token) ou code système.
            return [self::$fallback !== null, self::$fallback];
        }

        $user = $guard->user();

        // Le super admin de plateforme (hors tenant) administre tous les comptes.
        if ($user->isSuperAdmin()) {
            return [false, null];
        }

        return [true, $user->tenant_id !== null ? (int) $user->tenant_id : null];
    }

    public static function requireId(): int
    {
        return self::id() ?? throw new TenantContextMissing('Aucun tenant courant : opération refusée.');
    }

    public static function current(): ?Tenant
    {
        $id = self::id();

        return $id ? Tenant::find($id) : null;
    }

    /** Un contexte posé par du code serveur de confiance (runAs / withoutTenancy) est-il actif ? */
    public static function hasExplicitContext(): bool
    {
        return self::$stack !== [];
    }

    /** true si le scope ne doit filtrer sur rien (bypass explicite ou mode système console). */
    public static function isUnscoped(): bool
    {
        if (($frame = end(self::$stack)) && $frame['bypass']) {
            return true;
        }

        return self::id() === null && self::isSystem();
    }

    /** Mode système : commande artisan / worker, jamais une requête HTTP ni un test. */
    public static function isSystem(): bool
    {
        return self::$console ?? (app()->runningInConsole() && ! app()->runningUnitTests());
    }

    /** Exécute $callback comme si $tenantId était le tenant courant (null = contexte plateforme). */
    public static function runAs(?int $tenantId, Closure $callback): mixed
    {
        self::$stack[] = ['id' => $tenantId, 'bypass' => false];

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** Exécute $callback sans aucun filtre tenant. Réservé au code de plateforme audité. */
    public static function withoutTenancy(Closure $callback): mixed
    {
        self::$stack[] = ['id' => null, 'bypass' => true];

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** Pour les commandes planifiées : exécute $callback une fois par tenant actif. */
    public static function forEachTenant(Closure $callback): void
    {
        Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->orderBy('id')->each(
            fn (Tenant $tenant) => self::runAs($tenant->id, fn () => $callback($tenant))
        );
    }

    /**
     * Équivalent scopé de DB::table() : les requêtes du query builder contournent les scopes
     * Eloquent, donc elles DOIVENT passer par ici pour toute table tenant-owned.
     */
    public static function table(string $table): Builder
    {
        $query = DB::table($table);

        if (self::isUnscoped()) {
            return $query;
        }

        return ($id = self::id()) === null
            ? $query->whereRaw('1 = 0')
            : $query->where("{$table}.tenant_id", $id);
    }

    /**
     * Pour Filament : ->unique(ignoreRecord: true, modifyRuleUsing: TenantContext::scopeUnique()).
     * La règle `unique` interroge la base sans passer par Eloquent : sans ce filtre, elle verrait
     * (et révélerait) les valeurs des autres tenants.
     */
    public static function scopeUnique(): Closure
    {
        return fn ($rule) => $rule->where('tenant_id', self::id());
    }

    /**
     * Chemin de stockage privé d'un fichier du tenant courant : tenants/{id}/{path}.
     * Sans tenant : refus (fail closed), jamais de dossier partagé.
     */
    public static function storagePath(string $path): string
    {
        return 'tenants/'.self::requireId().'/'.ltrim($path, '/');
    }

    /** Préfixe une clé de cache avec le tenant : un cache partagé ne doit jamais servir les chiffres d'un autre tenant. */
    public static function cacheKey(string $key): string
    {
        return 't'.(self::id() ?? 'none').':'.$key;
    }

    public static function setFallback(?int $tenantId): void
    {
        self::$fallback = $tenantId;
    }

    public static function forceConsole(?bool $console): void
    {
        self::$console = $console;
    }

    public static function reset(): void
    {
        self::$stack = [];
        self::$fallback = null;
        self::$console = null;
        \App\Services\Subscriptions\SubscriptionAccessPolicy::flush();
    }
}
