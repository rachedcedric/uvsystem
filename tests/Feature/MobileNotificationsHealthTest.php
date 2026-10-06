<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Admin\NotificationsDiagnostic;
use App\Livewire\Mobile\NotificationWatcher;
use App\Models\Agent;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\TestNotification;
use App\Services\NotificationDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileNotificationsHealthTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function checkStatus(string $key, ?User $user = null): string
    {
        return collect(app(NotificationDiagnostics::class)->checks($user))->firstWhere('key', $key)['status'];
    }

    private function firebaseCredentials(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        $path = tempnam(sys_get_temp_dir(), 'firebase-');
        file_put_contents($path, json_encode(['type' => 'service_account', 'project_id' => 'modelec-test', 'client_email' => 'fb@example.iam.gserviceaccount.com', 'private_key' => $privateKey]));

        return $path;
    }

    public function test_scheduler_check_follows_the_heartbeat(): void
    {
        Cache::forget(NotificationDiagnostics::HEARTBEAT_KEY);
        $this->assertSame('error', $this->checkStatus('scheduler'));

        Cache::put(NotificationDiagnostics::HEARTBEAT_KEY, now()->timestamp);
        $this->assertSame('ok', $this->checkStatus('scheduler'));

        Cache::put(NotificationDiagnostics::HEARTBEAT_KEY, now()->subMinutes(20)->timestamp);
        $this->assertSame('error', $this->checkStatus('scheduler'));
    }

    public function test_the_scheduler_runs_a_heartbeat_and_the_queue_worker_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('scheduler-heartbeat')->expectsOutputToContain('queue:work')->assertSuccessful();

        $this->artisan('schedule:test', ['--name' => 'scheduler-heartbeat'])->assertSuccessful();
        $this->assertNotNull(Cache::get(NotificationDiagnostics::HEARTBEAT_KEY));
    }

    public function test_queue_check_detects_a_missing_worker_and_failed_jobs(): void
    {
        config(['queue.default' => 'database']);
        $this->assertSame('ok', $this->checkStatus('queue'));

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(10)->timestamp, 'created_at' => now()->subMinutes(10)->timestamp]);
        $this->assertSame('error', $this->checkStatus('queue'));

        DB::table('jobs')->delete();
        DB::table('failed_jobs')->insert(['uuid' => 'abc', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $this->assertSame('warn', $this->checkStatus('queue'));

        config(['queue.default' => 'sync']);
        $this->assertSame('ok', $this->checkStatus('queue'));
    }

    public function test_sms_check_requires_a_sender_number(): void
    {
        config(['services.sms.enabled' => false]);
        $this->assertSame('info', $this->checkStatus('sms'));

        config(['services.sms.enabled' => true, 'services.twilio.account_sid' => 'AC1', 'services.twilio.auth_token' => 't', 'services.twilio.from' => null]);
        $this->assertSame('error', $this->checkStatus('sms'));

        config(['services.twilio.from' => '+15005550006']);
        $this->assertSame('ok', $this->checkStatus('sms'));
    }

    public function test_mail_check_flags_a_missing_resend_key_and_log_mode(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => null]);
        $this->assertSame('error', $this->checkStatus('mail'));

        config(['mail.default' => 'log']);
        $this->assertSame('warn', $this->checkStatus('mail'));

        config(['mail.default' => 'resend', 'services.resend.key' => 're_123']);
        $this->assertSame('ok', $this->checkStatus('mail'));
    }

    public function test_push_check_walks_through_firebase_setup_stages(): void
    {
        $admin = $this->user('admin');
        config(['services.firebase.credentials' => null, 'services.firebase.project_id' => null]);
        $this->assertSame('error', $this->checkStatus('push', $admin));

        config(['services.firebase.credentials' => '/introuvable.json']);
        $this->assertSame('error', $this->checkStatus('push', $admin));

        config(['services.firebase.credentials' => $this->firebaseCredentials()]);
        $this->assertSame('warn', $this->checkStatus('push', $admin));

        PushToken::create(['user_id' => $admin->id, 'token' => 'tok', 'platform' => 'android']);
        $this->assertSame('ok', $this->checkStatus('push', $admin));
    }

    public function test_recipients_and_reminders_checks(): void
    {
        $this->assertSame('error', $this->checkStatus('recipients'));
        $this->user('master');
        $this->assertSame('ok', $this->checkStatus('recipients'));

        $this->assertSame('ok', $this->checkStatus('reminders'));
        $agent = Agent::factory()->create(['status' => 'active']);
        $agent->forceFill(['current_debt' => 100000])->saveQuietly();
        $this->assertSame('warn', $this->checkStatus('reminders'));
    }

    public function test_diagnostic_screen_is_reserved_to_admins_and_renders_every_check(): void
    {
        $this->actingAs($this->user('commercial'));
        Livewire::test(NotificationsDiagnostic::class)->assertForbidden();

        $this->actingAs($this->user('master'));
        Livewire::test(NotificationsDiagnostic::class)->assertSee('Planificateur')->assertSee('File d')->assertSee('Notifications push')->assertSee('SMS');
        $this->get(route('m.diagnostic-notifications'))->assertOk();
    }

    public function test_test_notification_reaches_the_bell_and_reports_each_push_device(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);
        config(['services.firebase.credentials' => $this->firebaseCredentials(), 'services.firebase.project_id' => null]);
        Cache::forget('firebase:access-token:'.sha1('fb@example.iam.gserviceaccount.com'));

        // Sans appareil : cloche seulement.
        Livewire::test(NotificationsDiagnostic::class)->call('sendTest')->assertSee('Aucun appareil enregistré');
        $this->assertSame(1, $admin->notifications()->count());

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok'], 200),
            'https://fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'ok'], 200)
                ->push(['error' => ['message' => 'Quota dépassé']], 429),
        ]);
        PushToken::create(['user_id' => $admin->id, 'token' => 'device-1', 'platform' => 'android']);

        Livewire::test(NotificationsDiagnostic::class)->call('sendTest')->assertSee('Push envoyé');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'messages:send') && $r['message']['android']['notification']['channel_id'] === 'debt-alerts-v2' && $r['message']['android']['notification']['sound'] === 'modelec_alert');

        Livewire::test(NotificationsDiagnostic::class)->call('sendTest')->assertSee('Push refusé');
    }

    public function test_test_mail_is_sent_to_the_connected_admin(): void
    {
        Mail::fake();
        $admin = $this->user('master');
        $this->actingAs($admin);

        Livewire::test(NotificationsDiagnostic::class)->call('sendTestMail')->assertSee($admin->email);
    }

    public function test_watcher_ignores_existing_notifications_and_announces_new_ones(): void
    {
        $admin = $this->user('commercial');
        $this->actingAs($admin);
        $admin->notifyNow(new TestNotification(push: false));

        $component = Livewire::test(NotificationWatcher::class)->assertSee('1');
        $component->call('check')->assertNotDispatched('m-notify');

        $admin->notifyNow(new TestNotification(push: false));
        $component->call('check')->assertDispatched('m-notify', count: 1);

        $component->call('check')->assertNotDispatched('m-notify');
    }

    public function test_watcher_badge_is_hidden_when_nothing_is_unread_and_shown_in_the_tab_bar(): void
    {
        $admin = $this->user('master');
        $this->actingAs($admin);

        Livewire::test(NotificationWatcher::class)->assertSeeHtml('hidden');
        $this->get(route('m.home'))->assertOk()->assertSee('m-tab__badge', false);
    }

    public function test_plus_screen_offers_sound_preferences_and_the_diagnostic_entry(): void
    {
        $this->actingAs($this->user('master'));

        $this->get(route('m.more'))->assertOk()
            ->assertSee('data-pref="m-sound"', false)->assertSee('data-sound-test="notify"', false)
            ->assertSee('Diagnostic des notifications');
    }
}
