<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Exceptions\SubscriptionLimitReached;
use App\Models\Agent;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\PushToken;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\DebtAlertNotification;
use App\Notifications\PaymentReminderNotification;
use App\Services\FirebaseMessaging;
use App\Services\Subscriptions\FeatureCatalog;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Services\Subscriptions\SubscriptionManager;
use App\Support\Subscriptions\ExportGate;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Risque « fonctionnalités non toutes appliquées ». Chaque fonctionnalité technique du catalogue est refusée côté
 * serveur à un tenant dont l'offre ne l'inclut pas, et permise à celui qui l'inclut ; un test d'inventaire
 * échoue si une fonctionnalité est ajoutée au catalogue sans être couverte ici.
 */
class SubscriptionFeaturesTest extends SubscriptionTestCase
{
    /** fonctionnalité => test qui prouve son application */
    private const COVERAGE = [
        'statistiques_essentielles' => 'test_statistiques_essentielles',
        'statistiques_avancees' => 'test_statistiques_avancees',
        'exports_pdf' => 'test_exports_pdf_and_excel',
        'exports_excel' => 'test_exports_pdf_and_excel',
        'exports_avances' => 'test_exports_avances',
        'notifications_dettes' => 'test_notifications_dettes',
        'notifications_push' => 'test_notifications_push',
        'scoring_agents' => 'SubscriptionTenantAccessTest::test_the_mobile_import_and_scoring_depend_on_the_plan_features',
        'import_donnees' => 'SubscriptionTenantAccessTest::test_the_mobile_import_and_scoring_depend_on_the_plan_features',
        'rapports_avances' => 'test_rapports_avances',
        'sauvegardes_dediees' => 'test_sauvegardes_dediees',
    ];

