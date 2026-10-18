<?php

namespace Tests\Feature;

use App\Filament\Resources\AgentResource\Pages\CreateAgent;
use App\Livewire\Mobile\Crud;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mobile\Crud\Resources\Agents;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La classification standard/premium/VIP n'accordait aucun droit et ne pilotait aucune règle métier. Les agents sont
 * définis par leur compte lié, leurs rôles utilisateur et leur statut opérationnel.
 */
class AgentStatusValuesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);

        $this->tenant = Tenant::create(['name' => 'Société A']);
        $master = TenantContext::runAs($this->tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });

        $this->actingAs($master);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_status_offered_by_the_mobile_form_can_be_stored(): void
    {
        foreach (array_keys(Agents::STATUSES) as $status) {
            $agent = Agent::factory()->create(['status' => $status]);

            $this->assertSame($status, $agent->fresh()->status);
        }
    }

    public function test_an_agent_is_saved_from_the_mobile_form_without_a_commercial_type(): void
    {
        Livewire::test(Crud\Form::class, ['resource' => 'agents'])
            ->set('data.name', 'DOUK TELECOM')->set('data.phone', '0747494939')->set('data.address', 'QRT DIVO')
            ->set('data.status', 'active')
            ->call('save')->assertHasNoErrors();

        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::where('name', 'DOUK TELECOM')->firstOrFail());
        $this->assertSame('active', $agent->status);
    }

    public function test_an_agent_is_saved_from_the_web_form_without_a_commercial_type(): void
    {
        Livewire::test(CreateAgent::class)
            ->fillForm(['name' => 'DOUK TELECOM', 'phone' => '0747494939', 'status' => 'blocked'])
            ->call('create')->assertHasNoFormErrors();

        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::where('name', 'DOUK TELECOM')->firstOrFail());
        $this->assertSame('blocked', $agent->status);
    }

    public function test_agent_forms_no_longer_offer_a_commercial_type(): void
    {
        $webSource = (string) file_get_contents(app_path('Filament/Resources/AgentResource.php'));
        $mobileFields = collect(Agents::fields())->pluck('name');

        $this->assertStringNotContainsString("Select::make('type')", $webSource);
        $this->assertNotContains('type', $mobileFields);
    }

    public function test_agent_views_reports_and_scripts_no_longer_use_a_commercial_type(): void
    {
        $mobileShowSource = (string) file_get_contents(resource_path('views/livewire/mobile/agents/show.blade.php'));
        $reportSource = (string) file_get_contents(app_path('Http/Controllers/Api/ReportController.php'));
        $concurrencyScript = (string) file_get_contents(base_path('scripts/check-limit-concurrency.php'));

        $this->assertStringNotContainsString('$a->type', $mobileShowSource);
        $this->assertStringNotContainsString("'Type' => \$a->type", $reportSource);
        $this->assertStringNotContainsString("'type' => 'standard'", $concurrencyScript);
    }

    public function test_the_agents_table_no_longer_has_a_commercial_type_column(): void
    {
        $this->assertFalse(Schema::hasColumn('agents', 'type'));
    }
}
