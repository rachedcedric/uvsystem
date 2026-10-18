<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDocumentBranding;
use App\Support\BrandAsset;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le serveur web sert les images en « immutable » pendant 30 jours : une adresse de logo sans « ?v= » reste affichée avec
 * l'ancienne image dans les navigateurs qui l'ont déjà chargée. Ce test parcourt les pages et refuse toute image de marque
 * non versionnée, ou toute page sans icône d'onglet.
 */
class BrandAssetsVersionedTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> adresses d'images de marque trouvées dans la page */
    private function brandImages(string $html): array
    {
        preg_match_all('/(?:src|srcset|href)="([^"]*(?:images\/logo[^"]*|favicon[^"]*|apple-touch-icon[^"]*))"/i', $html, $matches);

        return array_values(array_unique(array_map('html_entity_decode', $matches[1])));
    }

    private function assertBranded(string $page, string $html): void
    {
        $this->assertNotEmpty($this->brandImages($html), "{$page} : aucune icône ni logo de marque");

        foreach ($this->brandImages($html) as $url) {
            $this->assertMatchesRegularExpression('/\?v=\d+$/', $url, "{$page} : {$url} n'est pas versionné");
        }

        $this->assertStringNotContainsStringIgnoringCase('model'.'ec', $html, "{$page} : ancien nom de marque");
    }

    public function test_every_public_and_login_page_uses_versioned_brand_images_and_a_favicon(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['/', '/offres', '/inscription/envoye', '/inscription/terminee', '/forgot-password', '/m/login', '/master/login', '/platform/login', '/agent/login', '/page-inconnue'] as $uri) {
            $this->assertBranded($uri, (string) $this->get($uri)->getContent());
        }
    }

    public function test_the_authenticated_screens_use_versioned_brand_images(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $master = User::factory()->create();
        $master->assignRole('master');
        $this->actingAs($master);

        foreach (['/master', '/master/agents', '/m', '/m/plus', '/m/ventes'] as $uri) {
            $this->assertBranded($uri, (string) $this->get($uri)->getContent());
        }
    }

    public function test_the_payment_return_page_shows_the_logo_and_a_favicon(): void
    {
        $html = view('payments.jeko-return', ['title' => 'Paiement reçu', 'message' => 'Merci', 'successful' => true])->render();

        $this->assertBranded('jeko-return', $html);
        $this->assertStringContainsString('· '.config('branding.name'), $html);
    }

    public function test_pdf_headers_embed_the_logo_and_no_emoji_that_dompdf_cannot_draw(): void
    {
        $this->assertStringStartsWith('data:image/png;base64,', (string) BrandAsset::dataUri('images/logo-wide.png'));

        foreach (array_merge(glob(resource_path('views/exports/*-pdf.blade.php')), [resource_path('views/receipts/remboursement.blade.php')]) as $file) {
            $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}]/u', (string) file_get_contents($file)), basename($file).' contient un emoji (affiché comme un carré dans le PDF)');
        }

        $this->assertStringContainsString('brand-mark', (string) file_get_contents(resource_path('views/exports/agent-fiche-pdf.blade.php')));
    }

    public function test_documents_name_the_company_not_the_technical_application_name(): void
    {
        foreach (['app/Filament/Pages/MaCaissePage.php', 'app/Filament/Pages/MaCaisseJourPage.php', 'app/Http/Controllers/Mobile/ExportController.php'] as $file) {
            $this->assertStringNotContainsString("'tenant' => config('app.name')", (string) file_get_contents(base_path($file)), $file);
        }
    }

    public function test_document_branding_resolves_the_current_company_name(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Entreprise Démonstration']);
        $branding = app(TenantDocumentBranding::class);

        $this->assertSame(
            'Entreprise Démonstration',
            TenantContext::runAs($tenant->id, fn (): string => $branding->tenantName()),
        );
        $this->assertSame(
            config('branding.name'),
            TenantContext::withoutTenancy(fn (): string => $branding->tenantName()),
        );
    }
}
