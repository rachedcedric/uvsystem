<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Composants réutilisables du design system mobile (docs/MOBILE-DESIGN-SYSTEM.md) : structure, accessibilité, échappement. */
class MobileDesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private function render(string $template): string
    {
        return Blade::render($template);
    }

    private function userWithRole(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        $tenant = Tenant::create(['name' => 'Société A']);

        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    public function test_status_badge_carries_its_meaning_in_text_and_escapes_it(): void
    {
        $html = $this->render('<x-m.status-badge tone="danger" label="Annulée" />');
        $this->assertStringContainsString('m-badge m-badge--danger', $html);
        $this->assertStringContainsString('>Annulée<', $html);

        $this->assertStringNotContainsString('<script>', $this->render('<x-m.status-badge label="<script>x</script>" />'));
        $this->assertStringContainsString('m-badge--muted', $this->render('<x-m.status-badge label="x" />'));
    }

    public function test_amount_is_always_complete_signed_and_flagged_when_cancelled(): void
    {
        $html = $this->render('<x-m.amount :value="1250000" sign="−" />');
        $this->assertStringContainsString('1 250 000', preg_replace('/\s+/u', ' ', str_replace("\u{202F}", ' ', str_replace("\u{00A0}", ' ', $html))));
        $this->assertStringContainsString('−', $html);
        $this->assertStringNotContainsString('is-cancelled', $html);
        $this->assertDoesNotMatchRegularExpression('/\d\s?[MkK]\b/u', strip_tags($html), 'jamais de montant abrégé');

        $cancelled = $this->render('<x-m.amount :value="500" :cancelled="true" unit />');
        $this->assertStringContainsString('is-cancelled', $cancelled);
        $this->assertStringContainsString('(annulé)', $cancelled);
        $this->assertStringContainsString('FCFA', $cancelled);
    }

    public function test_kv_list_omits_empty_values_and_escapes_the_rest(): void
    {
        $html = $this->render('<x-m.kv-list :items="[[\'Fournisseur\', \'Orange\'], [\'Notes\', null], [\'Vide\', \'\'], [\'Piège\', \'<b>x</b>\']]" />');

        $this->assertStringContainsString('Fournisseur', $html);
        $this->assertStringContainsString('Orange', $html);
        $this->assertStringNotContainsString('Notes', $html);
        $this->assertStringNotContainsString('Vide', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertSame('', trim($this->render('<x-m.kv-list :items="[[\'A\', null]]" />')));
    }

    public function test_confirm_sheet_is_an_accessible_dialog_whose_action_is_the_callers(): void
    {
        $html = $this->render('<x-m.confirm-sheet id="delete-sheet" title="Supprimer V1 ?" confirm="Oui" cancel="Non" text="Attention" wire:click="delete" />');

        $this->assertStringContainsString('id="delete-sheet"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-label="Supprimer V1 ?"', $html);
        $this->assertStringContainsString('wire:click="delete"', $html);
        $this->assertStringContainsString('MobileUI.closeSheets()', $html);
        $this->assertStringContainsString('data-sheet-close', $html);
        $this->assertStringContainsString('m-btn--danger', $html);
        $this->assertStringNotContainsString('<x', $this->render('<x-m.confirm-sheet id="s" title="<x>" />'));
    }

    public function test_sheet_action_opens_the_matching_sheet(): void
    {
        $html = $this->render('<x-m.sheet-action sheet="cancel-sheet" icon="x-circle" outline>Annuler</x-m.sheet-action>');

        $this->assertStringContainsString('data-sheet-open="cancel-sheet"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('m-danger-link--outline', $html);
        $this->assertStringContainsString('Annuler', $html);
    }

    public function test_callout_announces_only_real_alerts(): void
    {
        $this->assertStringContainsString('role="alert"', $this->render('<x-m.callout tone="danger">Stop</x-m.callout>'));
        $this->assertStringNotContainsString('role="alert"', $this->render('<x-m.callout>Info</x-m.callout>'));
        $this->assertStringContainsString('m-callout--warn', $this->render('<x-m.callout tone="warn">x</x-m.callout>'));
    }

    public function test_access_note_is_shown_to_read_only_accounts_only(): void
    {
        $readOnly = $this->userWithRole(User::ROLE_READ_ONLY);
        $this->actingAs($readOnly);
        $this->assertStringContainsString('Consultation seule', $this->render('<x-m.access-note />'));

        $master = TenantContext::runAs($readOnly->tenant_id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
        $this->actingAs($master);
        $this->assertSame('', trim($this->render('<x-m.access-note />')));
    }

    public function test_filter_bar_summarises_the_result_and_offers_reset_only_when_filtered(): void
    {
        $plain = $this->render('<x-m.filter-bar :periods="[\'week\' => \'7 jours\']" period="week" :count="1" noun="vente" nouns="ventes" total="100 FCFA" />');
        $this->assertStringContainsString('role="status"', $plain);
        $this->assertStringContainsString('aria-live="polite"', $plain);
        $this->assertStringContainsString('1 vente', $plain);
        $this->assertStringNotContainsString('1 ventes', $plain);
        $this->assertStringNotContainsString('Réinitialiser', $plain);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $plain);

        $filtered = $this->render('<x-m.filter-bar :count="3" noun="vente" nouns="ventes" :active="true" />');
        $this->assertStringContainsString('3 ventes', $filtered);
        $this->assertStringContainsString('wire:click="resetFilters"', $filtered);
    }

    public function test_skeleton_rows_are_announced_as_loading_and_accept_livewire_attributes(): void
    {
        $html = $this->render('<x-m.skeleton-rows :rows="2" wire:loading.delay />');

        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringContainsString('aria-label="Chargement"', $html);
        $this->assertStringContainsString('wire:loading.delay', $html);
        $this->assertSame(2, substr_count($html, 'class="m-row"'));
    }

    // ------------------------------------------------------------------ tokens de couleur (WCAG AA)

    /** @return array<string, array<string, string>> jetons du thème clair et du thème sombre (le sombre hérite du clair) */
    private function themes(): array
    {
        $css = (string) file_get_contents(base_path('resources/css/mobile.css'));
        preg_match('/:root\s*\{(.*?)\}/s', $css, $root);
        preg_match('/@media \(prefers-color-scheme: dark\)\s*\{\s*:root[^{]*\{(.*?)\}/s', $css, $dark);
        $parse = function (string $block): array {
            preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/', $block, $m, PREG_SET_ORDER);

            return array_column($m, 2, 1);
        };
        $light = $parse($root[1]);

        return ['clair' => $light, 'sombre' => array_merge($light, $parse($dark[1]))];
    }

    /** @return array{0: array{0: float, 1: float, 2: float}, 1: float} */
    private function color(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m)) {
            return [[hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))], 1.0];
        }
        preg_match('/rgba?\(([^)]*)\)/', $value, $m);
        $p = array_map('floatval', explode(',', $m[1]));

        return [[$p[0], $p[1], $p[2]], $p[3] ?? 1.0];
    }

    private function luminance(array $c): float
    {
        $f = fn (float $v): float => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    private function contrast(array $a, array $b): float
    {
        [$hi, $lo] = [max($this->luminance($a), $this->luminance($b)), min($this->luminance($a), $this->luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_text_colours_reach_wcag_aa_in_both_themes(): void
    {
        // [texte, fond, fond sous une teinte translucide (null = fond plein)]
        $pairs = [
            ['fg', 'bg', null], ['fg', 'surface', null], ['fg-2', 'surface', null], ['fg-3', 'surface', null], ['fg-3', 'bg', null],
            ['brand', 'surface', null], ['ok', 'ok-soft', 'surface'], ['warn', 'warn-soft', 'surface'], ['danger', 'danger-soft', 'surface'],
            ['brand', 'brand-soft', 'bg'], ['brand-fg', 'danger', null], ['brand-fg', 'brand', null],
        ];

        $failures = [];
        foreach ($this->themes() as $theme => $t) {
            foreach ($pairs as [$fgKey, $bgKey, $over]) {
                [$bg, $alpha] = $this->color($t[$bgKey]);
                if ($over !== null) {
                    [$base] = $this->color($t[$over]);
                    $bg = array_map(fn ($i) => $bg[$i] * $alpha + $base[$i] * (1 - $alpha), [0, 1, 2]);
                }
                [$fg] = $this->color($t[$fgKey]);
                $ratio = $this->contrast($fg, $bg);
                if ($ratio < 4.5) {
                    $failures[] = sprintf('%s : %s sur %s = %.2f:1', $theme, $fgKey, $bgKey, $ratio);
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_interactive_controls_keep_a_44px_touch_target(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/mobile.css'));

        $this->assertMatchesRegularExpression('/\.m-chip\s*\{[^}]*height:\s*44px/s', $css);
        $this->assertMatchesRegularExpression('/\.m-link\s*\{[^}]*min-height:\s*44px/s', $css);
        $this->assertMatchesRegularExpression('/\.m-danger-link\s*\{[^}]*height:\s*52px/s', $css);
    }
}
