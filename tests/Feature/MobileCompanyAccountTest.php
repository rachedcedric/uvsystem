<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Account\Profile;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDocumentBranding;
use App\Support\Mobile\Nav;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Application mobile : profil de l'entreprise, nom/logo de l'entreprise, accès à l'espace agent. */
class MobileCompanyAccountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
        $this->a = Tenant::create(['name' => 'Boutique Soleil']);
        $this->b = Tenant::create(['name' => 'Société B']);
    }

    private function userIn(Tenant $tenant, string $role): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    // ------------------------------------------------------------------------------------------ profil

    public function test_a_master_edits_the_company_profile_from_the_phone(): void
    {
        $master = $this->userIn($this->a, 'master');

        Livewire::actingAs($master)->test(Profile::class)
            ->assertSet('name', 'Boutique Soleil')
            ->set('name', 'Boutique Soleil SARL')
            ->set('legal_name', 'Soleil Distribution SARL')
            ->set('contact_phone', '+225 07 00 00 00 00')
            ->set('city', 'Abidjan')
            ->set('document_footer', 'RCCM CI-ABJ-2026-B-1234')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('m.more'));

        $tenant = $this->a->fresh();
        $this->assertSame('Boutique Soleil SARL', $tenant->name);
        $this->assertSame('+225 07 00 00 00 00', $tenant->contact_phone);
        $this->assertSame('Soleil Distribution SARL', data_get($tenant->settings, 'profile.legal_name'));
        $this->assertSame('RCCM CI-ABJ-2026-B-1234', data_get($tenant->settings, 'profile.document_footer'));
        $this->assertSame('Société B', $this->b->fresh()->name, 'une autre entreprise n\'est jamais touchée');
    }

    public function test_the_profile_screen_is_closed_to_users_without_the_settings_permission(): void
    {
        $user = $this->userIn($this->a, 'commercial');
        $this->assertFalse($user->can('view_system_settings'));

        $this->actingAs($user)->get(route('m.profil'))->assertForbidden();
    }

    public function test_the_company_comes_from_the_logged_in_user_only(): void
    {
        $masterB = $this->userIn($this->b, 'master');

        Livewire::actingAs($masterB)->test(Profile::class)
            ->assertSet('name', 'Société B')
            ->set('name', 'Société B Plus')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Boutique Soleil', $this->a->fresh()->name);
        $this->assertSame('Société B Plus', $this->b->fresh()->name);
    }

    public function test_validation_errors_are_reported_and_nothing_is_saved(): void
    {
        $master = $this->userIn($this->a, 'master');

        Livewire::actingAs($master)->test(Profile::class)
            ->set('name', '')
            ->set('contact_email', 'pas-un-email')
            ->set('website', 'exemple')
            ->call('save')
            ->assertHasErrors(['name', 'contact_email', 'website']);

        $this->assertSame('Boutique Soleil', $this->a->fresh()->name);
    }

    public function test_a_logo_sent_from_the_phone_is_stored_in_the_company_folder_and_reduced(): void
    {
        $master = $this->userIn($this->a, 'master');

        Livewire::actingAs($master)->test(Profile::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 1600, 800))
            ->call('save')
            ->assertHasNoErrors();

        $path = data_get($this->a->fresh()->settings, 'branding.logo_path');
        $this->assertStringStartsWith($this->a->storagePath('branding/'), $path);
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(TenantDocumentBranding::LOGO_MAX_SIDE, $width);
        $this->assertSame(TenantDocumentBranding::LOGO_MAX_SIDE / 2, $height);

        // Remplacer puis retirer : l'ancien fichier disparaît à chaque fois.
        Livewire::actingAs($master)->test(Profile::class)
            ->set('logo', UploadedFile::fake()->image('nouveau.jpg', 200, 100))
            ->call('save');
        Storage::disk('public')->assertMissing($path);

        $second = data_get($this->a->fresh()->settings, 'branding.logo_path');
        Storage::disk('public')->assertExists($second);

        Livewire::actingAs($master)->test(Profile::class)->call('removeLogoChoice')->call('save');
        $this->assertNull(data_get($this->a->fresh()->settings, 'branding.logo_path'));
        Storage::disk('public')->assertMissing($second);
    }

    public function test_saving_without_touching_the_logo_keeps_it(): void
    {
        $master = $this->userIn($this->a, 'master');
        Livewire::actingAs($master)->test(Profile::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 100, 100))
            ->call('save');
        $path = data_get($this->a->fresh()->settings, 'branding.logo_path');

        Livewire::actingAs($master)->test(Profile::class)->set('city', 'Bouaké')->call('save');

        $this->assertSame($path, data_get($this->a->fresh()->settings, 'branding.logo_path'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_only_raster_images_of_reasonable_size_are_accepted(): void
    {
        $master = $this->userIn($this->a, 'master');

        foreach ([
            UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->create('logo.pdf', 5, 'application/pdf'),
            UploadedFile::fake()->image('large.png', 2600, 10),
        ] as $file) {
            Livewire::actingAs($master)->test(Profile::class)->set('logo', $file)->call('save')->assertHasErrors(['logo']);
        }

        $this->assertNull(data_get($this->a->fresh()->settings, 'branding.logo_path'));
    }

    // ------------------------------------------------------------------------------- Plus / en-tête

    public function test_the_plus_screen_shows_the_company_and_links_to_its_profile_and_subscription(): void
    {
        $master = $this->userIn($this->a, 'master');

        $this->actingAs($master)->get(route('m.more'))
            ->assertOk()
            ->assertSee('Boutique Soleil')
            ->assertSee(route('m.profil'), false)
            ->assertSee(route('m.abonnement'), false)
            ->assertDontSee('Société B');

        // Le filet « Autres écrans » ne repropose pas ce qui existe déjà en natif.
        $html = $this->actingAs($master)->get(route('m.more'))->getContent();
        $this->assertStringNotContainsString('/master/mon-abonnement', $html);
        $this->assertStringNotContainsString('/master/profil-entreprise', $html);
    }

    public function test_the_company_logo_is_shown_once_uploaded(): void
    {
        $master = $this->userIn($this->a, 'master');
        Livewire::actingAs($master)->test(Profile::class)->set('logo', UploadedFile::fake()->image('logo.png', 100, 100))->call('save');

        $this->actingAs($master)->get(route('m.more'))->assertOk()->assertSee('tenant-branding/logo', false);
        $this->actingAs($master)->get(route('tenant.branding.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_home_header_names_the_company_of_the_user(): void
    {
        $this->actingAs($this->userIn($this->a, 'master'))->get(route('m.home'))->assertOk()->assertSee('Boutique Soleil');
        $this->actingAs($this->userIn($this->b, 'master'))->get(route('m.home'))->assertOk()->assertSee('Société B')->assertDontSee('Boutique Soleil');
    }

    public function test_the_menu_entries_follow_the_permissions(): void
    {
        $labels = fn (User $user): array => array_column(Nav::moreItems($user), 'label');

        $this->assertContains('Profil de l\'entreprise', $labels($this->userIn($this->a, 'master')));
        $this->assertContains('Profil de l\'entreprise', $labels($this->userIn($this->a, 'admin')));
        $this->assertNotContains('Profil de l\'entreprise', $labels($this->userIn($this->a, 'commercial')));
    }

    // ----------------------------------------------------------------------------------- espace agent

    public function test_a_master_linked_to_an_agent_record_reaches_the_agent_space_from_the_phone(): void
    {
        $master = $this->userIn($this->a, 'master');

        $this->assertNotContains('Mon espace agent', array_column(Nav::moreItems($master), 'label'));
        $this->actingAs($master)->get(route('m.espace-agent'))->assertForbidden();

        TenantContext::runAs($this->a->id, fn () => Agent::factory()->create(['user_id' => $master->id]));
        $master = $master->fresh();

        $this->assertContains('Mon espace agent', array_column(Nav::moreItems($master), 'label'));
        $this->actingAs($master)->get(route('m.espace-agent'))->assertRedirect('/agent');
        $this->actingAs($master)->get(route('m.more'))->assertOk()->assertSee(route('m.espace-agent'), false);
    }
}
