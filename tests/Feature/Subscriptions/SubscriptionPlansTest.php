<?php

namespace Tests\Feature\Subscriptions;

use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

class SubscriptionPlansTest extends SubscriptionTestCase
{
    public function test_the_three_initial_plans_exist_with_the_agreed_values(): void
    {
        $essentiel = $this->plan('essentiel');
        $this->assertSame([15000, 150000, 3, 100], [$essentiel->monthly_price, $essentiel->yearly_price, $essentiel->max_users, $essentiel->max_agents]);

        $pro = $this->plan('pro');
        $this->assertSame([30000, 300000, 10, 500], [$pro->monthly_price, $pro->yearly_price, $pro->max_users, $pro->max_agents]);

        $entreprise = $this->plan('entreprise');
        $this->assertSame([60000, 600000], [$entreprise->monthly_price, $entreprise->yearly_price]);
        // Illimité = NULL explicite, jamais 0.
        $this->assertNull($entreprise->max_users);
        $this->assertNull($entreprise->max_agents);
    }

    public function test_the_seeder_is_idempotent_and_never_overwrites_admin_edits(): void
    {
        $this->plan('pro')->update(['monthly_price' => 99000, 'name' => 'Pro+']);

        $this->seed(SubscriptionPlanSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(3, SubscriptionPlan::count());
        $this->assertSame(99000, $this->plan('pro')->monthly_price);
        $this->assertSame('Pro+', $this->plan('pro')->name);
    }

    public function test_a_plan_can_be_created_edited_deactivated_and_reactivated(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Sur mesure', 'slug' => 'sur-mesure', 'monthly_price' => 1000, 'yearly_price' => 10000,
            'max_users' => 2, 'max_agents' => 5, 'features' => ['exports_pdf'], 'is_active' => true,
        ]);
        $this->assertSame(['exports_pdf'], $plan->fresh()->features);
        $this->assertIsInt($plan->fresh()->monthly_price);

        $plan->update(['monthly_price' => 2000, 'is_featured' => true]);
        $this->assertSame(2000, $plan->fresh()->monthly_price);
        $this->assertTrue($plan->fresh()->is_featured);

        $plan->update(['is_active' => false]);
        $this->assertFalse(SubscriptionPlan::active()->where('slug', 'sur-mesure')->exists());
        $plan->update(['is_active' => true]);
        $this->assertTrue(SubscriptionPlan::active()->where('slug', 'sur-mesure')->exists());
    }

    public function test_the_slug_is_unique(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        SubscriptionPlan::create(['name' => 'Doublon', 'slug' => 'pro', 'monthly_price' => 1, 'yearly_price' => 1]);
    }

    public function test_a_plan_used_by_a_subscription_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $unused = SubscriptionPlan::create(['name' => 'Jamais utilisée', 'slug' => 'jamais', 'monthly_price' => 1, 'yearly_price' => 1]);
        $unused->delete();
        $this->assertNull(SubscriptionPlan::where('slug', 'jamais')->first());

        // « entreprise » est utilisée par l'abonnement hérité du tenant n°1 créé par la migration.
        $used = $this->plan('entreprise');
        $this->assertTrue($used->isUsed());

        try {
            $used->delete();
            $this->fail('La suppression aurait dû être refusée.');
        } catch (LogicException) {
            $this->assertNotNull($this->plan('entreprise'));
        }
    }

    public function test_an_inactive_plan_cannot_be_assigned_to_a_tenant(): void
    {
        $this->plan('pro')->update(['is_active' => false]);

        $this->expectException(InvalidSubscriptionTransition::class);
        app(SubscriptionManager::class)->startTrial($this->tenant(), $this->plan('pro'));
    }

    public function test_features_of_included_plans_are_resolved(): void
    {
        $essentiel = $this->plan('essentiel');
        $pro = $this->plan('pro');
        $entreprise = $this->plan('entreprise');

        $this->assertTrue($essentiel->hasFeature('exports_pdf'));
        $this->assertFalse($essentiel->hasFeature('scoring_agents'));

        $this->assertTrue($pro->hasFeature('scoring_agents'));
        $this->assertTrue($pro->hasFeature('exports_pdf'), 'Pro inclut Essentiel');
        $this->assertTrue($entreprise->hasFeature('import_donnees'), 'Entreprise inclut Pro');
        $this->assertTrue($entreprise->hasFeature('notifications_dettes'), 'et, par Pro, Essentiel');
        $this->assertNotContains('toutes_les_fonctions_pro', $entreprise->resolvedFeatures());
    }

    public function test_circular_inclusions_do_not_loop(): void
    {
        $a = SubscriptionPlan::create(['name' => 'A', 'slug' => 'a', 'monthly_price' => 1, 'yearly_price' => 1, 'features' => ['toutes_les_fonctions_b', 'x']]);
        SubscriptionPlan::create(['name' => 'B', 'slug' => 'b', 'monthly_price' => 1, 'yearly_price' => 1, 'features' => ['toutes_les_fonctions_a', 'y']]);

        $this->assertEqualsCanonicalizing(['x', 'y'], $a->resolvedFeatures());
    }
}
