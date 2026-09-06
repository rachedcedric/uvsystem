<?php

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Remboursement;
use App\Services\RemboursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new RemboursementService;
});

describe('RemboursementService', function () {

    describe('process()', function () {

        it('creates a remboursement successfully', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 100000,
                'total_rembourse' => 0,
                'solde_restant' => 100000,
            ]);

            $remboursement = $this->service->process(
                agent: $agent,
                amount: 50000,
                paymentMethod: 'cash'
            );

            expect($remboursement)->toBeInstanceOf(Remboursement::class)
                ->and($remboursement->agent_id)->toBe($agent->id)
                ->and((float) $remboursement->amount)->toBe(50000.0)
                ->and($remboursement->payment_method)->toBe('cash')
                ->and($remboursement->reference)->not->toBeNull();
        });

        it('throws exception when amount is zero', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 100000,
                'total_rembourse' => 0,
                'solde_restant' => 100000,
            ]);

            $this->service->process(
                agent: $agent,
                amount: 0
            );
        })->throws(ValidationException::class);

        it('throws exception when amount is negative', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 100000,
                'total_rembourse' => 0,
                'solde_restant' => 100000,
            ]);

            $this->service->process(
                agent: $agent,
                amount: -5000
            );
        })->throws(ValidationException::class);

        it('throws exception when amount exceeds current debt', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 50000,
                'total_rembourse' => 0,
                'solde_restant' => 50000,
            ]);

            $this->service->process(
                agent: $agent,
                amount: 75000 // Exceeds 50000 debt
            );
        })->throws(ValidationException::class);

        it('allows exact debt amount repayment', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 50000,
                'total_rembourse' => 0,
                'solde_restant' => 50000,
            ]);

            $remboursement = $this->service->process(
                agent: $agent,
                amount: 50000
            );

            expect($remboursement)->toBeInstanceOf(Remboursement::class)
                ->and((float) $remboursement->amount)->toBe(50000.0);
        });

        it('supports different payment methods', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 100000,
                'total_rembourse' => 0,
                'solde_restant' => 100000,
            ]);

            $methods = ['cash', 'mobile_money', 'bank_transfer', 'cheque'];

            foreach ($methods as $method) {
                $remboursement = $this->service->process(
                    agent: $agent,
                    amount: 10000,
                    paymentMethod: $method
                );

                expect($remboursement->payment_method)->toBe($method);

                // Update solde for next iteration
                $agent->solde->update(['solde_restant' => $agent->solde->solde_restant - 10000]);
            }
        });

        it('stores notes when provided', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 100000,
                'total_rembourse' => 0,
                'solde_restant' => 100000,
            ]);

            $remboursement = $this->service->process(
                agent: $agent,
                amount: 25000,
                paymentMethod: 'cash',
                notes: 'Paiement partiel mensuel'
            );

            expect($remboursement->notes)->toBe('Paiement partiel mensuel');
        });
    });

    describe('fullRepayment()', function () {

        it('repays entire debt', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 75000,
                'total_rembourse' => 0,
                'solde_restant' => 75000,
            ]);

            $remboursement = $this->service->fullRepayment($agent);

            expect($remboursement)->toBeInstanceOf(Remboursement::class)
                ->and((float) $remboursement->amount)->toBe(75000.0);
        });

        it('returns null when no debt exists', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);

            $result = $this->service->fullRepayment($agent);

            expect($result)->toBeNull();
        });
    });

    describe('hasDebt()', function () {

        it('returns true when agent has debt', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 50000,
                'total_rembourse' => 0,
                'solde_restant' => 50000,
            ]);

            expect($this->service->hasDebt($agent))->toBeTrue();
        });

        it('returns false when agent has no debt', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 50000,
                'total_rembourse' => 50000,
                'solde_restant' => 0,
            ]);

            expect($this->service->hasDebt($agent))->toBeFalse();
        });
    });

    describe('getCurrentDebt()', function () {

        it('returns current debt amount', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 80000,
                'total_rembourse' => 30000,
                'solde_restant' => 50000,
            ]);

            expect($this->service->getCurrentDebt($agent))->toBe(50000.0);
        });

        it('returns 0 when no solde exists', function () {
            $agent = Agent::factory()->create();

            expect($this->service->getCurrentDebt($agent))->toBe(0.0);
        });
    });

    describe('getStats()', function () {

        it('returns repayment statistics', function () {
            $agent = Agent::factory()->create();

            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 100000,
                'total_rembourse' => 40000,
                'solde_restant' => 60000,
            ]);

            // Create some remboursements
            Remboursement::factory()->count(3)->create([
                'agent_id' => $agent->id,
                'amount' => 10000,
                'payment_method' => 'cash',
            ]);

            $stats = $this->service->getStats($agent);

            expect($stats)
                ->toHaveKey('total_rembourse')
                ->toHaveKey('current_debt')
                ->toHaveKey('remboursements_count')
                ->toHaveKey('average_remboursement');
        });
    });
});
