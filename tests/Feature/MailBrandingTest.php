<?php

namespace Tests\Feature;

use App\Notifications\Subscriptions\TrialExpiring;
use App\Notifications\Subscriptions\VerifySignupNotification;
use App\Support\BrandAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

/**
 * Tous les e-mails portent la marque (config/branding.php) et son logo, jamais APP_NAME. Régression : sur un serveur dont le
 * .env avait gardé l'ancien APP_NAME, l'en-tête, la signature et le pied de page des e-mails affichaient l'ancien nom.
 */
class MailBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** L'ancien nom, assemblé à l'exécution : ce fichier ne doit pas le contenir lui-même (voir BrandIdentityTest). */
    private function oldName(): string
    {
        return 'MODEL'.'EC CI';
    }

    private function signupHtml(): string
    {
        // Le serveur concerné avait encore l'ancien nom dans APP_NAME : on le reproduit.
        config(['app.name' => $this->oldName()]);

        return (string) (new VerifySignupNotification('https://uvmaster.ci/inscription/verifier/jeton', 'N2K SERVICES', 24))
            ->toMail(new AnonymousNotifiable)
            ->render();
    }

    public function test_the_signup_mail_shows_the_brand_and_its_logo_whatever_app_name_says(): void
    {
        $html = $this->signupHtml();

        $this->assertStringNotContainsStringIgnoringCase($this->oldName(), $html);
        $this->assertStringNotContainsStringIgnoringCase('MODEL'.'EC', $html);

        // Logo en image absolue (un e-mail s'ouvre hors du site), avec la marque en texte alternatif.
        $this->assertStringContainsString('<img src="'.rtrim(config('branding.url'), '/').'/images/logo-wide.png'.BrandAsset::version('images/logo-wide.png').'"', $html);
        $this->assertStringContainsString('alt="'.config('branding.name').'"', $html);
        $this->assertSame('UVMASTER', config('branding.name'));

        // Pied de page à la marque, en français.
        $this->assertStringContainsString('© '.date('Y').' UVMASTER. Tous droits réservés.', $html);
    }

    public function test_the_mail_is_fully_in_french(): void
    {
        $html = $this->signupHtml();

        $this->assertStringContainsString('Cordialement,', $html);
        $this->assertStringContainsString('Si le bouton « Confirmer et démarrer mon essai » ne fonctionne pas', $html);
        $this->assertStringContainsString('Bonjour', $html);

        foreach (['Regards', 'All rights reserved', 'trouble clicking', 'Hello!', 'Whoops'] as $english) {
            $this->assertStringNotContainsString($english, $html, $english);
        }
    }

    public function test_subscription_mails_use_the_same_branded_layout(): void
    {
        config(['app.name' => $this->oldName()]);

        $html = (string) (new TrialExpiring(['plan' => 'Pro', 'ends_at' => '20/10/2026', 'days' => 3]))
            ->toMail(new AnonymousNotifiable)
            ->render();

        $this->assertStringNotContainsStringIgnoringCase('MODEL'.'EC', $html);
        $this->assertStringContainsString('/images/logo-wide.png', $html);
        $this->assertStringContainsString('Cordialement,', $html);
    }

    public function test_the_sender_name_is_the_brand_not_the_app_name(): void
    {
        $this->assertSame(config('branding.name'), config('mail.from.name'));
    }

    public function test_the_logo_file_exists_and_no_mail_view_reads_the_app_name_any_more(): void
    {
        $this->assertFileExists(public_path('images/logo-wide.png'));

        foreach (['vendor/mail/html/message', 'vendor/mail/html/header', 'vendor/mail/html/layout', 'vendor/mail/text/message', 'vendor/notifications/email', 'emails/notification'] as $view) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));

            $this->assertStringNotContainsString("config('app.name')", $source, $view);
        }
    }
}
