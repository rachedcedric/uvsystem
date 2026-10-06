<?php

namespace Tests\Feature\Subscriptions;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class SubscriptionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Ici on teste l'isolation et les droits : aucun tenant de repli pour les requêtes anonymes.
        TenantContext::setFallback(null);
    }

    protected function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('slug', $slug)->firstOrFail();
    }

    protected function tenant(string $name = 'Société Test'): Tenant
    {
        return Tenant::create(['name' => $name.' '.uniqid()]);
    }

    protected function userIn(Tenant $tenant, string $role = 'master'): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** Fait avancer l'horloge ET vide le mémo de la politique d'accès (en production, une requête dure quelques ms). */
    protected function travelToDate(string $date): void
    {
        \Illuminate\Support\Carbon::setTestNow($date);
        \App\Services\Subscriptions\SubscriptionAccessPolicy::flush();
    }
}
