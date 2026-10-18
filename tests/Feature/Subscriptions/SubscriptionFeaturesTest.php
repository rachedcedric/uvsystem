<?php

namespace Tests\Feature\Subscriptions;

use App\Contracts\PushNotification;
use App\Enums\SubscriptionCycle;
use App\Exceptions\SubscriptionLimitReached;
use App\Exports\AgentsExport;
use App\Filament\Pages\AgentScoringPage;
use App\Filament\Pages\RapportStatistiques;
use App\Filament\Pages\StatisticsPage;
use App\Livewire\Mobile\Analytics\Scoring;
use App\Livewire\Mobile\Analytics\Statistics;
use App\Models\Agent;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\PushToken;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\DebtAlertNotification;
use App\Notifications\DebtReminderNotification;
use App\Notifications\PaymentReminderNotification;
use App\Services\FirebaseMessaging;
use App\Services\Subscriptions\FeatureCatalog;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Services\Subscriptions\SubscriptionManager;
use App\Support\Subscriptions\ExportGate;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
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
        'statistiques_avancees' => 'test_statistiques_avancees',
        'exports_pdf' => 'test_exports_pdf_and_excel',
        'exports_excel' => 'test_exports_pdf_and_excel',
        'exports_avances' => 'test_exports_avances',
        'notifications_dettes' => 'test_notifications_dettes',
        'notifications_push' => 'test_notifications_push',
        'import_donnees' => 'SubscriptionTenantAccessTest::test_the_mobile_import_depends_on_the_plan_features',
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

    public function test_statistics_and_scoring_are_open_to_every_master_whatever_the_plan(): void
    {
        $this->assertSame(['statistiques_essentielles', 'scoring_agents'], FeatureCatalog::openFeatures());
        $this->assertNotContains('scoring_agents', FeatureCatalog::enforcedFeatures());

        // Offre sans ces fonctionnalités, puis tenant sans aucun abonnement : les pages restent ouvertes au master.
        [, $withoutFeatures] = $this->tenantWith(['exports_pdf']);
        $noSubscription = $this->userIn($this->tenant());

        foreach ([$withoutFeatures, $noSubscription] as $master) {
            $this->actingAs($master);
            Livewire::test(Statistics::class)->assertSuccessful();
            Livewire::test(Scoring::class)->assertSuccessful();
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Livewire::test(StatisticsPage::class)->assertSuccessful();
            Livewire::test(AgentScoringPage::class)->assertSuccessful();
        }
    }

    public function test_statistiques_avancees(): void
    {
        [, $without] = $this->tenantWith(['statistiques_essentielles']);
        [, $with] = $this->tenantWith(['statistiques_avancees']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($without);
        Livewire::test(RapportStatistiques::class)->assertForbidden();

        $this->actingAs($with);
        Livewire::test(RapportStatistiques::class)->assertSuccessful();
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
        $this->assertThrows(fn () => Excel::download(new AgentsExport(null), 'x.xlsx'), HttpException::class);
        $this->assertThrows(fn () => Excel::raw(new AgentsExport(null), \Maatwebsite\Excel\Excel::XLSX), HttpException::class);
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
        $this->assertSame([], (new DebtReminderNotification($agentWithout, 'J+1', 1000.0, 0.0, 1000.0, 'en retard'))->via($without));

        $this->assertNotEmpty((new DebtAlertNotification($agentWith, 'warning'))->via($with));
        $this->assertNotEmpty((new PaymentReminderNotification($agentWith))->via($agentWith));
    }

    public function test_notifications_push(): void
    {
        [$tWithout, $without] = $this->tenantWith(['notifications_dettes']);
        [$tWith, $with] = $this->tenantWith(['notifications_dettes', 'notifications_push']);
        $messaging = $this->mock(FirebaseMessaging::class);
        $messaging->shouldReceive('send')->once(); // une seule fois : pour le tenant qui a la fonctionnalité
        $notification = new class extends Notification implements PushNotification
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
        Carbon::setTestNow();
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
