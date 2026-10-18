<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\BrandAsset;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Subscriptions\SubscriptionTestCase;

/**
 * Trois réglages d'affichage : le panneau admin ne montre que le logo du produit, la connexion mobile est centrée, la page
 * publique des offres est centrée.
 */
class UiAdjustmentsTest extends SubscriptionTestCase
{
    public function test_the_admin_panel_shows_only_the_product_logo_even_when_the_enterprise_uploaded_its_own(): void
    {
        Storage::fake('public');

        // Une entreprise qui a téléversé son propre logo (profil de l'entreprise).
        $tenant = Tenant::create(['name' => 'Société avec son logo', 'settings' => ['branding' => ['logo_path' => 'tenants/9/branding/logo.png']]]);
        Storage::disk('public')->put('tenants/9/branding/logo.png', 'png');
        $this->actingAs($this->userIn($tenant, 'master'));

        $panel = Filament::getPanel('admin');

        $this->assertSame(BrandAsset::url('images/logo-wide.png'), (string) $panel->getBrandLogo());
        $this->assertSame(BrandAsset::url('images/logo-wide-dark.png'), (string) $panel->getDarkModeBrandLogo());
        $this->assertStringNotContainsString('branding', (string) $panel->getBrandLogo());
        $this->assertFileExists(public_path('images/logo-wide.png'));
        $this->assertFileExists(public_path('images/logo-wide-dark.png'));

        // Le logo téléversé n'est plus lu par le panneau : il reste réservé aux documents et à la carte mobile de l'entreprise.
        $this->assertStringNotContainsString('tenantLogoUrl', file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php')));
    }

    public function test_the_mobile_login_brand_block_is_centered(): void
    {
        $html = $this->get(route('m.login'))->assertOk()->getContent();

        $this->assertStringContainsString('m-login__brand', $html);
        $this->assertStringContainsString('m-login__logo', $html);

        $css = file_get_contents(resource_path('css/mobile.css'));

        $this->assertSame(1, preg_match('/\.m-login__brand\s*\{([^}]*)\}/', $css, $brand));
        $this->assertStringContainsString('align-items: center;', $brand[1]);
        $this->assertStringContainsString('text-align: center;', $brand[1]);
        $this->assertStringNotContainsString('flex-start', $brand[1]);

        $this->assertSame(1, preg_match('/\.m-login__logo\s*\{([^}]*)\}/', $css, $logo));
        $this->assertStringContainsString('margin-inline: auto;', $logo[1]);
    }

    public function test_the_public_offers_page_is_centered(): void
    {
        // Les offres actives viennent de la base (migrations) : on s'assure qu'elles existent avant de rendre la page.
        $this->plan('pro');
        $this->plan('essentiel');

        $html = $this->get(route('offres'))->assertOk()->getContent();

        // Tout le contenu de la page est dans le bloc centré, et les cartes forment une rangée centrée.
        $this->assertStringContainsString('<div class="offres">', $html);
        $this->assertStringContainsString('.offres{text-align:center}', $html);
        $this->assertStringContainsString('.offres .grid{display:flex;flex-wrap:wrap;justify-content:center;', $html);
        $this->assertStringContainsString('class="grid"', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'class="plan'));

        // Le HTML reste équilibré : autant de <div> ouvertes que fermées.
        $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'));
    }
}
