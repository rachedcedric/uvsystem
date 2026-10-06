<?php

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\ApprovisionnementService;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function soldeSuiviAgent(): Agent
{
    $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

    AgentSolde::create([
        'agent_id' => $agent->id,
        'total_uv_distribues' => 0,
        'total_rembourse' => 0,
        'solde_restant' => 0,
    ]);

    return $agent;
}

function soldeSuiviApprovisionner(float $amount): Approvisionnement
{
    return app(ApprovisionnementService::class)->create([
        'user_id' => User::factory()->create()->id,
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'amount' => $amount,
        'date_approvisionnement' => now(),
    ]);
}

describe('balance_after consistency', function () {

    it('records balance_after = balance_before - amount for a sale', function () {
        $agent = soldeSuiviAgent();
        soldeSuiviApprovisionner(5000000);

        $vente = (new UvDistributionService)->distribute(agent: $agent, amount: 1500000);

        expect((float) $vente->solde_uv_avant)->toBe(5000000.0)
            ->and((float) $vente->solde_uv_apres)->toBe(3500000.0)
            ->and((float) $vente->solde_uv_apres)->toBe((float) $vente->solde_uv_avant - (float) $vente->amount);
    });

    it('records balance_after = balance_before + amount for an approvisionnement', function () {
        soldeSuiviApprovisionner(5000000);
        $appro = soldeSuiviApprovisionner(2000000);

        expect((float) $appro->solde_uv_avant)->toBe(5000000.0)
            ->and((float) $appro->solde_uv_apres)->toBe(7000000.0)
            ->and((float) $appro->solde_uv_apres)->toBe((float) $appro->solde_uv_avant + (float) $appro->amount);
    });

    it('keeps each historical transaction\'s stored balance exact across a chain of successive operations', function () {
        $agent = soldeSuiviAgent();

        // Solde initial : 5 000 000
        $initial = soldeSuiviApprovisionner(5000000);
        expect((float) $initial->solde_uv_apres)->toBe(5000000.0);

        // Approvisionnement +2 000 000 -> Solde Après : 7 000 000
        $appro = soldeSuiviApprovisionner(2000000);
        expect((float) $appro->solde_uv_avant)->toBe(5000000.0)
            ->and((float) $appro->solde_uv_apres)->toBe(7000000.0);

        // Vente -1 500 000 -> Solde Après : 5 500 000
        $vente1 = (new UvDistributionService)->distribute(agent: $agent, amount: 1500000);
        expect((float) $vente1->solde_uv_avant)->toBe(7000000.0)
            ->and((float) $vente1->solde_uv_apres)->toBe(5500000.0);

        // Vente -500 000 -> Solde Après : 5 000 000
        $vente2 = (new UvDistributionService)->distribute(agent: $agent->fresh(), amount: 500000);
        expect((float) $vente2->solde_uv_avant)->toBe(5500000.0)
            ->and((float) $vente2->solde_uv_apres)->toBe(5000000.0);

        // L'historique conserve exactement ces valeurs, même après coup.
        expect((float) $initial->fresh()->solde_uv_apres)->toBe(5000000.0)
            ->and((float) $appro->fresh()->solde_uv_apres)->toBe(7000000.0)
            ->and((float) $vente1->fresh()->solde_uv_apres)->toBe(5500000.0)
            ->and((float) $vente2->fresh()->solde_uv_apres)->toBe(5000000.0);

        // Et correspond au solde réel final.
        expect(app(MasterSoldeService::class)->getAvailableBalance())->toBe(5000000.0);
    });

    it('does not retroactively change an old transaction\'s recorded balance when later transactions occur', function () {
        $agent = soldeSuiviAgent();
        $appro = soldeSuiviApprovisionner(1000000);
        $soldeApresOriginal = (float) $appro->solde_uv_apres;

        (new UvDistributionService)->distribute(agent: $agent, amount: 200000);
        soldeSuiviApprovisionner(3000000);
        (new UvDistributionService)->distribute(agent: $agent->fresh(), amount: 700000);

        expect((float) $appro->fresh()->solde_uv_apres)->toBe($soldeApresOriginal);
    });
});

describe('master-solde:backfill', function () {

    it('reconstructs balance_before/after for old rows created before the tracking existed', function () {
        // Simule d'anciennes lignes créées avant l'ajout du suivi de solde
        // (insertion directe, sans passer par les services/observers qui
        // renseignent solde_uv_avant/apres).
        $fournisseur = Fournisseur::factory()->create();
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['status' => 'active']);

        DB::table('approvisionnements')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 5000000,
            'reference' => 'APP-OLD-1',
            'date_approvisionnement' => '2026-01-01',
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'solde_uv_avant' => null,
            'solde_uv_apres' => null,
        ]);

        DB::table('uv_transactions')->insert([
            'tenant_id' => 1,
            'agent_id' => $agent->id,
            'amount' => 2000000,
            'transaction_type' => 'distribution',
            'reference' => 'UV-OLD-1',
            'transaction_date' => '2026-01-01 09:00:00',
            'status' => 'completed',
            'created_at' => '2026-01-01 09:00:00',
            'updated_at' => '2026-01-01 09:00:00',
            'solde_uv_avant' => null,
            'solde_uv_apres' => null,
        ]);

        $this->artisan('master-solde:backfill')->assertSuccessful();

        $appro = DB::table('approvisionnements')->where('reference', 'APP-OLD-1')->first();
        $vente = DB::table('uv_transactions')->where('reference', 'UV-OLD-1')->first();

        expect((float) $appro->solde_uv_avant)->toBe(0.0)
            ->and((float) $appro->solde_uv_apres)->toBe(5000000.0)
            ->and((float) $vente->solde_uv_avant)->toBe(5000000.0)
            ->and((float) $vente->solde_uv_apres)->toBe(3000000.0);
    });

    it('never overwrites an already-populated row unless --force is passed', function () {
        $fournisseur = Fournisseur::factory()->create();
        $user = User::factory()->create();

        DB::table('approvisionnements')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 1000000,
            'reference' => 'APP-MANUAL',
            'date_approvisionnement' => '2026-01-01',
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'solde_uv_avant' => 999999,
            'solde_uv_apres' => 1999999,
        ]);

        $this->artisan('master-solde:backfill')->assertSuccessful();

        $appro = DB::table('approvisionnements')->where('reference', 'APP-MANUAL')->first();

        expect((float) $appro->solde_uv_avant)->toBe(999999.0)
            ->and((float) $appro->solde_uv_apres)->toBe(1999999.0);
    });

    it('never writes a possibly-wrong balance when the reconciliation check fails', function () {
        DB::table('approvisionnements')->insert([
            'tenant_id' => 1,
            'user_id' => User::factory()->create()->id,
            'fournisseur_id' => Fournisseur::factory()->create()->id,
            'amount' => 1000000,
            'reference' => 'APP-CHECK',
            'date_approvisionnement' => '2026-01-01',
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'solde_uv_avant' => null,
            'solde_uv_apres' => null,
        ]);

        $this->mock(MasterSoldeService::class, function ($mock) {
            $mock->shouldReceive('getAvailableBalance')->andReturn(999999999.0);
        });

        $this->artisan('master-solde:backfill')->assertFailed();

        $appro = DB::table('approvisionnements')->where('reference', 'APP-CHECK')->first();

        expect($appro->solde_uv_avant)->toBeNull()
            ->and($appro->solde_uv_apres)->toBeNull();
    });
});
