<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Commission;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use App\Support\Mobile\Crud\Registry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parcours de tous les écrans mobiles avec les vraies permissions du seeder
 * (rôle master) : aucun écran ne doit planter, avec ou sans données.
 */
class MobileSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mobile_screen_loads_for_a_master(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $master = User::factory()->create();
        $master->assignRole('master');
        $this->actingAs($master);

        PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'is_active' => true, 'sort_order' => 1]);
        $fournisseur = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true]);
        Approvisionnement::create(['user_id' => $master->id, 'fournisseur_id' => $fournisseur->id, 'amount' => 3000000, 'date_approvisionnement' => now()]);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 1000000, 'phone' => '0701010101']);
        $sale = (new UvDistributionService)->distribute($agent->fresh(), 200000);
        $payment = app(RemboursementService::class)->process($agent->fresh(), 50000);
        $cat = ChargeCategorie::create(['code' => 'loyer', 'name' => 'Loyer', 'is_active' => true]);
        $charge = Charge::create(['category_id' => $cat->id, 'libelle' => 'Loyer', 'montant' => 90000, 'date_charge' => today(), 'created_by' => $master->id]);
        $mouvement = app(CaisseService::class)->enregistrer('entree', 40000, 'Apport');
        $commission = Commission::create(['is_global' => true, 'date_commission' => today(), 'volume_total_jour' => 1200000, 'commission_globale' => 6360, 'montant_vente' => 1200000, 'montant_commission' => 6360, 'nombre_transactions' => 3, 'seuil_min' => 1000000, 'seuil_max' => 1499999]);
        $day = app(CaissePeriodResolver::class)->currentDate()->toDateString();
        activity()->causedBy($master)->log('Test');
        $activity = Activity::latest('id')->first();

        $urls = [
            route('m.home'), route('m.more'), route('m.notifications'),
            route('m.ventes'), route('m.ventes.create'), route('m.ventes.show', $sale),
            route('m.remboursements'), route('m.remboursements.create'), route('m.remboursements.show', $payment),
            route('m.agents'), route('m.agents.show', $agent),
            route('m.caisse'), route('m.caisse.create'), route('m.caisse.jour', $day),
            route('m.approvisionnements'), route('m.approvisionnements.create'),
            route('m.charges'), route('m.charges.create'), route('m.charges.show', $charge),
            route('m.commissions'), route('m.commissions.show', $commission),
            route('m.dettes'), route('m.dettes', ['v' => 'fournisseurs']), route('m.dettes.agent', $agent),
            route('m.dettes.fournisseur', $fournisseur), route('m.remboursements-fournisseurs'),
            route('m.statistiques'), route('m.statistiques', ['p' => 'year']), route('m.rapports'),
            route('m.activite'), route('m.activite.show', $activity),
            route('m.import'), route('m.securite'),
            route('m.crud.show', ['approvisionnements', Approvisionnement::first()->id]),
            route('m.crud.show', ['mouvements-caisse', $mouvement->id]),
            route('m.crud.edit', ['ventes', $sale->id]), route('m.crud.edit', ['remboursements', $payment->id]),
            route('m.crud.edit', ['charges', $charge->id]), route('m.crud.edit', ['agents', $agent->id]),
            route('m.crud.edit', ['approvisionnements', Approvisionnement::first()->id]), route('m.crud.edit', ['mouvements-caisse', $mouvement->id]),
        ];

        foreach (Registry::all() as $resource) {
            if ($resource::hidden() || $resource::slug() === 'agents') {
                continue;
            }
            $urls[] = route('m.crud.index', $resource::slug());
            $urls[] = route('m.crud.create', $resource::slug());
        }

        foreach ($urls as $url) {
            $response = $this->get($url);
            $this->assertTrue($response->isOk(), "{$url} → HTTP {$response->getStatusCode()}");
        }

        $this->assertGreaterThan(55, count($urls));
    }

    public function test_every_mobile_screen_loads_without_any_data(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $master = User::factory()->create();
        $master->assignRole('master');
        $this->actingAs($master);

        foreach (['m.home', 'm.more', 'm.notifications', 'm.ventes', 'm.remboursements', 'm.agents', 'm.caisse', 'm.approvisionnements', 'm.charges',
            'm.commissions', 'm.dettes', 'm.remboursements-fournisseurs', 'm.statistiques', 'm.rapports', 'm.activite', 'm.import', 'm.securite'] as $name) {
            $response = $this->get(route($name));
            $this->assertTrue($response->isOk(), "{$name} → HTTP {$response->getStatusCode()}");
        }
    }
}
