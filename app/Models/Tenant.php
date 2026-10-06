<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Une société cliente de la plateforme. Volontairement NON scopée : seul le super admin
 * de plateforme la manipule (voir PlatformPanelProvider) ; l'utilisateur d'un tenant n'accède
 * qu'à son propre tenant via User::tenant().
 */
class Tenant extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'slug', 'status', 'plan', 'contact_name', 'contact_email', 'contact_phone',
        'settings', 'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->slug = $tenant->slug ?: static::uniqueSlug($tenant->name);
            $tenant->status = $tenant->status ?: self::STATUS_ACTIVE;
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /**
     * Tenant « d'origine » : celui créé par la migration, auquel appartient tout ce qui existait avant le
     * multi-tenant. C'est la seule société à laquelle des fichiers antérieurs peuvent être rattachés.
     */
    public static function legacyOwnerId(): ?int
    {
        $id = static::query()->min('id');

        return $id === null ? null : (int) $id;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }

    public function suspend(): void
    {
        $this->forceFill(['status' => self::STATUS_SUSPENDED, 'suspended_at' => now()])->save();

        // canAccessPanel() bloque déjà les requêtes ; on supprime aussi sessions et tokens pour que
        // rien ne subsiste (cookies « rester connecté », jetons API).
        \App\Tenancy\TenantContext::withoutTenancy(
            fn () => $this->users()->get()->each->revokeAllAccess()
        );
    }

    public function activate(): void
    {
        $this->forceFill(['status' => self::STATUS_ACTIVE, 'suspended_at' => null])->save();
    }

    /** Dossier de stockage privé de ce tenant : storage/app/private/tenants/{id}/… */
    public function storagePath(string $path = ''): string
    {
        return 'tenants/'.$this->getKey().($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}
