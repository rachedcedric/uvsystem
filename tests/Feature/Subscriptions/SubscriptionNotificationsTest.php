<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionNotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Subscriptions\SubscriptionExpired;
use App\Notifications\Subscriptions\SubscriptionNotification;
use App\Notifications\Subscriptions\TrialStarted;
use App\Services\Subscriptions\SubscriptionLifecycleProcessor;
use App\Services\Subscriptions\SubscriptionManager;
use App\Services\Subscriptions\SubscriptionNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/** Risque « notifications » : envoi synchrone, échec conservé et repris, aucun doublon, état métier jamais altéré. */
class SubscriptionNotificationsTest extends SubscriptionTestCase
{
    private SubscriptionManager $manager;

    private Tenant $tenant;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToDate('2026-10-10 02:00:00');
        $this->manager = app(SubscriptionManager::class);
        $this->tenant = $this->tenant();
        $this->master = $this->userIn($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Remplace le facade Notification par un envoi qui échoue tant que $failing est vrai. */
    private function flakyMailer(bool &$failing): void
    {
        Notification::swap(new class($failing)
        {
            public array $delivered = [];

            public function __construct(private bool &$failing) {}

            public function send($notifiables, $notification): void
            {
                if ($this->failing) {
                    throw new \RuntimeException('SMTP indisponible');
                }
                $this->delivered[] = $notification::class;
            }
        });
    }

    public function test_subscription_notifications_are_synchronous_never_queued(): void
    {
        foreach (glob(app_path('Notifications/Subscriptions/*.php')) as $file) {
            $class = 'App\\Notifications\\Subscriptions\\'.basename($file, '.php');
            if (! is_subclass_of($class, SubscriptionNotification::class)) {
                continue;
            }
            $this->assertFalse(is_subclass_of($class, ShouldQueue::class), "$class ne doit pas être mise en file");
        }

        Queue::fake();
        Notification::fake();
        $this->manager->startTrial($this->tenant, $this->plan('pro'));

        Queue::assertNothingPushed();
        Notification::assertSentTo($this->master, TrialStarted::class);
    }

    public function test_a_send_failure_is_persisted_and_never_alters_the_business_state(): void
    {
        $failing = true;
        $this->flakyMailer($failing);
        Log::spy();
        $sub = $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 1);
        $this->travelToDate('2026-10-12 02:00:00');

        $this->artisan('subscriptions:process')->assertSuccessful();

        // L'état métier est exact malgré l'échec.
        $this->assertSame(SubscriptionStatus::Expired, $sub->fresh()->status);
        // L'échec est CONSERVÉ avec sa trace (pas effacé).
        $log = SubscriptionNotificationLog::withoutGlobalScopes()->where('type', SubscriptionExpired::type())->firstOrFail();
        $this->assertNull($log->sent_at);
        $this->assertNotNull($log->failed_at);
        $this->assertStringContainsString('SMTP indisponible', $log->last_error);
        $this->assertSame(1, $log->attempts);
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'notification d\'abonnement'))->atLeast()->once();
    }

    public function test_a_failed_notification_is_retried_at_the_next_run_and_sent_once(): void
    {
        $failing = true;
        $this->flakyMailer($failing);
        $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 1);
        $this->travelToDate('2026-10-12 02:00:00');
        $this->artisan('subscriptions:process');

        $failing = false; // le service de messagerie revient
        Carbon::setTestNow(now()->addHour()); // le passage suivant a lieu plus tard
        $this->artisan('subscriptions:process')->assertSuccessful();
        Carbon::setTestNow(now()->addHour());
        $this->artisan('subscriptions:process')->assertSuccessful(); // un troisième passage n'envoie plus rien

        $log = SubscriptionNotificationLog::withoutGlobalScopes()->where('type', SubscriptionExpired::type())->firstOrFail();
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->failed_at);
        $this->assertSame(2, $log->attempts);
        $this->assertSame([SubscriptionExpired::class], array_values(array_filter(Notification::getFacadeRoot()->delivered, fn ($c) => $c === SubscriptionExpired::class)));
    }

    public function test_retries_stop_after_the_maximum_number_of_attempts(): void
    {
        config(['subscriptions.notification_max_attempts' => 3]);
        $failing = true;
        $this->flakyMailer($failing);
        $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 1);
        $this->travelToDate('2026-10-12 02:00:00');

        foreach (range(1, 6) as $i) {
            Carbon::setTestNow(now()->addHour());
            $this->artisan('subscriptions:process');
            SubscriptionNotificationLog::withoutGlobalScopes()->update(['claimed_at' => null]);
        }

        $log = SubscriptionNotificationLog::withoutGlobalScopes()->where('type', SubscriptionExpired::type())->firstOrFail();
        $this->assertSame(3, $log->attempts, 'jamais plus de tentatives que le maximum');
        $this->assertNull($log->sent_at);
        $this->assertNotNull($log->failed_at, 'la trace de l\'échec définitif reste consultable');
    }

    public function test_two_concurrent_workers_cannot_both_send_the_same_notification(): void
    {
        Notification::fake();
        $sub = $this->manager->startTrial($this->tenant, $this->plan('pro'));
        Notification::fake(); // repart de zéro : seul ce qui suit est compté
        $notifier = app(SubscriptionNotifier::class);
        // Simule un premier processus qui a déjà « réclamé » la notification et n'a pas encore fini.
        SubscriptionNotificationLog::withoutGlobalScopes()->where('type', 'trial_started')->update(['sent_at' => null, 'attempts' => 1, 'claimed_at' => now()]);

        $this->assertFalse($notifier->send($sub, TrialStarted::class, "trial_started:{$sub->id}", []));
        Notification::assertSentToTimes($this->master, TrialStarted::class, 0);
    }

    public function test_the_same_notification_is_never_sent_twice(): void
    {
        Notification::fake();
        $sub = $this->manager->startTrial($this->tenant, $this->plan('pro'));
        $notifier = app(SubscriptionNotifier::class);

        $this->assertFalse($notifier->send($sub, TrialStarted::class, "trial_started:{$sub->id}"));
        $this->assertFalse($notifier->send($sub, TrialStarted::class, "trial_started:{$sub->id}"));

        Notification::assertSentToTimes($this->master, TrialStarted::class, 1);
    }

    public function test_the_report_counts_notification_failures_and_retries(): void
    {
        $failing = true;
        $this->flakyMailer($failing);
        $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 1);
        $this->travelToDate('2026-10-12 02:00:00');

        $report = app(SubscriptionLifecycleProcessor::class)->run();

        $this->assertSame(1, $report['expired']);
        $this->assertGreaterThanOrEqual(1, $report['notification_failures']);
    }
}
