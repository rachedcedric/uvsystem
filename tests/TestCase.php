<?php

namespace Tests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
