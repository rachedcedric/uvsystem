<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Services\Subscriptions\SubscriptionAccessPolicy;
use App\Services\Subscriptions\SubscriptionBanner;
use App\Services\Subscriptions\SubscriptionManager;
use Filament\Facades\Filament;

/** Avertissement d'abonnement en haut des écrans de l'entreprise (panneau web et mobile). */
class SubscriptionBannerTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['security.authentication.2fa_enabled' => false]);
    }

    private function activeTenantUser(string $role = 'master')
    {
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        return $this->userIn($tenant, $role);
    }

    public function test_no_banner_when_the_subscription_is_healthy(): void
    {
        $this->assertNull(SubscriptionBanner::for($this->activeTenantUser()));
    }

    public function test_a_warning_appears_in_the_last_week_with_the_days_left(): void
    {
        $user = $this->activeTenantUser();
        $this->travelToDate(now()->addDays(26)->toDateString());

        $banner = SubscriptionBanner::for($user->fresh());

        $this->assertSame('warning', $banner['level']);
        $this->assertStringContainsString('expire dans', $banner['message']);
    }

    public function test_an_expired_subscription_shows_the_read_only_message(): void
    {
        $user = $this->activeTenantUser();
        $this->travelToDate(now()->addDays(40)->toDateString());

        $banner = SubscriptionBanner::for($user->fresh());

        $this->assertSame('danger', $banner['level']);
        $this->assertSame('Compte en consultation seule.', $banner['title']);
    }

    public function test_a_tenant_without_subscription_is_told_it_is_read_only(): void
    {
        $user = $this->userIn($this->tenant());
        config(['subscriptions.require_subscription' => true]);
        SubscriptionAccessPolicy::flush();

        $this->assertSame('danger', SubscriptionBanner::for($user)['level']);
    }

    public function test_only_company_users_get_a_banner_never_the_platform_admin_nor_guests(): void
    {
        $this->assertNull(SubscriptionBanner::for(null));
        $this->assertNull(SubscriptionBanner::for($this->superAdmin()));
    }

    public function test_only_master_and_admin_are_offered_the_subscription_link(): void
    {
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $master = $this->userIn($tenant, 'master');
        $comptable = $this->userIn($tenant, 'comptable');
        $this->travelToDate(now()->addDays(40)->toDateString());

        $this->assertTrue(SubscriptionBanner::for($master->fresh())['manageable']);
        $this->assertFalse(SubscriptionBanner::for($comptable->fresh())['manageable']);
    }

    public function test_the_banner_is_rendered_on_the_web_panel_and_the_mobile_app_but_not_on_the_subscription_screens(): void
    {
        $user = $this->activeTenantUser();
        $this->travelToDate(now()->addDays(40)->toDateString());
        $user = $user->fresh();

        $this->actingAs($user)->get('/master')
            ->assertOk()
            ->assertSee('data-subscription-banner="danger"', false)
            ->assertSee('/master/mon-abonnement', false);

        $this->actingAs($user)->get('/m')
            ->assertOk()
            ->assertSee('data-subscription-banner="danger"', false)
            ->assertSee('/m/abonnement', false);

        $this->actingAs($user)->get('/master/mon-abonnement')->assertDontSee('data-subscription-banner', false);
        $this->actingAs($user)->get('/m/abonnement')->assertDontSee('data-subscription-banner', false);
    }

    public function test_a_healthy_company_sees_no_banner_anywhere(): void
    {
        $user = $this->activeTenantUser();

        $this->actingAs($user)->get('/master')->assertOk()->assertDontSee('data-subscription-banner', false);
        $this->actingAs($user)->get('/m')->assertOk()->assertDontSee('data-subscription-banner', false);
    }
}
