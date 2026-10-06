<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;

/**
 * Garde-fous des commandes de démonstration (suppression / réinitialisation de données).
 * Aucune option ne permet de les contourner : elles ne s'exécutent que sur un poste de développement,
 * et jamais dès qu'il existe plusieurs sociétés (aucune suppression globale accidentelle possible).
 */
trait GuardsDemoCommands
{
    /** Raison du refus, ou null si l'exécution est sûre. */
    protected function demoRefusal(): ?string
    {
        if (! app()->environment(['local', 'development', 'testing', 'mobiledev'])) {
            return 'Environnement « '.app()->environment().' » : réservé au développement (local, development, mobiledev). '
                .'« staging » est exclu car il peut contenir une copie de production.';
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $this->looksLikeDevelopmentHost($host)) {
            return "APP_URL « {$host} » ne ressemble pas à un poste de développement : APP_ENV est peut-être mal réglé sur un serveur réel.";
        }

        if (Tenant::query()->count() > 1) {
            return 'Plusieurs tenants existent : ces commandes de démonstration sont interdites dès que la plateforme héberge plus d\'une société.';
        }

        return null;
    }

    private function looksLikeDevelopmentHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1', '10.0.2.2'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost');
    }
}
