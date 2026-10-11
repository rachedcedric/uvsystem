<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Identité visible UVMASTER : une seule source (config/branding.php), aucune trace de l'ancienne marque dans ce qui est
 * affiché, et séparation avec les valeurs techniques (APP_NAME) et les données métier (nom d'entreprise cliente).
 */
class BrandIdentityTest extends TestCase
{
    /** Valeurs volontairement conservées : données métier / valeurs de migration, pas la marque du logiciel. */
    private const ALLOWED = [
        'app/Cms/ContentRegistry.php' => 'raison sociale par défaut du CMS',
        'database/migrations/2026_10_07_000001_create_tenants_and_add_tenant_id.php' => 'nom du tenant n°1 (donnée métier)',
    ];

    public function test_the_official_identity_comes_from_one_configuration(): void
    {
        $this->assertSame('UVMASTER', config('branding.name'));
        $this->assertSame('La gestion des UV simplifiée', config('branding.tagline'));
        $this->assertSame('uvmaster.ci', config('branding.domain'));
        $this->assertSame('https://uvmaster.ci', config('branding.url'));
    }

    public function test_the_technical_application_name_is_left_alone(): void
    {
        // APP_NAME pilote le dossier des sauvegardes, le cookie de session et le préfixe de cache : il ne suit pas la marque.
        $this->assertNotSame(config('branding.name'), config('app.name'));
    }

    /** @return list<string> */
    private function offenders(string $dir, string $extension): array
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());
            if (! $file->isFile() || ! str_ends_with($path, $extension) || str_contains($path, '/.engram/') || isset(self::ALLOWED[$path])) {
                continue;
            }
            $source = (string) preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#^\s*(//|\*|\#).*$#m'], '', (string) file_get_contents($file->getPathname()));
            // Identifiants techniques conservés (ressource sonore Android, interface JS native, classes CSS, clés internes).
            $source = (string) preg_replace('/modelec_alert|modelec-[a-z-]+|ModelecNative|__modelecBridge/i', '', $source);
            if (preg_match('/MODELEC|UV Distribution System|uvsystem|uv-system\.com/i', $source)) {
                $offenders[] = $path;
            }
        }

        return $offenders;
    }

    public function test_no_view_shows_the_old_brand(): void
    {
        $this->assertSame([], $this->offenders('resources/views', '.blade.php'));
    }

    public function test_no_php_source_hardcodes_the_old_brand_or_domain(): void
    {
        $this->assertSame([], array_merge($this->offenders('app', '.php'), $this->offenders('config', '.php')));
    }

    public function test_public_and_login_pages_show_the_new_brand_only(): void
    {
        foreach (['/', '/m/login', '/master/login', '/platform/login'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $visible = strip_tags((string) preg_replace('#<(style|script)\b.*?</\1>#is', '', $html));
            $this->assertStringContainsString('UVMASTER', $html, $url);
            // Seule mention conservée : la raison sociale par défaut du CMS (mention légale, décision en attente).
            $this->assertStringNotContainsStringIgnoringCase('modelec', str_replace("MODELEC Côte d'Ivoire", '', html_entity_decode($visible, ENT_QUOTES)), $url);
        }
    }

    public function test_sms_messages_are_signed_with_the_new_brand(): void
    {
        $sms = file_get_contents(base_path('app/Services/SmsService.php'));

        $this->assertStringContainsString("config('branding.name').\": Alerte dette!", $sms);
        $this->assertSame(0, preg_match('/"MODELEC/i', $sms));
    }
}
