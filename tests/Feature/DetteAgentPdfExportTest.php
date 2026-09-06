<?php

use App\Models\Agent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Régression : dompdf (v3.1.4, cette installation) génère des pages
 * blanches en boucle quand un élément "position: fixed" (utilisé pour
 * répéter un en-tête/pied de page) coexiste avec du texte contenant
 * certains caractères courants (apostrophe notamment — fréquente dans les
 * noms d'agents ivoiriens, ex. "N'Guessan") : jusqu'à 8 pages générées pour
 * 3 lignes de données réelles, constaté et reproduit lors du diagnostic.
 * Le template exports/dette-agent-pdf.blade.php a été réécrit sans aucun
 * "position: fixed" pour l'éviter structurellement — ces tests verrouillent
 * à la fois le nombre de pages réel et l'absence de la CSS problématique.
 */
function countPdfPages(string $binary): int
{
    return preg_match_all('/\/Type\s*\/Page[^s]/', $binary);
}

describe('Dette agent PDF export — no runaway blank pages', function () {
    it('renders a single page for a small dataset', function () {
        $agent = Agent::factory()->create(['name' => 'Agent Démo', 'phone' => '77000001']);

        $pdf = Pdf::loadView('exports.dette-agent-pdf', [
            'agent' => $agent,
            'summary' => ['detteDuJour' => 50_000, 'totalDette' => 500_000, 'rembourse' => 100_000, 'resteAPayer' => 400_000],
            'history' => collect([
                (object) ['type' => 'distribution', 'date_operation' => now(), 'amount' => 50_000, 'remboursement_amount' => null, 'solde_uv_avant' => 1_000_000, 'solde_uv_apres' => 950_000, 'reference' => 'V-ABC123'],
                (object) ['type' => 'remboursement', 'date_operation' => now(), 'amount' => null, 'remboursement_amount' => 100_000, 'solde_uv_avant' => null, 'solde_uv_apres' => null, 'reference' => 'R-DEF456'],
            ]),
            'generatedAt' => now(),
            'generatedBy' => 'Test',
        ])->setPaper('a4', 'portrait');

        expect(countPdfPages($pdf->output()))->toBe(1);
    });

    it('does not multiply pages when the agent name contains an apostrophe', function () {
        $agent = Agent::factory()->create(['name' => "Koffi N'Guessan", 'phone' => '0700000000']);

        $pdf = Pdf::loadView('exports.dette-agent-pdf', [
            'agent' => $agent,
            'summary' => ['detteDuJour' => 0, 'totalDette' => 100_000, 'rembourse' => 50_000, 'resteAPayer' => 50_000],
            'history' => collect(),
            'generatedAt' => now(),
            'generatedBy' => 'Test',
        ])->setPaper('a4', 'portrait');

        expect(countPdfPages($pdf->output()))->toBe(1);
    });

    it('paginates a large history at a reasonable rate instead of one page per row', function () {
        // dompdf est gourmand en mémoire ; au sein de la suite complète (des
        // centaines de tests dans le même process PHP), la limite par
        // défaut peut être atteinte avant ce test précis sans rapport avec
        // une régression de ce template — on l'augmente localement, comme
        // le ferait tout appel réel à une génération PDF en production.
        ini_set('memory_limit', '256M');

        $agent = Agent::factory()->create(['name' => "N'Da Test"]);

        $history = collect(range(0, 39))->map(fn (int $i) => (object) [
            'type' => $i % 3 === 0 ? 'remboursement' : 'distribution',
            'date_operation' => now()->subDays($i),
            'amount' => $i % 3 === 0 ? null : 10_000 + $i * 100,
            'remboursement_amount' => $i % 3 === 0 ? 5_000 + $i * 50 : null,
            'solde_uv_avant' => $i % 3 === 0 ? null : 1_000_000 - $i * 1_000,
            'solde_uv_apres' => $i % 3 === 0 ? null : 1_000_000 - ($i + 1) * 1_000,
            'reference' => ($i % 3 === 0 ? 'R-' : 'V-').$i,
        ]);

        $pdf = Pdf::loadView('exports.dette-agent-pdf', [
            'agent' => $agent,
            'summary' => ['detteDuJour' => 10_000, 'totalDette' => 5_000_000, 'rembourse' => 2_000_000, 'resteAPayer' => 3_000_000],
            'history' => $history,
            'generatedAt' => now(),
            'generatedBy' => 'Test',
        ])->setPaper('a4', 'portrait');

        // 80 lignes ~= quelques pages de tableau, jamais des dizaines de
        // pages quasi vides comme lors du bug (8 pages pour 3 lignes).
        expect(countPdfPages($pdf->output()))->toBeGreaterThan(0)->toBeLessThanOrEqual(6);
    });

    it('never reintroduces a "position: fixed" CSS declaration in this template (root cause of the blank-page bug)', function () {
        $source = file_get_contents(resource_path('views/exports/dette-agent-pdf.blade.php'));

        // Recherche la déclaration CSS réelle (avec point-virgule), pas la
        // mention explicative dans les commentaires du fichier.
        expect($source)->not->toMatch('/position\s*:\s*fixed\s*;/i');
    });
});