    /** @return array{0: Tenant, 1: User} tenant dont l'offre ne contient QUE $features */
    private function tenantWith(array $features, array $planOverrides = []): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Sur mesure '.uniqid(), 'slug' => 'sm-'.uniqid(), 'monthly_price' => 1, 'yearly_price' => 1,
            'features' => $features, 'is_active' => true,
        ] + $planOverrides);
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $plan, SubscriptionCycle::Monthly);

        return [$tenant, $this->userIn($tenant)];
    }

    // ---------------------------------------------------------------- inventaire

    public function test_every_enforced_feature_has_a_proof_and_service_features_are_declared(): void
    {
        $this->assertEqualsCanonicalizing(FeatureCatalog::enforcedFeatures(), array_keys(self::COVERAGE), 'une fonctionnalité du catalogue n\'est couverte par aucun test d\'application');
        $this->assertSame(['assistance_prioritaire', 'options_personnalisees'], FeatureCatalog::serviceFeatures(), 'promesses de service, sans écran à verrouiller');

        foreach (self::COVERAGE as $feature => $test) {
            $method = str_contains($test, '::') ? explode('::', $test)[1] : $test;
            $class = str_contains($test, '::') ? 'Tests\\Feature\\Subscriptions\\'.explode('::', $test)[0] : static::class;
            $this->assertTrue(method_exists($class, $method), "$feature : test $test introuvable");
        }

        // Les offres livrées couvrent bien toutes les fonctionnalités techniques (Entreprise les a toutes).
        $entreprise = $this->plan('entreprise')->resolvedFeatures();
        foreach (FeatureCatalog::enforcedFeatures() as $feature) {
            $this->assertContains($feature, $entreprise, "Entreprise doit inclure $feature");
        }
    }

    // ---------------------------------------------------------------- statistiques

    public function test_statistiques_essentielles(): void
    {
        [, $without] = $this->tenantWith(['exports_pdf']);
        [, $with] = $this->tenantWith(['statistiques_essentielles']);

        $this->actingAs($without);
        Livewire::test(\App\Livewire\Mobile\Analytics\Statistics::class)->assertForbidden();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(\App\Filament\Pages\StatisticsPage::class)->assertForbidden();

        $this->actingAs($with);
        Livewire::test(\App\Livewire\Mobile\Analytics\Statistics::class)->assertSuccessful();
        Livewire::test(\App\Filament\Pages\StatisticsPage::class)->assertSuccessful();
    }

    public function test_statistiques_avancees(): void
    {
        [, $without] = $this->tenantWith(['statistiques_essentielles']);
        [, $with] = $this->tenantWith(['statistiques_avancees']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($without);
        Livewire::test(\App\Filament\Pages\RapportStatistiques::class)->assertForbidden();

        $this->actingAs($with);
        Livewire::test(\App\Filament\Pages\RapportStatistiques::class)->assertSuccessful();
    }

    // ---------------------------------------------------------------- exports

    public function test_exports_pdf_and_excel(): void
    {
        [, $none] = $this->tenantWith(['statistiques_essentielles']);
        [, $pdfOnly] = $this->tenantWith(['exports_pdf']);
        [, $excelOnly] = $this->tenantWith(['exports_excel']);

        // HTTP : même route, un format par fonctionnalité.
        $this->actingAs($none);
        $this->get('/m/exports/rapport/agents/pdf')->assertForbidden();
        $this->get('/m/exports/rapport/agents/xlsx')->assertForbidden();

        $this->actingAs($pdfOnly);
        $this->get('/m/exports/rapport/agents/pdf')->assertOk();
        $this->get('/m/exports/rapport/agents/xlsx')->assertForbidden();

        $this->actingAs($excelOnly);
        $this->get('/m/exports/rapport/agents/xlsx')->assertOk();
        $this->get('/m/exports/rapport/agents/pdf')->assertForbidden();
    }

    public function test_every_pdf_and_excel_generation_goes_through_the_gate_not_just_the_mobile_routes(): void
    {
        [, $none] = $this->tenantWith(['statistiques_essentielles']);
        $this->actingAs($none);

        // Filament, API, commandes : tout passe par les mêmes facades Pdf / Excel liées au conteneur.
        $this->assertThrows(fn () => Pdf::loadView('exports.transactions-pdf', []), HttpException::class);
        $this->assertThrows(fn () => Excel::download(new \App\Exports\AgentsExport(null, null), 'x.xlsx'), HttpException::class);
        $this->assertThrows(fn () => Excel::raw(new \App\Exports\AgentsExport(null, null), \Maatwebsite\Excel\Excel::XLSX), HttpException::class);
        // Documents transactionnels (reçus) : jamais bloqués.
        $this->assertNull(ExportGate::featureForPdfView('receipts.remboursement'));
    }

    public function test_exports_avances(): void
    {
        [, $basic] = $this->tenantWith(['exports_pdf', 'exports_excel']);
        [, $advanced] = $this->tenantWith(['exports_pdf', 'exports_excel', 'exports_avances']);

        foreach (['/m/exports/commissions/pdf', '/m/exports/commissions/xlsx', '/m/exports/periode/ventes/month/pdf', '/m/exports/caisse/historique'] as $url) {
            $this->actingAs($basic);
            $this->get($url)->assertForbidden();
            $this->actingAs($advanced);
            $this->assertContains($this->get($url)->getStatusCode(), [200, 302], $url);
        }
    }

    // ---------------------------------------------------------------- notifications

    public function test_notifications_dettes(): void
    {
        [$tWithout, $without] = $this->tenantWith(['exports_pdf']);
        [$tWith, $with] = $this->tenantWith(['notifications_dettes']);
        $agentWithout = TenantContext::runAs($tWithout->id, fn () => Agent::factory()->create());
        $agentWith = TenantContext::runAs($tWith->id, fn () => Agent::factory()->create());

        $this->assertSame([], (new DebtAlertNotification($agentWithout, 'warning'))->via($without));
        $this->assertSame([], (new PaymentReminderNotification($agentWithout))->via($agentWithout), 'rappel adressé à un agent du tenant');
        $this->assertSame([], (new \App\Notifications\DebtReminderNotification($agentWithout, 'J+1', 1000.0, 0.0, 1000.0, 'en retard'))->via($without));

        $this->assertNotEmpty((new DebtAlertNotification($agentWith, 'warning'))->via($with));
        $this->assertNotEmpty((new PaymentReminderNotification($agentWith))->via($agentWith));
    }

    public function test_notifications_push(): void
    {
        [$tWithout, $without] = $this->tenantWith(['notifications_dettes']);
        [$tWith, $with] = $this->tenantWith(['notifications_dettes', 'notifications_push']);
        $messaging = $this->mock(FirebaseMessaging::class);
        $messaging->shouldReceive('send')->once(); // une seule fois : pour le tenant qui a la fonctionnalité
        $notification = new class extends \Illuminate\Notifications\Notification implements \App\Contracts\PushNotification
        {
            public function pushPayload(): array
            {
                return ['title' => 't', 'body' => 'b', 'url' => '/'];
            }
        };
        foreach ([[$tWithout, $without], [$tWith, $with]] as [$tenant, $user]) {
            TenantContext::runAs($tenant->id, fn () => PushToken::create(['user_id' => $user->id, 'token' => 'tok-'.$user->id, 'platform' => 'android']));
        }

        $channel = app(FirebasePushChannel::class);
        TenantContext::runAs($tWithout->id, fn () => $channel->send($without, $notification));
        TenantContext::runAs($tWith->id, fn () => $channel->send($with, $notification));

        // Enregistrement du jeton côté mobile : refusé si l'offre n'inclut pas le push.
        $this->actingAs($without);
        $this->postJson('/m/push-token', ['token' => 'nouveau', 'platform' => 'android'])->assertForbidden();
        $this->actingAs($with);
        $this->postJson('/m/push-token', ['token' => 'nouveau', 'platform' => 'android'])->assertSuccessful();
    }

    // ---------------------------------------------------------------- rapports et sauvegardes

    public function test_rapports_avances(): void
    {
        [$tWithout] = $this->tenantWith(['exports_pdf']);
        [$tWith] = $this->tenantWith(['exports_pdf', 'rapports_avances']);

        TenantContext::runAs($tWithout->id, fn () => Artisan::call('uv:daily-report', ['--save' => true]));
        TenantContext::runAs($tWith->id, fn () => Artisan::call('uv:daily-report', ['--save' => true]));

        $this->assertSame([], Storage::disk('local')->files("tenants/{$tWithout->id}/reports"), 'aucun rapport pour un tenant sans la fonctionnalité');
        $this->assertCount(1, Storage::disk('local')->files("tenants/{$tWith->id}/reports"));

        $this->actingAs($this->userIn($tWithout));
        $this->assertThrows(fn () => Pdf::loadView('exports.daily-report-pdf', []), HttpException::class);
    }

    public function test_sauvegardes_dediees(): void
    {
        [$tWithout, $without] = $this->tenantWith(['exports_pdf']);
        [$tWith, $with] = $this->tenantWith(['sauvegardes_dediees']);
        [$tOther] = $this->tenantWith(['sauvegardes_dediees']);

        TenantContext::runAs($tWithout->id, fn () => Artisan::call('tenancy:backup-dedicated'));
        TenantContext::runAs($tWith->id, fn () => Artisan::call('tenancy:backup-dedicated'));
        TenantContext::runAs($tOther->id, fn () => Artisan::call('tenancy:backup-dedicated'));

        $this->assertSame([], Storage::disk('local')->files("tenants/{$tWithout->id}/backups"));
        $files = Storage::disk('local')->files("tenants/{$tWith->id}/backups");
        $this->assertCount(1, $files);
        $name = basename($files[0]);

        $this->actingAs($with);
        $this->get("/exports/mes-sauvegardes/{$name}")->assertOk();
        $this->get('/exports/mes-sauvegardes/../../x.zip')->assertNotFound();
        $this->get('/exports/mes-sauvegardes/backup-20200101-000000.zip')->assertNotFound();

        $this->actingAs($without);
        $this->get("/exports/mes-sauvegardes/{$name}")->assertForbidden();

        // Le fichier d'un autre tenant n'est atteignable ni par son nom, ni par le nôtre.
        $otherName = basename(Storage::disk('local')->files("tenants/{$tOther->id}/backups")[0]);
        $this->actingAs($with);
        $this->get($otherName === $name ? '/exports/mes-sauvegardes/backup-19990101-000000.zip' : "/exports/mes-sauvegardes/{$otherName}")->assertNotFound();
    }

    public function test_dedicated_backups_are_rotated(): void
    {
        [$tenant] = $this->tenantWith(['sauvegardes_dediees']);
        config(['subscriptions.dedicated_backups_keep' => 3]);

        foreach (range(1, 5) as $i) {
            $this->travelToDate("2026-10-1{$i} 03:30:00");
            TenantContext::runAs($tenant->id, fn () => Artisan::call('tenancy:backup-dedicated'));
        }

        $files = Storage::disk('local')->files("tenants/{$tenant->id}/backups");
        $this->assertCount(3, $files);
        $this->assertStringContainsString('20261015', implode(' ', $files), 'les plus récentes sont gardées');
        $this->assertStringNotContainsString('20261011', implode(' ', $files));
        \Illuminate\Support\Carbon::setTestNow();
    }

    // ---------------------------------------------------------------- stockage

    public function test_the_storage_limit_is_enforced_without_deleting_existing_files(): void
    {
        [$tenant, $user] = $this->tenantWith(['exports_pdf'], ['storage_limit_mb' => 1]);
        $disk = Storage::disk('local');
        $e = app(SubscriptionEntitlements::class);

        $disk->put("tenants/{$tenant->id}/charges/ancien.pdf", str_repeat('a', 600 * 1024));
        $this->assertTrue($e->canStore(100 * 1024, $tenant));
        $this->assertFalse($e->canStore(600 * 1024, $tenant));
        $this->assertThrows(fn () => $e->assertCanStore(600 * 1024, $tenant), SubscriptionLimitReached::class);

        // Un justificatif qui fait dépasser l'espace refuse la charge ET retire ce seul fichier.
        $disk->put("tenants/{$tenant->id}/charges/trop-gros.pdf", str_repeat('b', 600 * 1024));
        $category = TenantContext::runAs($tenant->id, fn () => ChargeCategorie::first() ?? ChargeCategorie::create(['code' => 'autres', 'name' => 'Autres']));

        $this->assertThrows(fn () => TenantContext::runAs($tenant->id, fn () => Charge::create([
            'category_id' => $category->id, 'libelle' => 'Facture', 'montant' => 1000, 'date_charge' => now()->toDateString(),
            'created_by' => $user->id, 'justificatif_path' => "tenants/{$tenant->id}/charges/trop-gros.pdf",
        ])), SubscriptionLimitReached::class);

        $this->assertFalse($disk->exists("tenants/{$tenant->id}/charges/trop-gros.pdf"), 'le fichier refusé est retiré');
        $this->assertTrue($disk->exists("tenants/{$tenant->id}/charges/ancien.pdf"), 'les fichiers existants ne sont jamais supprimés');
        $this->assertSame(0, Charge::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_unlimited_storage_never_refuses(): void
    {
        [$tenant] = $this->tenantWith(['exports_pdf'], ['storage_limit_mb' => null]);
        Storage::disk('local')->put("tenants/{$tenant->id}/charges/gros.pdf", str_repeat('a', 3 * 1024 * 1024));

        $this->assertTrue(app(SubscriptionEntitlements::class)->canStore(50 * 1024 * 1024, $tenant));
    }

    public function test_generated_reports_respect_the_storage_limit(): void
    {
        [$tenant] = $this->tenantWith(['exports_pdf', 'rapports_avances'], ['storage_limit_mb' => 1]);
        Storage::disk('local')->put("tenants/{$tenant->id}/charges/plein.pdf", str_repeat('a', 1024 * 1024));

        // Cron : une exécution par tenant ; l'échec de celui-ci est rapporté sans empêcher les autres.
        $status = Artisan::call('uv:daily-report', ['--save' => true]);

        $this->assertSame(1, $status, 'échec signalé');
        $this->assertStringContainsString('Espace de stockage', Artisan::output());
        $this->assertSame([], Storage::disk('local')->files("tenants/{$tenant->id}/reports"), 'pas de rapport enregistré au-delà de l\'espace');
        $this->assertTrue(Storage::disk('local')->exists("tenants/{$tenant->id}/charges/plein.pdf"), 'fichier existant intact');
    }
}
