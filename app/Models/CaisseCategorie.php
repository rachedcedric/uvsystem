<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CaisseCategorie extends Model
{
    use LogsActivity;

    protected $table = 'caisse_categories';

    protected $fillable = ['name', 'color', 'sort_order', 'is_active', 'is_system'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public static function nameIsTaken(string $name, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('normalized_name', static::normalize($name))
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(CaisseMouvement::class, 'caisse_categorie_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isUsed(): bool
    {
        return $this->mouvements()->withTrashed()->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (self $categorie) {
            $categorie->name = trim((string) preg_replace('/\s+/u', ' ', $categorie->name));
            $categorie->normalized_name = static::normalize($categorie->name);
        });

        static::deleting(function (self $categorie) {
            if ($categorie->is_system || $categorie->isUsed()) {
                return false;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('caisse');
    }
}
