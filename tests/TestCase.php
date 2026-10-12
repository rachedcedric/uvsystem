<?php

namespace Tests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou : une configuration en cache (php artisan optimize / config:cache) fait ignorer phpunit.xml,
     * donc les tests viseraient la vraie base (.env) au lieu de SQLite. Exécutée AVANT RefreshDatabase.
     * Remède : php artisan optimize:clear, ou APP_CONFIG_CACHE=/chemin/inexistant php artisan test.
     */
    protected function setUpTraits()
    {
        if ($this->app->configurationIsCached() || ! $this->app->environment('testing')) {
            throw new \RuntimeException(
                'Tests refusés : configuration en cache ou APP_ENV différent de "testing" ('.$this->app->environment().'). '.
                'Les tests risqueraient d\'écrire dans la vraie base. Lancez « php artisan optimize:clear » '.
                'ou « APP_CONFIG_CACHE=/tmp/aucun php artisan test ».'
            );
        }

        return parent::setUpTraits();
    }

    /**
     * Tenant n°1 (créé par la migration) sert de tenant de repli : il rend les tests historiques,
     * écrits avant le multi-tenant, valides sans modification. Un utilisateur authentifié l'emporte
     * toujours sur ce repli ; les tests d'isolation le désactivent avec TenantContext::setFallback(null).
     */
    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::reset();
        TenantContext::setFallback(1);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();

        parent::tearDown();
    }
}
