<?php

namespace App\Cms;

use App\Models\CmsEntry;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Accès au contenu éditable. Valeur = surcharge enregistrée par le super admin, sinon valeur par défaut du registre.
 *  - une clé inconnue est une erreur de programmation : exception (jamais de texte vide silencieux) ;
 *  - une indisponibilité du CMS (table absente avant migration, base en panne) ne casse JAMAIS une page publique :
 *    on retombe sur les valeurs par défaut ;
 *  - rendu en TEXTE brut : l'échappement HTML se fait à l'affichage (Blade {{ }}), jamais ici.
 */
class Content
{
    private const CACHE_KEY = 'cms.overrides';

    /** @var array<string, string>|null */
    private static ?array $overrides = null;

    /** @param  array<string, scalar|null>  $vars repères {nom} remplacés dans le texte */
    public static function text(string $key, array $vars = []): string
    {
        $definition = ContentRegistry::definition($key);
        $value = self::overrides()[$key] ?? $definition['default'];

        return self::interpolate($value, $vars);
    }

    /**
     * @param  array<string, scalar|null>  $vars
     * @return list<string> une entrée par ligne non vide
     */
    public static function lines(string $key, array $vars = []): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', self::text($key, $vars)) ?: []),
            fn (string $line) => $line !== ''
        ));
    }

    /** Valeur enregistrée uniquement (null = valeur par défaut en vigueur). */
    public static function override(string $key): ?string
    {
        return self::overrides()[$key] ?? null;
    }

    public static function flush(): void
    {
        self::$overrides = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // cache indisponible : la mémoire de la requête est déjà vidée
        }
    }

    /** @return array<string, string> */
    private static function overrides(): array
    {
        if (self::$overrides !== null) {
            return self::$overrides;
        }

        try {
            return self::$overrides = Cache::rememberForever(self::CACHE_KEY, fn () => CmsEntry::query()->whereNotNull('value')->pluck('value', 'key')->all());
        } catch (Throwable) {
            return self::$overrides = []; // CMS indisponible : valeurs par défaut
        }
    }

    /** @param  array<string, scalar|null>  $vars */
    private static function interpolate(string $text, array $vars): string
    {
        foreach ($vars as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }

    public static function assertKnown(string $key): void
    {
        if (! ContentRegistry::has($key)) {
            throw new InvalidArgumentException("Contenu CMS inconnu : {$key}");
        }
    }
}
