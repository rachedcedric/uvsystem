<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\DebtAlertNotification;
use App\Notifications\DebtReminderNotification;
use App\Services\FirebaseMessaging;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_can_register_and_remove_an_android_push_token(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);

        $this->postJson('/m/push-token', ['token' => 'fcm-device-token', 'platform' => 'android'])
            ->assertNoContent();
        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $admin->id,
            'token' => 'fcm-device-token',
            'platform' => 'android',
        ]);

        $this->deleteJson('/m/push-token', ['token' => 'fcm-device-token'])->assertNoContent();
        $this->assertDatabaseMissing('push_tokens', ['token' => 'fcm-device-token']);
    }

    public function test_logout_removes_only_the_current_device_push_token(): void
    {
        $admin = $this->userWithRole('admin');
        $currentDevice = PushToken::create(['user_id' => $admin->id, 'token' => 'current-device', 'platform' => 'android']);
        PushToken::create(['user_id' => $admin->id, 'token' => 'other-device', 'platform' => 'android']);

        $this->actingAs($admin)
            ->withSession(['push_token' => $currentDevice->token])
            ->post('/m/logout')
            ->assertRedirect('/m/login');

        $this->assertDatabaseMissing('push_tokens', ['token' => 'current-device']);
        $this->assertDatabaseHas('push_tokens', ['token' => 'other-device']);
    }

    public function test_only_admins_can_register_push_tokens(): void
    {
        $commercial = $this->userWithRole('commercial');

        $this->actingAs($commercial)
            ->postJson('/m/push-token', ['token' => 'fcm-device-token', 'platform' => 'android'])
            ->assertForbidden();

        $this->assertDatabaseMissing('push_tokens', ['token' => 'fcm-device-token']);
    }

    public function test_debt_notifications_use_the_firebase_push_channel(): void
    {
        $admin = $this->userWithRole('admin');
        $agent = Agent::factory()->create(['current_debt' => 700000, 'debt_limit' => 1000000]);
        $alert = new DebtAlertNotification($agent, 'critical', 90);
        $reminder = new DebtReminderNotification($agent, '2026-10-05-00', 1000000, 300000, 700000, 'Partiellement remboursée');

        $this->assertContains(FirebasePushChannel::class, $alert->via($admin));
        $this->assertContains(FirebasePushChannel::class, $reminder->via($admin));
        $this->assertSame('/m/dettes/agent/'.$agent->id, $alert->pushPayload()['url']);
        $this->assertSame('/m/dettes/agent/'.$agent->id, $reminder->pushPayload()['url']);
        $this->assertStringContainsString('700 000 FCFA', $reminder->pushPayload()['body']);
    }

    public function test_debt_alerts_are_sent_to_admins_and_not_commercial_users(): void
    {
        Notification::fake();
        $admin = $this->userWithRole('admin');
        $commercial = $this->userWithRole('commercial');
        $agent = Agent::factory()->create(['current_debt' => 900000, 'debt_limit' => 1000000]);

        app(NotificationService::class)->sendDebtAlert($agent, 'critical', 90);

        Notification::assertSentTo($admin, DebtAlertNotification::class);
        Notification::assertNotSentTo($commercial, DebtAlertNotification::class);
    }

    public function test_firebase_message_is_sent_with_an_oauth_service_account_token(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $path = tempnam(sys_get_temp_dir(), 'firebase-credentials-');
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'modelec-test',
            'client_email' => 'firebase-test@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ], JSON_THROW_ON_ERROR));
        config(['services.firebase.credentials' => $path, 'services.firebase.project_id' => null]);
        Cache::forget('firebase:access-token:'.sha1('firebase-test@example.iam.gserviceaccount.com'));
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token'], 200),
            'https://fcm.googleapis.com/v1/projects/modelec-test/messages:send' => Http::response(['name' => 'projects/modelec-test/messages/1'], 200),
        ]);

        try {
            $admin = $this->userWithRole('admin');
            $device = PushToken::create([
                'user_id' => $admin->id,
                'token' => 'valid-device-token',
                'platform' => 'android',
            ]);

            app(FirebaseMessaging::class)->send($device, [
                'title' => 'Dette critique',
                'body' => '700 000 FCFA restant dû',
                'url' => '/m/dettes',
                'type' => 'agent_debt_alert',
            ]);

            Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/modelec-test/messages:send'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['message']['token'] === 'valid-device-token'
                && $request['message']['notification']['title'] === 'Dette critique'
                && $request['message']['data']['url'] === '/m/dettes');
            $this->assertNotNull($device->fresh()->last_used_at);
        } finally {
            unlink($path);
        }
    }

    public function test_unregistered_firebase_token_is_deleted(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        $path = tempnam(sys_get_temp_dir(), 'firebase-credentials-');
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'modelec-test',
            'client_email' => 'firebase-test@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ], JSON_THROW_ON_ERROR));
        config(['services.firebase.credentials' => $path, 'services.firebase.project_id' => null]);
        Cache::forget('firebase:access-token:'.sha1('firebase-test@example.iam.gserviceaccount.com'));
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token'], 200),
            'https://fcm.googleapis.com/v1/projects/modelec-test/messages:send' => Http::response([
                'error' => [
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ], 404),
        ]);

        try {
            $admin = $this->userWithRole('admin');
            $device = PushToken::create(['user_id' => $admin->id, 'token' => 'expired-device-token', 'platform' => 'android']);

            app(FirebaseMessaging::class)->send($device, [
                'title' => 'Dette critique',
                'body' => 'Dette agent',
                'url' => '/m/dettes',
                'type' => 'agent_debt_alert',
            ]);

            $this->assertDatabaseMissing('push_tokens', ['id' => $device->id]);
        } finally {
            unlink($path);
        }
    }
}
