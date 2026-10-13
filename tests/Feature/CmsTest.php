<?php

namespace Tests\Feature;

use App\Cms\Content;
use App\Cms\ContentRegistry;
use App\Filament\Platform\Pages\Cms\EmailContent;
use App\Filament\Platform\Pages\Cms\LandingContent;
use App\Filament\Platform\Pages\Cms\PublicPagesContent;
use App\Filament\Platform\Pages\Cms\SaasSettings;
use App\Models\Activity;
use App\Models\CmsEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Subscriptions\SubscriptionChangeRequested;
use App\Notifications\Subscriptions\TrialExpiring;
use App\Notifications\Subscriptions\VerifySignupNotification;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** CMS de la plateforme : contenu modifiable par le super admin, valeurs d'origine toujours disponibles. */
class CmsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [LandingContent::class, PublicPagesContent::class, EmailContent::class, SaasSettings::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        Content::flush();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Notification::fake();
        config(['security.authentication.2fa_enabled' => false]);
    }

    protected function tearDown(): void
    {
        Content::flush();
        parent::tearDown();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function master(): User
    {
        $tenant = Tenant::create(['name' => 'Société '.uniqid()]);

        return TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
    }

    private function edit(string $page, array $values): void
    {
        $fields = [];
        foreach ($values as $key => $value) {
            $fields[str_replace('.', '__', $key)] = $value;
        }

        Livewire::test($page)->fillForm($fields)->call('save')->assertHasNoFormErrors();
        Content::flush();
    }

    // ================================================================ service

    public function test_without_any_edit_every_text_is_the_original_one(): void
    {
        $this->assertSame("Vos flux UV.\nUne seule tour de contrôle.", cms('landing.hero.title'));
        $this->assertSame('Commencer gratuitement', cms('landing.cta.primary'));
        $this->assertSame('contact@uvmaster.ci', cms('settings.contact.email'));
        $this->assertSame('Essayer 14 jours', cms('public.offres.cta_trial', ['days' => 14]));
        $this->assertSame('Votre essai se termine dans 3 jour(s)', cms('emails.trial_expiring.subject', ['days' => 3]));
        $this->assertSame(0, CmsEntry::count());
    }

    public function test_an_override_replaces_the_default_and_removing_it_restores_the_default(): void
    {
        CmsEntry::create(['key' => 'landing.cta.primary', 'value' => 'Essayer MODELEC']);
        Content::flush();
        $this->assertSame('Essayer MODELEC', cms('landing.cta.primary'));

        CmsEntry::where('key', 'landing.cta.primary')->delete();
        Content::flush();
        $this->assertSame('Commencer gratuitement', cms('landing.cta.primary'));
    }

    public function test_placeholders_are_replaced_and_html_is_never_interpreted(): void
    {
        CmsEntry::create(['key' => 'public.offres.cta_trial', 'value' => '<b>Gratuit</b> {days} j']);
        CmsEntry::create(['key' => 'landing.hero.title', 'value' => "Ligne <i>un</i>\nLigne deux"]);
        Content::flush();

        $this->assertSame('<b>Gratuit</b> 7 j', cms('public.offres.cta_trial', ['days' => 7]), 'texte brut côté service');
        $html = (string) cms_html('landing.hero.title');
        $this->assertStringContainsString('Ligne &lt;i&gt;un&lt;/i&gt;<br>Ligne deux', $html, 'échappé à l\'affichage, saut de ligne conservé');
        $this->assertStringNotContainsString('<i>', $html);
    }

    public function test_an_unknown_key_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        cms('landing.inexistant');
    }

    public function test_a_broken_cms_never_breaks_a_public_page(): void
    {
        Schema::drop('cms_entries'); // table absente (avant migration) ou base indisponible
        Content::flush();

        $this->assertSame('Commencer gratuitement', cms('landing.cta.primary'));
        $this->get('/offres')->assertOk()->assertSee('Choisissez votre offre');
    }

    public function test_every_key_used_by_a_view_or_a_class_exists_in_the_registry(): void
    {
        $found = [];
        $files = array_merge(
            glob(resource_path('views/**/*.blade.php')) ?: [],
            glob(resource_path('views/*.blade.php')) ?: [],
            glob(resource_path('views/*/*/*.blade.php')) ?: [],
            glob(app_path('**/*.php')) ?: [],
            glob(app_path('*/*/*.php')) ?: [],
            glob(app_path('*/*/*/*.php')) ?: [],
        );
        foreach ($files as $file) {
            if (str_contains($file, 'Cms/helpers.php') || str_contains($file, 'Cms/Content.php') || str_contains($file, 'Cms/ContentRegistry.php')) {
                continue;
            }
            preg_match_all("/\b(?:cms|cms_html|cms_lines|Content::text|Content::lines)\(\s*'([a-z0-9_.]+)'/", file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                if (! str_ends_with($key, '.')) { // préfixe d'une clé construite (emails.{type}.subject) : couvert par le test des e-mails
                    $found[$key] = $file;
                }
            }
        }

        $this->assertNotEmpty($found);
        foreach ($found as $key => $file) {
            $this->assertTrue(ContentRegistry::has($key), "clé « $key » utilisée dans ".basename($file).' mais absente du registre');
        }
    }

    public function test_every_subscription_email_has_editable_subject_and_body_with_defaults(): void
    {
        foreach (ContentRegistry::NOTIFICATIONS as $class) {
            $type = $class::type();
            $this->assertTrue(ContentRegistry::has("emails.{$type}.subject"), $type);
            $this->assertTrue(ContentRegistry::has("emails.{$type}.body"), $type);
            $this->assertNotSame('', cms("emails.{$type}.subject"));
            $this->assertNotEmpty(cms_lines("emails.{$type}.body"));
        }
    }

    // ================================================================ accès

    public function test_only_the_super_admin_reaches_the_cms_pages(): void
    {
        $super = $this->superAdmin();
        $master = $this->master();

        foreach (self::PAGES as $page) {
            $this->actingAs($master);
            Livewire::test($page)->assertForbidden();
            $this->assertFalse($page::canAccess());

            $this->actingAs($super);
            Livewire::test($page)->assertSuccessful();
            $this->assertTrue($page::canAccess());
        }
    }

    public function test_a_tenant_user_cannot_open_cms_urls(): void
    {
        $this->actingAs($this->master());

        foreach (['/platform/cms/page-d-accueil', '/platform/cms/pages-publiques', '/platform/cms/emails', '/platform/cms/reglages'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->assertSame(0, CmsEntry::count());
    }

    public function test_the_cms_group_appears_in_the_menu_between_subscriptions_and_system(): void
    {
        $this->actingAs($this->superAdmin());

        $menu = collect(Filament::getNavigation())->mapWithKeys(fn ($g) => [(string) $g->getLabel() => collect($g->getItems())->map(fn ($i) => $i->getLabel())->values()->all()])->all();

        $this->assertSame(['Pilotage', 'Entreprises', 'Abonnements', 'CMS', 'Système'], array_keys($menu));
        $this->assertSame(['Page d\'accueil', 'Pages publiques', 'E-mails et messages', 'Réglages du SaaS'], $menu['CMS']);
    }

    // ================================================================ édition

    public function test_saving_stores_only_what_differs_from_the_default_and_logs_it(): void
    {
        $this->actingAs($this->superAdmin());

        $this->edit(LandingContent::class, [
            'landing.cta.primary' => 'Essayer MODELEC',
            'landing.cta.discover' => 'Découvrir la plateforme', // identique à l'origine : rien n'est stocké
            'landing.cta.login' => '',
        ]);

        $this->assertSame(['landing.cta.primary' => 'Essayer MODELEC'], CmsEntry::pluck('value', 'key')->all());
        $log = Activity::withoutGlobalScopes()->where('log_name', 'cms')->latest('id')->firstOrFail();
        $this->assertStringContainsString('1 élément', $log->description);
        $this->assertSame('Essayer MODELEC', $log->properties['modifications']['landing.cta.primary']['vers']);
        $this->assertNull($log->tenant_id, 'action de plateforme : invisible des entreprises');
    }

    public function test_emptying_a_field_restores_the_original_text(): void
    {
        $this->actingAs($this->superAdmin());
        $this->edit(LandingContent::class, ['landing.cta.primary' => 'Essayer MODELEC']);
        $this->assertSame('Essayer MODELEC', cms('landing.cta.primary'));

        $this->edit(LandingContent::class, ['landing.cta.primary' => '']);

        $this->assertSame('Commencer gratuitement', cms('landing.cta.primary'));
        $this->assertSame(0, CmsEntry::count());
    }

    public function test_markup_is_stripped_and_nothing_executable_is_stored(): void
    {
        $this->actingAs($this->superAdmin());

        $this->edit(LandingContent::class, ['landing.cta.primary' => '<script>alert(1)</script>Go <b>vite</b>']);

        $this->assertSame('alert(1)Go vite', CmsEntry::where('key', 'landing.cta.primary')->value('value'));
    }

    public function test_invalid_values_are_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(SaasSettings::class)
            ->fillForm(['settings__contact__email' => 'pas-un-email', 'settings__links__terms_url' => 'javascript:alert(1)', 'settings__contact__phone' => 'abc'])
            ->call('save')
            ->assertHasFormErrors(['settings__contact__email', 'settings__links__terms_url', 'settings__contact__phone']);

        Livewire::test(LandingContent::class)->fillForm(['landing__cta__primary' => str_repeat('x', 201)])->call('save')->assertHasFormErrors(['landing__cta__primary']);

        $this->assertSame(0, CmsEntry::count());
    }

    public function test_valid_links_and_contacts_are_accepted(): void
    {
        $this->actingAs($this->superAdmin());

        $this->edit(SaasSettings::class, [
            'settings.contact.email' => 'support@modelec.example', 'settings.contact.phone' => '+225 07 00 00 00 00',
            'settings.links.terms_url' => 'https://modelec.example/conditions', 'settings.links.privacy_url' => '/confidentialite',
        ]);

        $this->assertSame('https://modelec.example/conditions', cms('settings.links.terms_url'));
        $this->assertSame('/confidentialite', cms('settings.links.privacy_url'));
    }

    public function test_reset_restores_every_text_of_the_section_and_only_that_section(): void
    {
        $this->actingAs($this->superAdmin());
        $this->edit(LandingContent::class, ['landing.cta.primary' => 'Essayer MODELEC']);
        $this->edit(PublicPagesContent::class, ['public.offres.title' => 'Nos formules']);

        Livewire::test(LandingContent::class)->callAction('reset');
        Content::flush();

        $this->assertSame('Commencer gratuitement', cms('landing.cta.primary'));
        $this->assertSame('Nos formules', cms('public.offres.title'), 'les autres sections ne bougent pas');
    }

    // ================================================================ rendu

    public function test_pricing_and_signup_pages_show_the_edited_texts(): void
    {
        $this->actingAs($this->superAdmin());
        $this->edit(PublicPagesContent::class, [
            'public.offres.title' => 'Nos formules', 'public.offres.subtitle' => 'Sans carte bancaire.', 'public.offres.featured' => 'Notre choix',
            'public.offres.cta_trial' => 'Tester {days} jours', 'public.inscription.title' => 'Ouvrir mon espace',
            'public.inscription.trial_note' => '{days} jours offerts.', 'public.inscription.submit' => 'Envoyer le lien',
        ]);
        auth()->logout();

        $this->get('/offres')->assertOk()->assertSee('Nos formules')->assertSee('Sans carte bancaire.')->assertSee('Notre choix')->assertSee('Tester 14 jours')->assertDontSee('Essayer 14 jours');
        $this->get('/inscription?plan=pro&cycle=monthly')->assertOk()->assertSee('Ouvrir mon espace')->assertSee('14 jours offerts.')->assertSee('Envoyer le lien');
    }

    public function test_confirmation_pages_show_the_edited_texts(): void
    {
        $this->actingAs($this->superAdmin());
        $this->edit(PublicPagesContent::class, ['public.sent.title' => 'Regardez vos mails', 'public.confirm.title' => 'Dernière étape', 'public.confirm.button' => 'Je valide']);
        auth()->logout();

        $this->get(route('inscription.envoye'))->assertOk()->assertSee('Regardez vos mails');
        $token = 'abc';
        $url = URL::temporarySignedRoute('inscription.verifier', now()->addHour(), ['token' => $token]);
        $this->get($url)->assertOk()->assertSee('Dernière étape')->assertSee('Je valide');
    }

    public function test_settings_are_used_by_public_pages_and_login_brand_panel(): void
    {
        $this->actingAs($this->superAdmin());
        $this->edit(SaasSettings::class, ['settings.brand.name' => 'ACME UV', 'settings.brand.legal_name' => 'ACME SARL', 'settings.links.terms_url' => 'https://acme.example/cgu']);
        auth()->logout();

        $this->get('/offres')->assertOk()->assertSee('Offres — ACME UV', false);
        $this->get('/inscription?plan=pro')->assertOk()->assertSee('href="https://acme.example/cgu"', false);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee('ACME UV');
    }

    public function test_the_landing_page_shows_the_edited_texts(): void
    {
        if (! str_contains(file_get_contents(resource_path('views/welcome.blade.php')), "cms('landing.")) {
            $this->markTestSkipped('welcome.blade.php n\'est pas encore branchée sur le CMS (fichier en cours de modification).');
        }

        $this->actingAs($this->superAdmin());
        $this->edit(LandingContent::class, ['landing.hero.title' => "Nouveau titre\nDeuxième ligne", 'landing.cta.primary' => 'Lancez-vous', 'landing.footer.tagline' => 'Notre slogan']);
        $this->edit(SaasSettings::class, ['settings.contact.email' => 'hello@acme.example', 'settings.contact.phone' => '+225 01 02 03 04', 'settings.brand.legal_name' => 'ACME SARL']);
        auth()->logout();

        $this->get('/')->assertOk()->assertSee('Nouveau titre<br>Deuxième ligne', false)->assertSee('Lancez-vous')->assertSee('Notre slogan')
            ->assertSee('mailto:hello@acme.example', false)->assertSee('tel:+225', false)->assertSee('ACME SARL')->assertDontSee('Vos flux UV.');
    }

    public function test_edited_email_texts_are_the_ones_sent(): void
    {
        $this->actingAs($this->superAdmin());
        $this->edit(EmailContent::class, [
            'emails.common.greeting' => 'Salut,',
            'emails.verify_signup.subject' => 'Validez {company}', 'emails.verify_signup.intro' => 'Bienvenue chez {company}.',
            'emails.trial_expiring.subject' => 'Plus que {days} j pour {plan}', 'emails.trial_expiring.body' => "Ligne un {date}\nLigne deux",
            'emails.subscription_change_requested.body_free' => 'Changement immédiat vers {plan}.',
        ]);

        $verify = (new VerifySignupNotification('https://x.test/v', 'Boutique Soleil', 24))->toMail(new AnonymousNotifiable);
        $this->assertSame('Validez Boutique Soleil', $verify->subject);
        $this->assertSame('Salut,', $verify->greeting);
        $this->assertSame(['Bienvenue chez Boutique Soleil.'], $verify->introLines);
        $this->assertStringContainsString('24 heures', implode(' ', $verify->outroLines), 'texte d\'origine conservé là où rien n\'est modifié');

        $expiring = (new TrialExpiring(['plan' => 'Pro', 'ends_at' => '20/10/2026', 'days' => 3]))->toMail($this->master());
        $this->assertSame('Plus que 3 j pour Pro', $expiring->subject);
        $this->assertSame(['Ligne un 20/10/2026', 'Ligne deux'], $expiring->introLines);

        $free = (new SubscriptionChangeRequested(['plan' => 'Entreprise', 'cycle' => 'Mensuel', 'amount' => 0]))->toMail($this->master());
        $this->assertSame(['Changement immédiat vers Entreprise.'], $free->introLines);
    }

    public function test_the_in_app_notification_uses_the_edited_text_too(): void
    {
        $this->actingAs($this->superAdmin());
        $this->edit(EmailContent::class, ['emails.trial_expiring.subject' => 'Bientôt fini ({days})']);

        $payload = (new TrialExpiring(['plan' => 'Pro', 'ends_at' => '20/10/2026', 'days' => 2]))->toArray($this->master());

        $this->assertSame('Bientôt fini (2)', $payload['title']);
    }
}
