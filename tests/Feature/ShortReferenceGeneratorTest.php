<?php

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\RemboursementService;
use App\Services\ShortReferenceGenerator;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function referenceGenerator(): ShortReferenceGenerator
{
    return app(ShortReferenceGenerator::class);
}

describe('ShortReferenceGenerator::generate', function () {
    it('produces an 8-character reference in the expected format for each prefix', function (string $prefix, string $table) {
        $reference = referenceGenerator()->generate($prefix, $table);

        expect(strlen($reference))->toBe(8)
            ->and($reference)->toMatch('/^'.$prefix.'-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
    })->with([
        ['V', 'uv_transactions'],
        ['R', 'remboursements'],
        ['A', 'approvisionnements'],
    ]);

    it('never uses the confusing characters 0, O, 1, I, L', function () {
        $code = substr(referenceGenerator()->generate('V', 'uv_transactions'), 2);

        foreach (['0', 'O', '1', 'I', 'L'] as $forbidden) {
            expect(str_contains($code, $forbidden))->toBeFalse();
        }
    });

    it('never collides with references already persisted in the table', function () {
        $agentId = Agent::factory()->create()->id;
        $generated = [];

        for ($i = 0; $i < 50; $i++) {
            $reference = referenceGenerator()->generate('V', 'uv_transactions');

            expect($generated)->not->toContain($reference);
            $generated[] = $reference;

            DB::table('uv_transactions')->insert([
                'tenant_id' => 1,
                'agent_id' => $agentId,
                'amount' => 0,
                'transaction_type' => 'distribution',
                'reference' => $reference,
                'transaction_date' => now(),
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(DB::table('uv_transactions')->count())->toBe(50);
    });

    it('coexists with an old-format reference without colliding or breaking search', function () {
        DB::table('uv_transactions')->insert([
            'tenant_id' => 1,
            'agent_id' => Agent::factory()->create()->id,
            'amount' => 0,
            'transaction_type' => 'distribution',
            'reference' => 'UV-'.strtoupper(uniqid()),
            'transaction_date' => now(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reference = referenceGenerator()->generate('V', 'uv_transactions');

        expect($reference)->toMatch('/^V-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
    });
});

describe('Model wiring — new records get the short reference format', function () {
    it('assigns a V-prefixed reference to a new UvTransaction', function () {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        AgentSolde::firstOrCreate(['agent_id' => $agent->id], ['total_uv_distribues' => 0, 'total_rembourse' => 0, 'solde_restant' => 0]);
        Approvisionnement::create([
            'user_id' => User::factory()->create()->id,
            'fournisseur_id' => Fournisseur::factory()->create()->id,
            'amount' => 1_000_000,
            'date_approvisionnement' => now(),
        ]);

        $transaction = (new UvDistributionService)->distribute(agent: $agent, amount: 100_000);

        expect($transaction->reference)->toMatch('/^V-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
    });

    it('assigns an R-prefixed reference to a new Remboursement', function () {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        AgentSolde::firstOrCreate(['agent_id' => $agent->id], ['total_uv_distribues' => 500_000, 'total_rembourse' => 0, 'solde_restant' => 500_000]);
        $agent->forceFill(['current_debt' => 500_000])->saveQuietly();

        $remboursement = (new RemboursementService)->process(agent: $agent, amount: 100_000);

        expect($remboursement->reference)->toMatch('/^R-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
    });

    it('assigns an A-prefixed reference to a new Approvisionnement', function () {
        $approvisionnement = Approvisionnement::create([
            'user_id' => User::factory()->create()->id,
            'fournisseur_id' => Fournisseur::factory()->create()->id,
            'amount' => 1_000_000,
            'date_approvisionnement' => now(),
        ]);

        expect($approvisionnement->reference)->toMatch('/^A-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
    });

    it('never regenerates an existing (old-format) reference', function () {
        $transaction = UvTransaction::create([
            'agent_id' => Agent::factory()->create()->id,
            'amount' => 100_000,
            'transaction_type' => 'distribution',
            'reference' => 'UV-LEGACY123',
            'transaction_date' => now(),
            'status' => 'completed',
        ]);

        expect($transaction->reference)->toBe('UV-LEGACY123');
    });
});
