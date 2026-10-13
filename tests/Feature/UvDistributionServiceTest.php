<?php

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Get a fresh instance of the service.
 */
function getService(): UvDistributionService
{
    return new UvDistributionService;
}

/**
 * Approvisionne le Solde UV Disponible pour que les ventes du scénario aient
 * de quoi être débitées (le solde n'est plus une valeur simulée : il doit
 * réellement exister avant toute distribution).
 */
function approvisionner(float $amount): void
{
    Approvisionnement::create([
        'user_id' => auth()->id() ?? User::factory()->create()->id,
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'amount' => $amount,
        'date_approvisionnement' => now(),
    ]);
}

describe('UvDistributionService', function () {

    describe('distribute()', function () {

        it('creates a UV transaction for an active agent', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 1000000,
            ]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);

            approvisionner(100000);

            $transaction = getService()->distribute(
                agent: $agent,
                amount: 50000
            );

            expect($transaction)->toBeInstanceOf(UvTransaction::class)
                ->and($transaction->agent_id)->toBe($agent->id)
                ->and((float) $transaction->amount)->toBe(50000.0)
                ->and($transaction->transaction_type)->toBe('distribution')
                ->and($transaction->reference)->not->toBeNull();
        });

        it('throws exception for inactive agent', function () {
            $agent = Agent::factory()->create([
                'status' => 'inactive',
            ]);

            getService()->distribute(
                agent: $agent,
                amount: 50000
            );
        })->throws(ValidationException::class);

        it('throws exception for suspended agent', function () {
            $agent = Agent::factory()->create([
                'status' => 'suspended',
            ]);

            getService()->distribute(
                agent: $agent,
                amount: 50000
            );
        })->throws(ValidationException::class);

        it('throws exception when amount exceeds debt limit', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 100000,
            ]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);

            approvisionner(1000000);

            getService()->distribute(
                agent: $agent,
                amount: 150000 // Exceeds 100000 limit
            );
        })->throws(ValidationException::class);

        it('throws exception when amount exceeds available UV balance', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 0,
            ]);

            approvisionner(10000); // Solde UV très insuffisant

            getService()->distribute(
                agent: $agent,
                amount: 50000
            );
        })->throws(ValidationException::class, 'Solde UV insuffisant pour effectuer cette vente.');

        it('allows distribution when no debt limit is set', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 0, // No limit
            ]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);

            approvisionner(10000000);

            $transaction = getService()->distribute(
                agent: $agent,
                amount: 5000000 // Large amount, no limit
            );

            expect($transaction)->toBeInstanceOf(UvTransaction::class);
        });

        it('considers existing debt when checking limit', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 100000,
            ]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 80000,
                'total_rembourse' => 0,
                'solde_restant' => 80000, // Already 80k debt
            ]);

            approvisionner(1000000);

            // Should fail: 80k + 30k = 110k > 100k limit
            getService()->distribute(
                agent: $agent,
                amount: 30000
            );
        })->throws(ValidationException::class);

        it('allows distribution within remaining credit', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 100000,
            ]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 80000,
                'total_rembourse' => 0,
                'solde_restant' => 80000,
            ]);

            approvisionner(1000000);

            // Should succeed: 80k + 20k = 100k = limit
            $transaction = getService()->distribute(
                agent: $agent,
                amount: 20000
            );

            expect($transaction)->toBeInstanceOf(UvTransaction::class);
        });

        it('associates fournisseur when provided', function () {
            $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
            $fournisseur = Fournisseur::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);

            approvisionner(100000);

            $transaction = getService()->distribute(
                agent: $agent,
                amount: 25000,
                fournisseur: $fournisseur
            );

            expect($transaction->fournisseur_id)->toBe($fournisseur->id);
        });

        it('records the UV balance before and after the sale on the transaction', function () {
            $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
            approvisionner(4000000);

            $transaction = getService()->distribute(agent: $agent, amount: 900000);

            expect((float) $transaction->solde_uv_avant)->toBe(4000000.0)
                ->and((float) $transaction->solde_uv_apres)->toBe(3100000.0);
        });
    });

    describe('cancel()', function () {

        it('recredits the UV balance when cancelling a sale', function () {
            $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
            approvisionner(5000000);

            $transaction = getService()->distribute(agent: $agent, amount: 1200000);
            expect(app(MasterSoldeService::class)->getAvailableBalance())->toBe(3800000.0);

            getService()->cancel($transaction);

            expect($transaction->fresh()->status)->toBe('cancelled')
                ->and(app(MasterSoldeService::class)->getAvailableBalance())->toBe(5000000.0);
        });
    });

    describe('canDistribute()', function () {

        it('returns true for active agent within limit', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 100000,
            ]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);

            expect(getService()->canDistribute($agent, 50000))->toBeTrue();
        });

        it('returns false for inactive agent', function () {
            $agent = Agent::factory()->create(['status' => 'inactive']);

            expect(getService()->canDistribute($agent, 50000))->toBeFalse();
        });

        it('returns false when amount exceeds available credit', function () {
            $agent = Agent::factory()->create([
                'status' => 'active',
                'debt_limit' => 100000,
            ]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 90000,
                'total_rembourse' => 0,
                'solde_restant' => 90000,
            ]);

            expect(getService()->canDistribute($agent, 20000))->toBeFalse();
        });
    });

    describe('getAvailableCredit()', function () {

        it('returns remaining credit correctly', function () {
            $agent = Agent::factory()->create(['debt_limit' => 100000]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 60000,
                'total_rembourse' => 0,
                'solde_restant' => 60000,
            ]);

            expect(getService()->getAvailableCredit($agent))->toBe(40000.0);
        });

        it('returns PHP_FLOAT_MAX when no limit', function () {
            $agent = Agent::factory()->create(['debt_limit' => 0]);

            expect(getService()->getAvailableCredit($agent))->toBe(PHP_FLOAT_MAX);
        });

        it('returns 0 when at limit', function () {
            $agent = Agent::factory()->create(['debt_limit' => 100000]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 100000,
                'total_rembourse' => 0,
                'solde_restant' => 100000,
            ]);

            expect(getService()->getAvailableCredit($agent))->toBe(0.0);
        });
    });

    describe('getAgentStats()', function () {

        it('returns complete statistics', function () {
            $agent = Agent::factory()->create(['debt_limit' => 100000]);

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 75000,
                'total_rembourse' => 25000,
                'solde_restant' => 50000,
            ]);

            $stats = getService()->getAgentStats($agent);

            expect($stats)
                ->toHaveKey('total_uv_distribues', 75000.0)
                ->toHaveKey('total_rembourse', 25000.0)
                ->toHaveKey('solde_restant', 50000.0)
                ->toHaveKey('debt_limit', 100000.0)
                ->toHaveKey('available_credit', 50000.0)
                ->toHaveKey('debt_ratio', 50.0)
                ->toHaveKey('is_at_limit', false);
        });
    });
});
