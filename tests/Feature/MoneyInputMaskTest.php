<?php

namespace Tests\Feature;

use App\Filament\Resources\AgentResource\Pages\EditAgent;
use App\Filament\Resources\RemboursementResource\Pages\CreateRemboursement;
use App\Filament\Resources\UvTransactionResource\Pages\CreateUvTransaction;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bout en bout : un montant saisi/collé AVEC les espaces de formatage
 * ("1 000 000") doit être validé correctement, ne jamais fausser un calcul
 * réactif en cours de frappe, et arriver en base comme un nombre propre
 * (1000000, jamais la chaîne "1 000 000").
 */
class MoneyInputMaskTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'view_uv_transactions', 'create_uv_transactions',
            'view_remboursements', 'create_remboursements',
            'view_agents', 'edit_agents',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $this->user = User::factory()->create();
        $this->user->assignRole('master');
        $this->actingAs($this->user);

        $this->fournisseur = Fournisseur::factory()->create();

        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => $this->fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);
    }

    /** Une vente UV saisie avec espaces ("1 000 000") est enregistrée comme 1000000, pas comme texte */
    public function test_uv_transaction_amount_with_spaces_is_stored_as_clean_number(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

        Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $agent->id)
            ->set('data.transaction_type', 'distribution')
            ->set('data.amount', '1 000 000')
            ->set('data.transaction_date', now())
            ->call('create')
            ->assertHasNoFormErrors();

        $transaction = UvTransaction::where('agent_id', $agent->id)->first();

        $this->assertNotNull($transaction);
        $this->assertSame(1000000.0, (float) $transaction->amount);
        // La valeur brute en base ne doit jamais contenir l'espace de formatage.
        $this->assertStringNotContainsString(' ', (string) $transaction->getRawOriginal('amount'));
    }

    /** Un montant collé sans espaces ("1500000") fonctionne aussi */
    public function test_uv_transaction_amount_without_spaces_still_works(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

        Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $agent->id)
            ->set('data.transaction_type', 'distribution')
            ->set('data.amount', '1500000')
            ->set('data.transaction_date', now())
            ->call('create')
            ->assertHasNoFormErrors();

        $transaction = UvTransaction::where('agent_id', $agent->id)->first();
        $this->assertEqualsWithDelta(1500000.0, (float) $transaction->amount, 0.01);
    }

    /** Remboursement : un montant espacé égal exactement au reste dû est accepté */
    public function test_remboursement_amount_with_spaces_equal_to_debt_is_accepted(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($agent, 250_000, $this->fournisseur);
        $agent->recalculateDebt();

        Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $agent->id)
            ->set('data.amount', '250 000')
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now())
            ->call('create')
            ->assertHasNoFormErrors();

        $remb = Remboursement::where('agent_id', $agent->id)->first();
        $this->assertNotNull($remb);
        $this->assertEqualsWithDelta(250000.0, (float) $remb->amount, 0.01);
        $this->assertEqualsWithDelta(0, $agent->fresh()->current_debt, 0.01);
    }

    /**
     * Un montant espacé qui dépasse le reste dû doit être bloqué avec le
     * message exact — c'est précisément le calcul que les espaces de
     * formatage ne doivent jamais fausser.
     */
    public function test_remboursement_amount_with_spaces_exceeding_debt_is_blocked(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($agent, 200_000, $this->fournisseur);
        $agent->recalculateDebt();

        $component = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $agent->id)
            ->set('data.amount', '300 000') // > 200 000 restant dû
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now());

        $component->call('create');

        $component->assertHasFormErrors(['amount']);
        $this->assertSame(0, Remboursement::where('agent_id', $agent->id)->count());
        $this->assertEqualsWithDelta(200000.0, $agent->fresh()->current_debt, 0.01);
    }

    /**
     * Pendant la frappe, le calcul réactif "Nouvelle dette après remboursement"
     * doit rester correct même avec un montant contenant des espaces (pas de
     * troncature au premier espace).
     */
    public function test_reactive_remaining_debt_calculation_is_not_corrupted_by_spaces(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($agent, 1_000_000, $this->fournisseur);
        $agent->recalculateDebt();

        $html = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $agent->id)
            ->set('data.amount', '250 000')
            ->html();

        // Reste après remboursement = 1 000 000 - 250 000 = 750 000. Une
        // troncature au premier espace ((float) "250 000" === 250.0)
        // afficherait 999 750 au lieu de 750 000.
        $this->assertStringContainsString('750 000', $html);
        $this->assertStringNotContainsString('999 750', $html);
    }

    /** Un montant collé avec virgules ("1,250,000") est aussi nettoyé correctement */
    public function test_amount_with_commas_is_cleaned_correctly(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

        Livewire::test(CreateUvTransaction::class)
            ->set('data.agent_id', $agent->id)
            ->set('data.transaction_type', 'distribution')
            ->set('data.amount', '1,250,000')
            ->set('data.transaction_date', now())
            ->call('create')
            ->assertHasNoFormErrors();

        $transaction = UvTransaction::where('agent_id', $agent->id)->first();
        $this->assertEqualsWithDelta(1250000.0, (float) $transaction->amount, 0.01);
    }

    /** Le plafond de dette d'un agent (autre champ FCFA) est aussi nettoyé à l'enregistrement */
    public function test_agent_debt_limit_with_spaces_is_stored_clean(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 500000]);

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])
            ->set('data.debt_limit', '2 500 000')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(2500000.0, (float) $agent->fresh()->debt_limit, 0.01);
    }
}
