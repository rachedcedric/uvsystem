<?php

use App\Exports\DettesExport;
use App\Filament\Pages\AgentDebtDetailPage;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function agentDebtDetailAdmin(): User
{
    foreach (['view_remboursements', 'create_remboursements'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    $role->givePermissionTo(['view_remboursements', 'create_remboursements']);

    $admin = User::factory()->create();
    $admin->assignRole('master');

    return $admin;
}

describe('AgentDebtDetailPage — permissions', function () {
    it('denies access without the view_remboursements permission', function () {
        $user = User::factory()->create();
        $user->syncRoles([]);
        $user->syncPermissions([]);

        expect(AgentDebtDetailPage::canAccess())->toBeFalse();
    });
});

describe('AgentDebtDetailPage — summary (4 tiles distinct from the global list)', function () {
    it('computes Dette du jour / Total dette / Remboursé / Reste à payer from the same sources as the list page', function () {
        $admin = agentDebtDetailAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 10, 0));
        \App\Models\Approvisionnement::create([
            'user_id' => $admin->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $service = app(UvDistributionService::class);

        // Vente d'hier : ne compte pas dans "Dette du jour", mais dans le total.
        Carbon::setTestNow(Carbon::create(2026, 6, 14, 12, 0));
        $service->distribute($agent, 100_000, $fournisseur);
        $agent->recalculateDebt();

        // Vente d'aujourd'hui (jour métier) : compte dans "Dette du jour" ET le total.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 10, 0));
        $service->distribute($agent, 60_000, $fournisseur);
        $agent->recalculateDebt();

        app(RemboursementService::class)->process($agent, 40_000);
        $agent->recalculateDebt();

        // $agent est réutilisé en mémoire depuis le début du test : sa
        // relation "solde" a pu être mise en cache (lazy-load) avant la
        // création du remboursement. En production, Filament recharge
        // toujours un modèle frais via le route model binding — on
        // reproduit ça ici pour ne pas tester un artefact de cache PHP.
        $agent = $agent->fresh();

        $component = Livewire::test(AgentDebtDetailPage::class, ['agent' => $agent]);
        $summary = $component->instance()->getSummary();

        expect($summary['detteDuJour'])->toEqualWithDelta(60_000.0, 0.01)
            ->and($summary['totalDette'])->toEqualWithDelta(160_000.0, 0.01)
            ->and($summary['rembourse'])->toEqualWithDelta(40_000.0, 0.01)
            ->and($summary['resteAPayer'])->toEqualWithDelta(120_000.0, 0.01);

        // Même source que la page liste (Agent::current_debt / AgentSolde).
        expect($summary['resteAPayer'])->toEqualWithDelta((float) $agent->fresh()->current_debt, 0.01);
    });
});

describe('AgentDebtDetailPage — full history for exports (not just the visible page)', function () {
    it('getFullHistory() returns every movement while getHistory() stays paginated', function () {
        $admin = agentDebtDetailAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();

        \App\Models\Approvisionnement::create([
            'user_id' => $admin->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $service = app(UvDistributionService::class);

        for ($i = 0; $i < 20; $i++) {
            $service->distribute($agent, 10_000, $fournisseur);
        }
        $agent->recalculateDebt();

        $page = new AgentDebtDetailPage;
        $page->mount($agent);

        expect($page->getFullHistory())->toHaveCount(20)
            ->and($page->getHistory()['items'])->toHaveCount(15)
            ->and($page->getHistory()['total'])->toBe(20);
    });
});

describe('DettesExport — numeric amounts, full dataset', function () {
    it('produces a summary block plus one numeric row per movement', function () {
        $admin = agentDebtDetailAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();

        \App\Models\Approvisionnement::create([
            'user_id' => $admin->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($agent, 100_000, $fournisseur);
        $agent->recalculateDebt();
        app(RemboursementService::class)->process($agent, 30_000);
        $agent->recalculateDebt();

        $page = new AgentDebtDetailPage;
        $page->mount($agent);

        $export = new DettesExport($agent, $page->getSummary(), $page->getFullHistory());
        $rows = $export->array();

        // 3 lignes d'identification + 1 vide + 4 lignes de résumé + 1 vide + 1 en-tête + 2 mouvements.
        expect($rows)->toHaveCount(12);

        $summaryRows = collect($rows)->slice(4, 4)->values();
        expect($summaryRows[3][1])->toBeFloat()->toEqual(70_000.0); // Reste à payer, valeur numérique

        $historyRows = collect($rows)->slice(10)->values();
        expect($historyRows)->toHaveCount(2);
        foreach ($historyRows as $row) {
            expect($row[2] === null || is_float($row[2]))->toBeTrue();
        }
    });
});

describe('exports.dette-agent-pdf view renders without error', function () {
    it('renders the PDF Blade template with a real agent and history', function () {
        $admin = agentDebtDetailAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();

        \App\Models\Approvisionnement::create([
            'user_id' => $admin->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($agent, 100_000, $fournisseur);
        $agent->recalculateDebt();

        $page = new AgentDebtDetailPage;
        $page->mount($agent);

        $html = view('exports.dette-agent-pdf', [
            'agent' => $agent,
            'summary' => $page->getSummary(),
            'history' => $page->getFullHistory(),
            'generatedAt' => now(),
            'generatedBy' => $admin->name,
        ])->render();

        expect($html)->toContain($agent->name)
            ->toContain('Reste à payer');
    });
});
