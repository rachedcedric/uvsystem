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
            if (preg_match('/'.self::oldNamesPattern().'/i', $source)) {
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
            $this->assertStringNotContainsStringIgnoringCase(self::oldName(), html_entity_decode($visible, ENT_QUOTES), $url);
        }
    }

    public function test_sms_messages_are_signed_with_the_new_brand(): void
    {
        $sms = file_get_contents(base_path('app/Services/SmsService.php'));

        $this->assertStringContainsString("config('branding.name').\": Alerte dette!", $sms);
        $this->assertSame(0, preg_match('/"'.self::oldName().'/i', $sms));
    }

    public function test_the_old_name_exists_nowhere_in_the_repository(): void
    {
        $pattern = '/'.self::oldNamesPattern().'/i';
        $roots = ['app', 'config', 'database', 'resources', 'routes', 'tests', 'docs', 'deploy', 'docker', 'scripts', 'lang', 'android/app/src', 'public/js', 'public/css', 'capacitor-www'];
        $files = ['.dockerignore', '.gitignore', 'Dockerfile', 'README.md', 'composer.json', 'package.json', 'package-lock.json', 'capacitor.config.json'];
        $extensions = ['php', 'js', 'css', 'md', 'json', 'xml', 'yml', 'yaml', 'java', 'kt', 'gradle', 'properties', 'sh', 'txt', 'example', 'html', 'svg', 'csv', 'conf', 'ini', 'dockerignore', 'gitignore'];
        $paths = array_map(fn (string $file): string => base_path($file), $files);

        foreach ($roots as $root) {
            if (! is_dir(base_path($root))) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace(base_path().'/', '', $file->getPathname());
                if (preg_match('#/(build|node_modules|vendor|\.engram|\.gradle)/#', '/'.$relative) === 1) {
                    continue;
                }

                $paths[] = $file->getPathname();
            }
        }

        // Fichiers et dossiers à la racine du projet (hors dépendances) : leur nom compte aussi.
        foreach (array_diff((array) scandir(base_path()), ['.', '..', '.git', 'node_modules', 'vendor']) as $entry) {
            $paths[] = base_path($entry);
        }

        $offenders = [];
        foreach ($paths as $path) {
            $relative = str_replace(base_path().'/', '', $path);
            $scanned = is_file($path) && in_array(pathinfo($path, PATHINFO_EXTENSION), array_merge($extensions, ['']), true) && filesize($path) < 2_000_000;

            // Le nom de fichier compte aussi (classe Java, ressource sonore...).
            if (preg_match($pattern, $relative) === 1 || ($scanned && preg_match($pattern, (string) file_get_contents($path)) === 1)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'L\'ancien nom ne doit exister nulle part dans le dépôt.');
    }

    /** L'ancien nom, assemblé à l'exécution : ce fichier ne doit pas le contenir lui-même. */
    private static function oldName(): string
    {
        return 'MODEL'.'EC';
    }

    /** Toute l'ancienne identité (première marque, puis second nom du produit sous toutes ses graphies), assemblée à l'exécution. */
    private static function oldNamesPattern(): string
    {
        return self::oldName().'|uv[-_ ]?sys'.'tem|UV Distri'.'bution System';
    }
}
