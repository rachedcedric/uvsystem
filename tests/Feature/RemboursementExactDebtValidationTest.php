<?php

namespace Tests\Feature;

use App\Filament\Pages\DettesPage;
use App\Filament\Resources\RemboursementResource\Pages\CreateRemboursement;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Régression : un remboursement dont le montant est EXACTEMENT égal au
 * reste à payer était refusé à tort ("... ne peut pas dépasser le reste à
 * payer.") lorsque l'agent arrivait présélectionné (bouton "Encaisser" sur
 * /master/dettes, ?agent_id=X). Cause : la règle de validation lisait un
 * champ caché (_current_debt) alimenté uniquement par afterStateUpdated(),
 * qui ne se déclenche jamais lors d'un remplissage programmatique du
 * formulaire (fillForm()) — seulement sur une interaction live réelle.
 * Corrigé en relisant Agent::current_debt en direct dans la règle.
 */
class RemboursementExactDebtValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Fournisseur $fournisseur;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'view_uv_transactions', 'create_uv_transactions',
            'view_remboursements', 'create_remboursements',
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

        // Dette de 1 000 000 FCFA, exactement le scénario du bug rapporté.
        $this->agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($this->agent, 1_000_000, $this->fournisseur);
        $this->agent->recalculateDebt();
    }

    /**
     * Reproduit exactement le parcours du bug : arrivée via le bouton
     * "Encaisser" (agent présélectionné par ?agent_id=X, sans passer par un
     * afterStateUpdated live), puis montant = reste à payer exact.
     */
    public function test_exact_debt_amount_via_preselected_agent_is_accepted(): void
    {
        $component = Livewire::withQueryParams(['agent_id' => $this->agent->id])
            ->test(CreateRemboursement::class);

        $this->assertSame($this->agent->id, $component->get('data.agent_id'));

        $component->set('data.amount', '1 000 000')
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now());

        $component->call('create')->assertHasNoFormErrors();

        $this->assertSame(1, Remboursement::where('agent_id', $this->agent->id)->count());
        $this->assertEqualsWithDelta(0, $this->agent->fresh()->current_debt, 0.01);
    }

    /**
     * @dataProvider amountScenarios
     */
    public function test_amount_scenarios_against_a_million_debt(string $amountInput, bool $shouldBeAccepted): void
    {
        $component = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agent->id)
            ->set('data.amount', $amountInput)
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now());

        $component->call('create');

        if ($shouldBeAccepted) {
            $component->assertHasNoFormErrors();
            $this->assertSame(1, Remboursement::where('agent_id', $this->agent->id)->count());
        } else {
            $component->assertHasFormErrors(['amount']);
            $this->assertSame(0, Remboursement::where('agent_id', $this->agent->id)->count());
        }
    }

    public static function amountScenarios(): array
    {
        return [
            '1 FCFA' => ['1', true],
            '100 000 FCFA' => ['100000', true],
            '100 000 FCFA (avec espaces)' => ['100 000', true],
            '999 999 FCFA' => ['999999', true],
            '1 000 000 FCFA (remboursement intégral)' => ['1000000', true],
            '1 000 000 FCFA avec espaces (remboursement intégral)' => ['1 000 000', true],
            '1 000 001 FCFA (dépasse d\'1 FCFA)' => ['1000001', false],
            '1 000 001 FCFA avec espaces' => ['1 000 001', false],
            '1 500 000 FCFA' => ['1500000', false],
            '1 500 000 FCFA avec espaces' => ['1 500 000', false],
        ];
    }

    /** Après un remboursement intégral : "Nouvelle dette après remboursement" affiche 0 FCFA */
    public function test_full_repayment_shows_zero_remaining_debt_before_submit(): void
    {
        $html = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agent->id)
            ->set('data.amount', '1 000 000')
            ->html();

        $this->assertStringContainsString('0 FCFA', $html);
    }

    /**
     * Après validation d'un remboursement intégral : dette soldée, disparaît
     * des dettes actives, badge du menu mis à jour, plus aucun rappel
     * automatique ne sera généré pour cet agent (même source de vérité,
     * Agent::active()->withDebt()).
     */
    public function test_full_repayment_settles_the_debt_everywhere(): void
    {
        Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agent->id)
            ->set('data.amount', '1 000 000')
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->agent->refresh();

        $this->assertEqualsWithDelta(0, $this->agent->current_debt, 0.01);
        $this->assertSame('Soldée', DettesPage::statusLabel($this->agent->load('solde')));
        $this->assertSame(0, DettesPage::agentsEndettesQuery()->count());
        $this->assertNull(DettesPage::getNavigationBadge());
    }

    /** Un remboursement partiel calcule correctement le nouveau reste dû */
    public function test_partial_repayment_computes_correct_remaining_debt(): void
    {
        Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agent->id)
            ->set('data.amount', '400 000')
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(600000, $this->agent->fresh()->current_debt, 0.01);
    }

    /** Un montant dépassant le reste dû, saisi avec espaces, ne fausse jamais la comparaison */
    public function test_amount_exceeding_debt_with_spaces_is_rejected_with_exact_message(): void
    {
        $component = Livewire::test(CreateRemboursement::class)
            ->set('data.agent_id', $this->agent->id)
            ->set('data.amount', '1 000 001')
            ->set('data.payment_method', 'cash')
            ->set('data.payment_date', now());

        $component->call('create');

        $errors = $component->instance()->getErrorBag()->get('data.amount');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString(
            'Le montant du remboursement ne peut pas dépasser le reste à payer.',
            $errors[0]
        );
        $this->assertEqualsWithDelta(1000000, $this->agent->fresh()->current_debt, 0.01);
    }
}
