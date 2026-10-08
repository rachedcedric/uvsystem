<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Filament\Pages\SubscriptionPage;
use App\Filament\Pages\TenantProfilePage;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionManager;
use App\Services\Subscriptions\TenantHealthMetrics;
use App\Services\TenantDocumentBranding;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

class MasterAccountPagesTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_master_sees_usage_and_manages_the_subscription_from_the_web_panel(): void
    {
        $tenant = $this->tenant();
        $master = $this->userIn($tenant);
        $subscription = app(SubscriptionManager::class)->activate(
            $tenant,
            $this->plan('pro'),
            SubscriptionCycle::Monthly,
        );
        $this->actingAs($master);

        Livewire::test(SubscriptionPage::class)
            ->assertSuccessful()
            ->assertSee('Pro')
            ->assertSee('Utilisation de l’offre')
            ->assertSee('Fonctionnalités incluses')
            ->call('toggleAutoRenew')
            ->assertNotified('Renouvellement automatique activé');

        $this->assertTrue($subscription->fresh()->auto_renew);

        Livewire::test(SubscriptionPage::class)
            ->call('requestChange', $this->plan('entreprise')->id)
            ->assertNotified('Demande enregistrée');

        $this->assertSame(SubscriptionChangeRequest::PENDING, SubscriptionChangeRequest::query()->firstOrFail()->status);
        $this->assertSame(SubscriptionPayment::PENDING, SubscriptionPayment::query()->firstOrFail()->status);
    }

    public function test_only_tenant_managers_reach_the_subscription_and_profile_pages(): void
    {
        $tenant = $this->tenant();
        $master = $this->userIn($tenant, 'master');
        $admin = $this->userIn($tenant, 'admin');
        $commercial = $this->userIn($tenant, 'commercial');

        foreach ([$master, $admin] as $manager) {
            $this->actingAs($manager);
            Livewire::test(SubscriptionPage::class)->assertSuccessful();
            Livewire::test(TenantProfilePage::class)->assertSuccessful();
        }

        $this->actingAs($commercial);
        Livewire::test(SubscriptionPage::class)->assertForbidden();
        Livewire::test(TenantProfilePage::class)->assertForbidden();
    }

    public function test_master_updates_only_allowed_tenant_profile_fields(): void
    {
        $tenant = $this->tenant();
        $tenant->update(['settings' => ['unrelated' => ['kept' => true]]]);
        $master = $this->userIn($tenant);
        $this->actingAs($master);

        Livewire::test(TenantProfilePage::class)
            ->fillForm([
                'name' => 'Maison UV Abidjan',
                'legal_name' => 'Maison UV Abidjan SARL',
                'contact_name' => 'Awa Koné',
                'contact_email' => 'contact@maison-uv.test',
                'contact_phone' => '+225 07 00 00 00 00',
                'address' => 'Rue des Opérateurs',
                'city' => 'Abidjan',
                'country' => 'Côte d’Ivoire',
                'website' => 'https://maison-uv.test',
                'document_footer' => 'Maison UV Abidjan SARL · Abidjan',
            ])
            ->set('data.status', Tenant::STATUS_SUSPENDED)
            ->set('data.slug', 'autre-tenant')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Profil enregistré');

        $tenant->refresh();
        $this->assertSame('Maison UV Abidjan', $tenant->name);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertNotSame('autre-tenant', $tenant->slug);
        $this->assertSame('Maison UV Abidjan SARL', data_get($tenant->settings, 'profile.legal_name'));
        $this->assertTrue((bool) data_get($tenant->settings, 'unrelated.kept'));
    }

    public function test_platform_health_metrics_surface_actionable_tenant_risks(): void
    {
        $baseline = app(TenantHealthMetrics::class)->snapshot();
        $initialTenantCount = TenantContext::withoutTenancy(fn () => Tenant::query()->count());

        $limited = $this->tenant('Limité');
        app(SubscriptionManager::class)->activate($limited, $this->plan('essentiel'), SubscriptionCycle::Monthly);
        $master = $this->userIn($limited, 'master');
        $this->userIn($limited, 'commercial');
        $this->userIn($limited, 'comptable');

        $withoutMaster = $this->tenant('Sans Master');
        $this->userIn($withoutMaster, 'commercial');

        $this->actingAs($master);
        app(SubscriptionBilling::class)->requestChange(
            $limited,
            $this->plan('pro'),
            SubscriptionCycle::Monthly,
            $master,
        );

        $this->assertTrue($master->fresh()->hasRole('master'));
        $this->assertSame($initialTenantCount + 2, TenantContext::withoutTenancy(fn () => Tenant::query()->count()));

        $metrics = app(TenantHealthMetrics::class)->snapshot();

        $this->assertSame($baseline['quota_reached'] + 1, $metrics['quota_reached']);
        $this->assertSame($baseline['user_limit_reached'] + 1, $metrics['user_limit_reached']);
        $this->assertSame($baseline['pending_changes'] + 1, $metrics['pending_changes']);
        $this->assertSame($baseline['pending_payments'] + 1, $metrics['pending_payments']);
        $this->assertSame($baseline['without_master'] + 1, $metrics['without_master']);
    }

    public function test_master_account_pages_are_registered_in_the_expected_menu_group(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant));

        $menu = collect(Filament::getNavigation())->mapWithKeys(fn ($group) => [
            (string) $group->getLabel() => collect($group->getItems())->map(fn ($item) => $item->getLabel())->values()->all(),
        ])->all();

        $this->assertContains('Mon abonnement', $menu['Mon Compte']);
        $this->assertContains('Profil de l’entreprise', $menu['Mon Compte']);
    }

    public function test_document_branding_uses_only_the_current_tenant_files_and_escapes_custom_text(): void
    {
        $tenant = $this->tenant();
        $logoPath = $tenant->storagePath('branding/logo.png');
        Storage::disk('public')->put($logoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $tenant->update([
            'contact_email' => 'documents@societe.test',
            'settings' => [
                'profile' => [
                    'legal_name' => 'Société Documents SARL',
                    'city' => 'Abidjan',
                    'document_footer' => '<script>alert("x")</script> RCCM CI-ABJ-01',
                ],
                'branding' => ['logo_path' => $logoPath],
            ],
        ]);

        $branding = TenantContext::runAs($tenant->id, fn () => app(TenantDocumentBranding::class)->current());
        $html = view('exports.partials.tenant-document-branding', compact('branding'))->render();

        $this->assertSame('Société Documents SARL', $branding['legal_name']);
        $this->assertStringStartsWith('data:image/png;base64,', $branding['logo_data_uri']);
        $this->assertStringContainsString('documents@societe.test', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $this->actingAs($this->userIn($tenant));
        $this->get(route('tenant.branding.logo'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $tenant->update(['settings' => ['branding' => ['logo_path' => 'tenants/999/branding/logo.png']]]);
        $this->assertNull(TenantContext::runAs($tenant->id, fn () => app(TenantDocumentBranding::class)->current())['logo_data_uri']);
        $this->get(route('tenant.branding.logo'))->assertNotFound();

        $tenant->update(['settings' => ['branding' => ['logo_path' => $tenant->storagePath('branding/../secret.png')]]]);
        $this->assertNull(TenantContext::runAs($tenant->id, fn () => app(TenantDocumentBranding::class)->storedLogoPath($tenant)));
        $this->get(route('tenant.branding.logo'))->assertNotFound();
    }

    public function test_a_real_pdf_carries_the_current_company_footer_and_never_another_one(): void
    {
        View::addLocation(base_path('tests/Fixtures/views'));
        $a = $this->tenant('Alpha');
        $b = $this->tenant('Bravo');
        $a->update(['settings' => ['profile' => ['legal_name' => 'Alpha SARL', 'document_footer' => 'RCCM ALPHA-001']]]);
        $b->update(['settings' => ['profile' => ['legal_name' => 'Bravo SARL', 'document_footer' => 'RCCM BRAVO-002']]]);

        $htmlFor = function (Tenant $tenant): array {
            return TenantContext::runAs($tenant->id, function () {
                $pdf = Pdf::loadView('branding-probe');

                return [$pdf->getDomPDF()->outputHtml(), $pdf->output()];
            });
        };

        [$htmlA, $outputA] = $htmlFor($a);
        [$htmlB] = $htmlFor($b);

        $this->assertStringContainsString('Contenu du document', $htmlA);
        $this->assertStringContainsString('Alpha SARL', $htmlA);
        $this->assertStringContainsString('RCCM ALPHA-001', $htmlA);
        $this->assertStringNotContainsString('BRAVO', $htmlA);
        $this->assertStringContainsString('Bravo SARL', $htmlB);
        $this->assertStringNotContainsString('ALPHA', $htmlB);
        $this->assertStringStartsWith('%PDF', $outputA);
    }

    public function test_a_pdf_without_company_context_gets_no_footer(): void
    {
        View::addLocation(base_path('tests/Fixtures/views'));

        $html = TenantContext::withoutTenancy(fn () => Pdf::loadView('branding-probe')->getDomPDF()->outputHtml());

        $this->assertStringContainsString('Contenu du document', $html);
        $this->assertStringNotContainsString('border-top: 1px solid #cbd5e1', $html);
    }

    public function test_logo_upload_is_stored_under_the_company_folder_and_replacing_it_deletes_the_old_file(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant));

        Livewire::test(TenantProfilePage::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->image('logo.png', 120, 60)])
            ->call('save')
            ->assertHasNoFormErrors();

        $first = data_get($tenant->fresh()->settings, 'branding.logo_path');
        $this->assertNotNull($first);
        $this->assertStringStartsWith($tenant->storagePath('branding/'), $first);
        Storage::disk('public')->assertExists($first);

        // Comme dans le navigateur : on retire d'abord le logo en place, puis on envoie le nouveau.
        Livewire::test(TenantProfilePage::class)
            ->set('data.logo_path', [])
            ->fillForm(['logo_path' => UploadedFile::fake()->image('nouveau.jpg', 120, 60)])
            ->call('save')
            ->assertHasNoFormErrors();

        $second = data_get($tenant->fresh()->settings, 'branding.logo_path');
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_only_raster_images_are_accepted_as_a_logo(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant));

        foreach ([
            UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->create('logo.php', 5, 'application/x-php'),
            UploadedFile::fake()->create('logo.pdf', 5, 'application/pdf'),
        ] as $file) {
            Livewire::test(TenantProfilePage::class)
                ->fillForm(['logo_path' => $file])
                ->call('save')
                ->assertHasFormErrors(['logo_path']);
        }

        $this->assertNull(data_get($tenant->fresh()->settings, 'branding.logo_path'));
    }

    public function test_a_logo_path_pointing_at_another_company_is_never_served_nor_embedded(): void
    {
        $mine = $this->tenant('Mine');
        $other = $this->tenant('Autre');
        $foreign = $other->storagePath('branding/logo.png');
        Storage::disk('public')->put($foreign, 'not-really-a-png');
        $mine->update(['settings' => ['branding' => ['logo_path' => $foreign]]]);

        $this->assertNull(app(TenantDocumentBranding::class)->storedLogoPath($mine));

        $this->actingAs($this->userIn($mine));
        $this->get(route('tenant.branding.logo'))->assertNotFound();
    }

    public function test_saving_other_profile_fields_keeps_the_existing_logo(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant));

        Livewire::test(TenantProfilePage::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->image('logo.png', 120, 60)])
            ->call('save');
        $logo = data_get($tenant->fresh()->settings, 'branding.logo_path');

        Livewire::test(TenantProfilePage::class)
            ->fillForm(['city' => 'Bouaké'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($logo, data_get($tenant->fresh()->settings, 'branding.logo_path'));
        Storage::disk('public')->assertExists($logo);
        $this->assertSame('Bouaké', data_get($tenant->fresh()->settings, 'profile.city'));
    }

    public function test_the_account_stylesheet_is_scoped_to_its_wrapper(): void
    {
        $css = file_get_contents(public_path('css/filament-account.css'));
        $stripped = preg_replace('/@media[^{]*\{/', '', preg_replace('#/\*.*?\*/#s', '', $css));
        preg_match_all('/(^|\})\s*([^{}@][^{}]*)\{/m', $stripped, $matches);

        $this->assertStringContainsString('.dark .acct', $css);
        foreach ($matches[2] as $selectorList) {
            foreach (explode(',', $selectorList) as $selector) {
                $this->assertStringContainsString('.acct', trim($selector), "sélecteur hors périmètre : $selector");
            }
        }
        $this->assertStringContainsString('<div class="acct ', file_get_contents(resource_path('views/filament/pages/subscription-page.blade.php')));
    }

    public function test_an_uploaded_logo_is_reduced_to_a_small_file(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant));

        Livewire::test(TenantProfilePage::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->image('logo.png', 1800, 900)])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = data_get($tenant->fresh()->settings, 'branding.logo_path');
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame(TenantDocumentBranding::LOGO_MAX_SIDE, $width);
        $this->assertSame(TenantDocumentBranding::LOGO_MAX_SIDE / 2, $height);
    }

    public function test_a_logo_with_absurd_dimensions_is_refused(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant));

        Livewire::test(TenantProfilePage::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->image('large.png', 2600, 10)])
            ->call('save')
            ->assertHasFormErrors(['logo_path']);
    }

    public function test_an_old_oversized_logo_is_reduced_before_being_embedded_in_a_pdf(): void
    {
        $tenant = $this->tenant();
        $image = imagecreatetruecolor(900, 900);
        for ($i = 0; $i < 900; $i++) {
            for ($j = 0; $j < 900; $j += 3) {
                imagesetpixel($image, $i, $j, random_int(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($image, null, 95);
        $heavy = (string) ob_get_clean();
        $this->assertGreaterThan(120_000, strlen($heavy));

        $path = $tenant->storagePath('branding/ancien.jpg');
        Storage::disk('public')->put($path, $heavy);
        $tenant->update(['settings' => ['branding' => ['logo_path' => $path]]]);

        $branding = TenantContext::runAs($tenant->id, fn () => app(TenantDocumentBranding::class)->current());
        $embedded = base64_decode(substr($branding['logo_data_uri'], strpos($branding['logo_data_uri'], ',') + 1));
        [$width] = getimagesizefromstring($embedded);

        $this->assertLessThanOrEqual(TenantDocumentBranding::LOGO_MAX_SIDE, $width);
        $this->assertLessThan(strlen($heavy) / 3, strlen($embedded));
    }
}
