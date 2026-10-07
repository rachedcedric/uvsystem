<?php

namespace Tests\Feature\Subscriptions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Rattrapage du journal de notifications pour les bases créées avec l'ancienne version de la migration d'abonnements. */
class SubscriptionCatchUpMigrationTest extends SubscriptionTestCase
{
    private const COLUMNS = ['notification', 'payload', 'attempts', 'claimed_at', 'failed_at', 'last_error'];

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000100_add_reliability_columns_to_subscription_notification_logs.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Test de DDL : SQLite uniquement (transaction de test).');
        }
    }

    public function test_an_old_database_gets_the_missing_columns_and_keeps_its_rows(): void
    {
        DB::table('subscription_notification_logs')->insert(['tenant_id' => 1, 'type' => 'old', 'dedup_key' => 'old:1', 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('subscription_notification_logs', fn ($t) => $t->dropColumn(self::COLUMNS)); // état de l'ancienne version
        $this->assertFalse(Schema::hasColumn('subscription_notification_logs', 'attempts'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('subscription_notification_logs', self::COLUMNS));
        $row = DB::table('subscription_notification_logs')->where('dedup_key', 'old:1')->first();
        $this->assertSame(0, (int) $row->attempts, 'ligne existante conservée, tentative à zéro');
        $this->assertNull($row->failed_at);
    }

    public function test_it_is_idempotent_and_harmless_on_a_fresh_database_or_without_the_table(): void
    {
        $this->migration()->up(); // colonnes déjà présentes : aucun effet, aucune erreur
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumns('subscription_notification_logs', self::COLUMNS));

        Schema::drop('subscription_notification_logs'); // installation neuve : la table n'existe pas encore
        $this->migration()->up();
        $this->assertFalse(Schema::hasTable('subscription_notification_logs'));
    }

    public function test_the_notifier_works_again_after_the_catch_up(): void
    {
        Schema::table('subscription_notification_logs', fn ($t) => $t->dropColumn(self::COLUMNS));
        $this->migration()->up();

        $tenant = $this->tenant();
        $this->userIn($tenant);
        \Illuminate\Support\Facades\Notification::fake();
        $subscription = app(\App\Services\Subscriptions\SubscriptionManager::class)->startTrial($tenant, $this->plan('pro'));

        $log = DB::table('subscription_notification_logs')->where('tenant_subscription_id', $subscription->id)->first();
        $this->assertNotNull($log->sent_at);
        $this->assertSame(1, (int) $log->attempts);
    }
}
