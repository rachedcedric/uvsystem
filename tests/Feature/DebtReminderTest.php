<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Notifications\DebtReminderNotification;
use App\Services\NotificationService;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DebtReminderTest extends TestCase
{
    use RefreshDatabase;

    protected NotificationService $service;

    protected User $admin;

    protected Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00'));

        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'commercial', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('master');

        $this->service = app(NotificationService::class);
        $this->fournisseur = Fournisseur::factory()->create();

        Approvisionnement::create([
            'user_id' => $this->admin->id,
            'fournisseur_id' => $this->fournisseur->id,
            'amount' => 100_000_000,
            'date_approvisionnement' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function creerAgentAvecDette(float $amount, string $name = 'Agent Test'): Agent
    {
        $agent = Agent::factory()->create(['name' => $name, 'status' => 'active', 'debt_limit' => 0]);
        app(UvDistributionService::class)->distribute($agent, $amount, $this->fournisseur);
        $agent->recalculateDebt();

        return $agent->fresh();
    }

    /** Agent sans dette : jamais notifié */
    public function test_agent_without_debt_is_never_reminded(): void
    {
        Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);

        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(0, $result['eligible']);
        $this->assertSame(0, DatabaseNotification::where('type', DebtReminderNotification::class)->count());
    }

    /** Agent avec dette active : reçoit un rappel complet */
    public function test_agent_with_active_debt_receives_a_reminder(): void
    {
        $agent = $this->creerAgentAvecDette(150_000, 'KOFFI Jean');

        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(1, $result['eligible']);
        $this->assertSame(1, $result['sent']);

        $notif = DatabaseNotification::where('type', DebtReminderNotification::class)->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('KOFFI Jean', $notif->data['title']);
        $this->assertStringContainsString('150 000 FCFA', $notif->data['title']);
        $this->assertSame("/admin/remboursements/create?agent_id={$agent->id}", $notif->data['url']);
        $this->assertSame($agent->id, $notif->data['agent_id']);
        $this->assertSame('En cours', $notif->data['debt_status_label']);
        $this->assertSame($this->admin->id, $notif->notifiable_id);
    }

    /** Dette partiellement remboursée : toujours éligible, statut adapté */
    public function test_partially_repaid_debt_is_still_eligible(): void
    {
        $agent = $this->creerAgentAvecDette(200_000);
        app(RemboursementService::class)->process($agent, 50_000);
        $agent->recalculateDebt();

        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(1, $result['sent']);

        $notif = DatabaseNotification::where('type', DebtReminderNotification::class)->first();
        $this->assertSame('Partiellement remboursée', $notif->data['debt_status_label']);
        $this->assertEqualsWithDelta(150_000, $notif->data['reste_du'], 0.01);
    }

    /** Dette entièrement soldée : aucun rappel */
    public function test_fully_repaid_debt_is_never_reminded(): void
    {
        $agent = $this->creerAgentAvecDette(100_000);
        app(RemboursementService::class)->process($agent, 100_000);
        $agent->recalculateDebt();

        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(0, $result['eligible']);
        $this->assertSame(0, DatabaseNotification::where('type', DebtReminderNotification::class)->count());
    }

    /** Plusieurs agents endettés : chacun reçoit son propre rappel */
    public function test_multiple_indebted_agents_each_get_their_own_reminder(): void
    {
        $a = $this->creerAgentAvecDette(100_000, 'Agent A');
        $b = $this->creerAgentAvecDette(200_000, 'Agent B');

        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(2, $result['sent']);
        $this->assertSame(2, DatabaseNotification::where('type', DebtReminderNotification::class)->count());
    }

    /** Plusieurs ventes impayées pour le même agent => un seul rappel groupé */
    public function test_multiple_debts_for_the_same_agent_are_grouped_into_one_reminder(): void
    {
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $service = app(UvDistributionService::class);
        $service->distribute($agent, 100_000, $this->fournisseur);
        $service->distribute($agent, 150_000, $this->fournisseur);
        $service->distribute($agent, 175_000, $this->fournisseur);
        $agent->recalculateDebt();

        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(1, $result['sent']);
        $notif = DatabaseNotification::where('type', DebtReminderNotification::class)->first();
        $this->assertEqualsWithDelta(425_000, $notif->data['reste_du'], 0.01);
    }

    /** Aucun doublon dans le même cycle de 2h */
    public function test_no_duplicate_within_the_same_two_hour_cycle(): void
    {
        $this->creerAgentAvecDette(150_000);

        $this->service->checkAndSendDebtReminders();
        Carbon::setTestNow(now()->addMinutes(30)); // toujours dans le cycle 08h-10h
        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped_duplicate']);
        $this->assertSame(1, DatabaseNotification::where('type', DebtReminderNotification::class)->count());
    }

    /** Nouveau rappel au cycle suivant (2h plus tard) */
    public function test_new_reminder_on_the_next_two_hour_cycle(): void
    {
        $this->creerAgentAvecDette(150_000);

        $this->service->checkAndSendDebtReminders(); // cycle 08h
        Carbon::setTestNow(now()->addHours(2));       // cycle 10h
        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(2, DatabaseNotification::where('type', DebtReminderNotification::class)->count());
    }

    /** Remboursement intégral entre deux cycles => arrêt immédiat des rappels */
    public function test_reminders_stop_immediately_after_full_repayment(): void
    {
        $agent = $this->creerAgentAvecDette(150_000);
        $this->service->checkAndSendDebtReminders(); // cycle 08h : 1 rappel

        app(RemboursementService::class)->process($agent, 150_000);
        $agent->recalculateDebt();

        Carbon::setTestNow(now()->addHours(2)); // cycle 10h
        $result = $this->service->checkAndSendDebtReminders();

        $this->assertSame(0, $result['eligible']);
        $this->assertSame(0, $result['sent']);
        // Le rappel du cycle précédent reste dans l'historique, mais aucun nouveau.
        $this->assertSame(1, DatabaseNotification::where('type', DebtReminderNotification::class)->count());
    }

    /** Le widget affiche les rappels du plus récent au plus ancien */
    public function test_reminders_are_ordered_most_recent_first_like_the_widget(): void
    {
        $a = $this->creerAgentAvecDette(50_000, 'Agent Ancien');
        $this->service->checkAndSendDebtReminders();

        // Soldée avant le cycle suivant : ne doit plus générer de rappel,
        // pour isoler sans ambiguïté le rappel le plus récent (agent B).
        app(RemboursementService::class)->process($a, 50_000);
        $a->recalculateDebt();

        Carbon::setTestNow(now()->addHours(2));
        $b = $this->creerAgentAvecDette(80_000, 'Agent Recent');
        $this->service->checkAndSendDebtReminders();

        $ordered = DatabaseNotification::where('type', DebtReminderNotification::class)
            ->where('notifiable_id', $this->admin->id)
            ->latest()
            ->get();

        $this->assertSame($b->id, $ordered->first()->data['agent_id']);
        $this->assertSame($a->id, $ordered->last()->data['agent_id']);
    }
}
