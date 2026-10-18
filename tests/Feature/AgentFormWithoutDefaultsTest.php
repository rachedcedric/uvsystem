<?php

namespace Tests\Feature;

use App\Filament\Resources\AgentResource\Pages\CreateAgent;
use App\Filament\Resources\AgentResource\Pages\EditAgent;
use App\Livewire\Mobile\Crud;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\Mobile\DataImporter;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel;
use Tests\TestCase;

/**
 * Quand un master enregistre un agent, le plafond de dette et le taux de commission ne portent AUCUNE valeur imposée
 * (avant : 500 000 FCFA et 5 %). Laissés vides, ils sont enregistrés vides (null) ; renseignés, ils sont respectés tels quels.
 */
class AgentFormWithoutDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);

        $this->tenant = Tenant::create(['name' => 'Société A']);
        $this->master = TenantContext::runAs($this->tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });

        $this->actingAs($this->master);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function agentNamed(string $name): Agent
    {
        return TenantContext::runAs($this->tenant->id, fn () => Agent::where('name', $name)->firstOrFail());
    }

    /** Importe un CSV (écrit dans un fichier temporaire : DataImporter attend un chemin) pour l'entreprise du test. */
    private function importAgents(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'agents').'.csv';
        file_put_contents($path, $csv);

        try {
            return TenantContext::runAs($this->tenant->id, fn () => app(DataImporter::class)->import('agents', $path, Excel::CSV));
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------ formulaire web

    public function test_the_web_form_starts_with_empty_debt_limit_and_commission_rate(): void
    {
        Livewire::test(CreateAgent::class)
            ->assertFormSet(['debt_limit' => null, 'commission_rate' => null, 'status' => 'active', 'type' => 'standard']);
    }

    public function test_an_agent_created_on_the_web_without_them_has_no_limit_and_no_rate(): void
    {
        Livewire::test(CreateAgent::class)
            ->fillForm(['name' => 'Sans valeurs', 'phone' => '0700000011', 'type' => 'standard', 'status' => 'active'])
            ->call('create')
            ->assertHasNoFormErrors();

        $agent = $this->agentNamed('Sans valeurs');
        $this->assertNull($agent->debt_limit, 'vide = null, pas 0 ni 500 000');
        $this->assertNull($agent->commission_rate, 'vide = null, pas 5');
    }

    public function test_values_typed_by_the_master_on_the_web_are_kept_as_typed(): void
    {
        Livewire::test(CreateAgent::class)
            ->fillForm(['name' => 'Avec valeurs', 'phone' => '0700000012', 'type' => 'standard', 'status' => 'active', 'debt_limit' => '1 250 000', 'commission_rate' => 3.5])
            ->call('create')
            ->assertHasNoFormErrors();

        $agent = $this->agentNamed('Avec valeurs');
        $this->assertEquals(1250000, $agent->debt_limit);
        $this->assertEquals(3.5, $agent->commission_rate);
    }

    public function test_clearing_the_limit_of_an_existing_agent_removes_it_instead_of_storing_zero(): void
    {
        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create(['debt_limit' => 500000, 'commission_rate' => 4]));

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])
            ->fillForm(['debt_limit' => '', 'commission_rate' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = TenantContext::runAs($this->tenant->id, fn () => $agent->fresh());
        $this->assertNull($fresh->debt_limit);
        $this->assertNull($fresh->commission_rate);
    }

    // ------------------------------------------------------------------ formulaire mobile

    public function test_the_mobile_form_starts_empty_and_saves_empty_as_null(): void
    {
        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->assertSet('data.debt_limit', '')
            ->assertSet('data.commission_rate', '')
            ->assertSet('data.status', 'active')
            ->set('data.name', 'Mobile vide')->set('data.phone', '0700000013')->set('data.type', 'standard')
            ->call('save')->assertHasNoErrors();

        $agent = $this->agentNamed('Mobile vide');
        $this->assertNull($agent->debt_limit);
        $this->assertNull($agent->commission_rate);
    }

    public function test_the_mobile_form_keeps_what_the_master_types(): void
    {
        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->set('data.name', 'Mobile rempli')->set('data.phone', '0700000014')->set('data.type', 'standard')
            ->set('data.debt_limit', '750000')->set('data.commission_rate', '4.5')
            ->call('save')->assertHasNoErrors();

        $agent = $this->agentNamed('Mobile rempli');
        $this->assertEquals(750000, $agent->debt_limit);
        $this->assertEquals(4.5, $agent->commission_rate);
    }

    public function test_the_forms_say_that_an_empty_limit_means_no_limit(): void
    {
        // Le champ est vide, mais le master doit savoir ce que « vide » signifie : aucune limite de crédit.
        $this->assertStringContainsString('Laissez vide', file_get_contents(app_path('Support/Mobile/Crud/Resources/Agents.php')));
        $this->assertStringContainsString('Laissez vide', file_get_contents(app_path('Filament/Resources/AgentResource.php')));

        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create(['debt_limit' => null, 'current_debt' => 900000]));
        $this->assertTrue($agent->canAcceptDebt(5000000), 'sans limite, l\'agent n\'est pas bloqué');
    }

    // ------------------------------------------------------------------ imports

    public function test_the_mobile_import_imposes_nothing_when_the_columns_are_absent_or_blank(): void
    {
        $csv = "nom,prenom,telephone,plafond_dette,commission_rate\n"
            ."Sans,Colonnes,0700000021,,\n"          // cellules vides
            ."Avec,Colonnes,0700000022,600000,2.5\n"; // valeurs fournies

        $result = $this->importAgents($csv);
        $this->assertSame([], $result['failures']);

        $blank = $this->agentNamed('Sans Colonnes');
        $this->assertNull($blank->debt_limit);
        $this->assertNull($blank->commission_rate);

        $given = $this->agentNamed('Avec Colonnes');
        $this->assertEquals(600000, $given->debt_limit);
        $this->assertEquals(2.5, $given->commission_rate);

        // Sans AUCUNE de ces deux colonnes dans le fichier : rien n'est imposé non plus.
        $this->importAgents("nom,prenom,telephone\nSeul,Nom,0700000023\n");
        $alone = $this->agentNamed('Seul Nom');
        $this->assertNull($alone->debt_limit);
        $this->assertNull($alone->commission_rate);
    }

    public function test_an_invalid_commission_rate_is_still_refused_by_the_import(): void
    {
        $result = $this->importAgents("nom,prenom,telephone,commission_rate\nTrop,Haut,0700000024,150\n");

        $this->assertCount(1, $result['failures']);
        $this->assertSame(0, TenantContext::runAs($this->tenant->id, fn () => Agent::where('name', 'Trop Haut')->count()));
    }

    public function test_the_filament_agents_import_no_longer_imposes_values_either(): void
    {
        $source = file_get_contents(app_path('Imports/AgentsImport.php'));

        $this->assertStringNotContainsString('500000', $source);
        $this->assertStringNotContainsString('5.00', $source);
    }

    public function test_recalculating_commissions_does_not_replace_an_empty_rate_with_five_percent(): void
    {
        [$agent, $transaction] = TenantContext::runAs($this->tenant->id, function (): array {
            $agent = Agent::factory()->create(['commission_rate' => null]);
            $transaction = UvTransaction::create([
                'agent_id' => $agent->id,
                'amount' => 100_000,
                'commission_rate' => 9,
                'commission_amount' => 9_000,
                'transaction_type' => 'distribution',
                'status' => 'completed',
            ]);

            return [$agent, $transaction];
        });

        TenantContext::runAs($this->tenant->id, fn () => $this->artisan('commissions:recalculate', ['--agent' => $agent->id])->assertExitCode(0));

        $fresh = TenantContext::runAs($this->tenant->id, fn () => $transaction->fresh());
        $this->assertNull($fresh->commission_rate);
        $this->assertNull($fresh->commission_amount);
    }
}
