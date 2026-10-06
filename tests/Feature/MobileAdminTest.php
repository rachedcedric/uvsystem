<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Admin;
use App\Livewire\Mobile\Agents;
use App\Livewire\Mobile\Dettes;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['view_activity_log', 'view_agents', 'export_agents', 'view_remboursements', 'create_remboursements'];

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web'])->syncPermissions(self::PERMISSIONS);
        Role::firstOrCreate(['name' => 'commercial', 'guard_name' => 'web']);
        $this->master = User::factory()->create();
        $this->master->assignRole('master');
        $this->actingAs($this->master);
    }

    public function test_activity_log_lists_filters_and_shows_the_changes(): void
    {
        activity()->causedBy($this->master)->log('Vente créée par test');
        $activity = \App\Models\Activity::latest('id')->firstOrFail();

        $component = Livewire::test(Admin\ActivityLog::class)->assertSee('Aujourd')->assertSee('Vente créée par test');

        $component->set('search', 'introuvable-xyz')->assertSee('Aucune activité');
        Livewire::test(Admin\ActivityShow::class, ['activity' => $activity])->assertSee('Action')->assertSee('Utilisateur');
        $this->get(route('m.activite'))->assertOk();
    }

    public function test_activity_log_requires_its_permission(): void
    {
        $this->actingAs(tap(User::factory()->create())->assignRole('commercial'));

        Livewire::test(Admin\ActivityLog::class)->assertForbidden();
    }

    public function test_backups_are_never_available_to_tenant_users(): void
    {
        // Une sauvegarde contient les données de TOUS les tenants : même un master de tenant est refusé.
        Storage::fake('local');
        $dir = config('app.name', 'uv-system');
        Storage::disk('local')->put("{$dir}/2026-10-04-backup.zip", 'zip-bytes');

        Livewire::test(Admin\Backups::class)->assertForbidden();
        $this->get(route('m.sauvegardes.telecharger', ['name' => '2026-10-04-backup.zip']))->assertForbidden();
        $this->get('/backup/download/'.base64_encode("{$dir}/2026-10-04-backup.zip"))->assertForbidden();
    }

    public function test_backup_download_is_limited_to_the_platform_super_admin_and_to_zip_archives(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('secret.txt', 'top secret');
        $dir = config('app.name', 'uv-system');
        Storage::disk('local')->put("{$dir}/ok.zip", 'zip');

        Role::firstOrCreate(['name' => User::ROLE_SUPER_ADMIN, 'guard_name' => 'web']);
        $super = \App\Tenancy\TenantContext::runAs(null, fn () => User::factory()->create());
        $super->assignRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($super);

        $this->get('/backup/download/'.base64_encode('secret.txt'))->assertNotFound();
        $this->get('/backup/download/'.base64_encode("{$dir}/ok.zip"))->assertOk();

        $this->actingAs(tap(User::factory()->create())->assignRole('commercial'));
        $this->get('/backup/download/'.base64_encode("{$dir}/ok.zip"))->assertForbidden();
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('import.csv', $content);
    }

    public function test_agent_import_creates_agents_skips_duplicates_and_reports_failures(): void
    {
        Agent::factory()->create(['phone' => '0700000001']);
        $csv = "nom,prenom,telephone,email,zone,plafond_dette\n"
            ."Kone,Awa,0700112233,awa@example.com,Cocody,750000\n"
            ."Doublon,Test,0700000001,,,\n"
            ."Sans,Telephone,12,,,\n";

        $component = Livewire::test(Admin\Import::class)->set('type', 'agents')->set('file', $this->csv($csv))->call('import')->assertHasNoErrors();

        $agent = Agent::where('phone', '0700112233')->firstOrFail();
        $this->assertSame('Kone Awa', $agent->name);
        $this->assertEquals(750000, $agent->debt_limit);
        $this->assertSame(1, $component->get('results')['skipped']);
        $this->assertSame(4, $component->get('results')['failures'][0]['row']);
    }

    public function test_transaction_import_uses_the_distribution_service_checks(): void
    {
        \App\Models\Approvisionnement::create(['user_id' => $this->master->id, 'fournisseur_id' => \App\Models\Fournisseur::factory()->create()->id, 'amount' => 300000, 'date_approvisionnement' => now()]);
        $agent = Agent::factory()->create(['phone' => '0700555555', 'status' => 'active', 'debt_limit' => 250000]);

        $csv = "agent_telephone,montant,notes\n0700555555,100000,ok\n0700555555,200000,trop haut\n0799999999,1000,inconnu\n";
        $component = Livewire::test(Admin\Import::class)->set('type', 'transactions')->set('file', $this->csv($csv))->call('import');

        $this->assertSame(1, \App\Models\UvTransaction::count());
        $this->assertEquals(100000, $agent->fresh()->current_debt);
        $results = $component->get('results');
        $this->assertSame(1, $results['success']);
        $this->assertCount(2, $results['failures']);
    }

    public function test_repayment_import_cannot_exceed_the_debt(): void
    {
        \App\Models\Approvisionnement::create(['user_id' => $this->master->id, 'fournisseur_id' => \App\Models\Fournisseur::factory()->create()->id, 'amount' => 500000, 'date_approvisionnement' => now()]);
        $agent = Agent::factory()->create(['phone' => '0700666666', 'status' => 'active', 'debt_limit' => 500000]);
        (new \App\Services\UvDistributionService)->distribute($agent, 100000);
        \App\Models\PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'is_active' => true, 'sort_order' => 1]);

        $csv = "agent_telephone,montant\n0700666666,40000\n0700666666,90000\n";
        $component = Livewire::test(Admin\Import::class)->set('type', 'remboursements')->set('file', $this->csv($csv))->call('import');

        $this->assertEquals(40000, \App\Models\Remboursement::sum('amount'));
        $this->assertSame(1, $component->get('results')['success']);
        $this->assertSame(3, $component->get('results')['failures'][0]['row']);
    }

    public function test_import_rejects_wrong_file_types_and_non_admins(): void
    {
        Livewire::test(Admin\Import::class)
            ->set('file', UploadedFile::fake()->create('virus.exe', 10))
            ->call('import')->assertHasErrors('file');

        $this->actingAs(tap(User::factory()->create())->assignRole('commercial'));
        Livewire::test(Admin\Import::class)->assertForbidden();
    }

    public function test_two_factor_can_be_enabled_with_a_valid_code_then_disabled(): void
    {
        $component = Livewire::test(Admin\TwoFactor::class)->call('startSetup')->assertSet('showSetup', true);
        $secret = $component->get('secret');
        $this->assertNotEmpty($secret);

        $component->set('code', '000000')->call('confirmSetup')->assertHasErrors('code');

        $valid = (new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp($secret);
        $component->set('code', $valid)->call('confirmSetup')->assertHasNoErrors();

        $user = $this->master->fresh();
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertCount(8, $user->two_factor_recovery_codes);

        $old = $user->two_factor_recovery_codes;
        Livewire::test(Admin\TwoFactor::class)->call('regenerate');
        $this->assertNotEquals($old, $this->master->fresh()->two_factor_recovery_codes);
        $this->assertCount(8, $this->master->fresh()->two_factor_recovery_codes);

        Livewire::test(Admin\TwoFactor::class)->call('disable');
        $this->assertFalse((bool) $this->master->fresh()->two_factor_enabled);
    }

    public function test_agent_screen_offers_reminder_and_pdf_card(): void
    {
        $agent = Agent::factory()->create(['name' => 'Awa Koné', 'status' => 'active', 'debt_limit' => 500000]);
        $agent->forceFill(['current_debt' => 120000])->saveQuietly();

        Livewire::test(Agents\Show::class, ['agent' => $agent])->assertSee('Rappel')->assertSee('Fiche agent (PDF)');

        $pdf = $this->get(route('m.exports.fiche-agent', $agent));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_reminder_requires_a_debt_and_a_reachable_channel(): void
    {
        $clean = Agent::factory()->create(['status' => 'active']);
        Livewire::test(Agents\Show::class, ['agent' => $clean])->call('sendReminder')->assertDispatched('m-toast');

        $debtor = Agent::factory()->create(['status' => 'active', 'email' => null]);
        $debtor->forceFill(['current_debt' => 50000])->saveQuietly();
        Livewire::test(Agents\Show::class, ['agent' => $debtor])->set('channel', 'email')->call('sendReminder')->assertDispatched('m-toast');
    }

    public function test_debt_reminders_can_be_sent_by_admins(): void
    {
        $debtor = Agent::factory()->create(['status' => 'active']);
        $debtor->forceFill(['current_debt' => 50000])->saveQuietly();

        Livewire::test(Dettes\Index::class)->call('sendReminders')->assertDispatched('m-toast');

        $this->actingAs(tap(User::factory()->create())->assignRole('commercial'));
        Livewire::test(Dettes\Index::class)->assertForbidden();
    }
}
