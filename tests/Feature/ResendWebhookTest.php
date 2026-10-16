<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/webhooks/resend : signature Svix réelle, refus propres (jamais de 500), anti-rejeu avec bail,
 * relance après échec, et journaux sans adresse, sujet, lien ni secret. Tous les secrets sont synthétiques.
 */
class ResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'cle-synthetique-http-0123456789';

    private const ADDRESS = 'victime-synthetique@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.resend.webhook_secret' => 'whsec_'.base64_encode(self::KEY),
            'services.resend.webhook_tolerance' => 300,
            'services.resend.webhook_dedup_ttl' => 86400,
            'services.resend.webhook_lease' => 120,
            // Aucun appel sortant : un rebond dur ou une plainte déclenche une alerte admin qui, avec une vraie
            // clé dans l'environnement, appellerait l'API Resend.
            'services.resend.key' => null,
            'security.audit.notification_emails' => [],
            // Les événements de journal ne sont émis que pour les niveaux traités par le canal : on ne dépend pas de LOG_LEVEL.
            'logging.default' => 'null',
        ]);
    }

    // ------------------------------------------------------------------ aides

    private function sign(string $id, int|string $timestamp, string $body, string $key = self::KEY): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * @param  array{timestamp?: int|string, signature?: string, uri?: string, drop?: list<string>}  $options
     */
    private function send(string $id, array|string $payload, array $options = []): TestResponse
    {
        $body = is_array($payload) ? json_encode($payload) : $payload;
        $timestamp = $options['timestamp'] ?? time();

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => $options['signature'] ?? $this->sign($id, $timestamp, $body),
        ];

        foreach ($options['drop'] ?? [] as $header) {
            unset($server[$header]);
        }

        return $this->call('POST', $options['uri'] ?? '/api/webhooks/resend', [], [], [], $server, $body);
    }

    /** @return array<string, mixed> */
    private function delivered(): array
    {
        return ['type' => 'email.delivered', 'data' => ['email_id' => 'e1', 'to' => [self::ADDRESS], 'subject' => 'Sujet confidentiel']];
    }

    private function deliveredCount(): int
    {
        return (int) Cache::get('email_stats:delivered:'.now()->format('Y-m-d'), 0);
    }

    private function eventKey(string $id): string
    {
        return 'webhook:resend:'.$id;
    }

    // ------------------------------------------------------------------ requêtes authentiques

    public function test_a_genuinely_signed_event_is_processed_and_remembered(): void
    {
        $this->send('msg_ok_1', $this->delivered())->assertOk()->assertJson(['success' => true])->assertJsonMissing(['duplicate' => true]);

        $this->assertSame(1, $this->deliveredCount());
        $this->assertSame('done', Cache::get($this->eventKey('msg_ok_1')));
    }

    public function test_a_secret_rotation_header_with_several_signatures_is_accepted(): void
    {
        $body = json_encode($this->delivered());
        $header = 'v1,AAAAAAAA '.$this->sign('msg_rot', time(), $body);

        $this->send('msg_rot', $body, ['signature' => $header])->assertOk();
        $this->assertSame(1, $this->deliveredCount());
    }

    // ------------------------------------------------------------------ refus : toujours 401, jamais 500

    public function test_every_unauthentic_request_is_refused_with_a_401_and_no_side_effect(): void
    {
        $body = json_encode($this->delivered());
        $now = time();

        $cases = [
            'sans signature' => ['id' => 'msg_r01', 'o' => ['drop' => ['HTTP_SVIX_SIGNATURE']]],
            'sans svix-id' => ['id' => 'msg_r02', 'o' => ['drop' => ['HTTP_SVIX_ID']]],
            'sans horodatage' => ['id' => 'msg_r03', 'o' => ['drop' => ['HTTP_SVIX_TIMESTAMP']]],
            'version seule' => ['id' => 'msg_r04', 'o' => ['signature' => 'v1']],
            'virgule seule' => ['id' => 'msg_r05', 'o' => ['signature' => ',']],
            'ancien format v1=' => ['id' => 'msg_r06', 'o' => ['signature' => 'v1='.substr($this->sign('msg_r06', $now, $body), 3)]],
            'bruit de 10 Ko' => ['id' => 'msg_r07', 'o' => ['signature' => str_repeat('A', 10_000)]],
            'mauvaise clé' => ['id' => 'msg_r08', 'o' => ['signature' => $this->sign('msg_r08', $now, $body, 'autre-cle-synthetique')]],
            'secret brut utilisé comme clé' => ['id' => 'msg_r09', 'o' => ['signature' => $this->sign('msg_r09', $now, $body, 'whsec_'.base64_encode(self::KEY))]],
            'corps modifié' => ['id' => 'msg_r10', 'o' => ['signature' => $this->sign('msg_r10', $now, $body.' ')]],
            'horodatage -301 s' => ['id' => 'msg_r11', 'o' => ['timestamp' => $now - 301]],
            'horodatage +301 s' => ['id' => 'msg_r12', 'o' => ['timestamp' => $now + 301]],
            'horodatage non numérique' => ['id' => 'msg_r13', 'o' => ['timestamp' => 'abc', 'signature' => $this->sign('msg_r13', 'abc', $body)]],
            'svix-id invalide' => ['id' => 'msg r14', 'o' => []],
        ];

        foreach ($cases as $name => $case) {
            $this->send($case['id'], $body, $case['o'])
                ->assertStatus(401)
                ->assertExactJson(['error' => 'Invalid signature']);

            $this->assertFalse(Cache::has($this->eventKey($case['id'])), "« {$name} » ne doit rien réserver");
        }

        $this->assertSame(0, $this->deliveredCount(), 'aucun refus ne doit avoir d\'effet');
    }

    public function test_a_missing_secret_refuses_everything_outside_local_and_a_malformed_one_is_reported(): void
    {
        config(['services.resend.webhook_secret' => '']);
        $this->send('msg_nosecret', $this->delivered())->assertStatus(401);
        $this->assertSame(0, $this->deliveredCount());

        Log::spy();
        config(['services.resend.webhook_secret' => 'whsec_%%%pas-du-base64%%%']);
        $this->send('msg_badsecret', $this->delivered())->assertStatus(401);

        Log::shouldHaveReceived('log')
            ->withArgs(fn ($level, $message, $context) => $level === 'critical' && ($context['reason'] ?? null) === 'invalid_secret')
            ->once();
    }

    public function test_in_local_without_a_secret_the_webhook_stays_open_and_unsigned_calls_are_not_deduplicated(): void
    {
        config(['services.resend.webhook_secret' => '']);
        $this->app['env'] = 'local';

        $this->postJson('/api/webhooks/resend', $this->delivered())->assertOk();
        $this->postJson('/api/webhooks/resend', $this->delivered())->assertOk()->assertJsonMissing(['duplicate' => true]);

        $this->assertSame(2, $this->deliveredCount());
    }

    // ------------------------------------------------------------------ anti-rejeu

    public function test_a_replay_or_a_duplicate_delivery_is_acknowledged_but_not_processed_again(): void
    {
        $this->send('msg_dup', $this->delivered())->assertOk();

        // Capture rejouée telle quelle, puis relivraison Svix (même svix-id, nouvel horodatage et nouvelle signature).
        $this->send('msg_dup', $this->delivered(), ['timestamp' => time()])->assertOk()->assertJson(['duplicate' => true]);
        $this->send('msg_dup', $this->delivered(), ['timestamp' => time() - 10])->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, $this->deliveredCount());
    }

    public function test_an_event_being_processed_by_another_call_gets_a_409_and_is_left_untouched(): void
    {
        Cache::put($this->eventKey('msg_busy'), 'processing:autre-appel', 120);

        $this->send('msg_busy', $this->delivered())->assertStatus(409);

        $this->assertSame(0, $this->deliveredCount());
        $this->assertSame('processing:autre-appel', Cache::get($this->eventKey('msg_busy')));
    }

    public function test_an_expired_lease_lets_the_retry_through(): void
    {
        Cache::put($this->eventKey('msg_dead'), 'processing:appel-mort', 120);
        $this->travel(121)->seconds(); // le processus qui tenait le bail est mort : le bail expire seul

        $this->send('msg_dead', $this->delivered())->assertOk()->assertJsonMissing(['duplicate' => true]);

        $this->assertSame('done', Cache::get($this->eventKey('msg_dead')));
    }

    public function test_a_failed_event_releases_its_lease_so_that_the_retry_is_processed(): void
    {
        Schema::rename('activity_log', 'activity_log_hors_service'); // l'écriture du journal d'activité échoue : 500

        $this->send('msg_retry', $this->delivered())->assertStatus(500);
        $this->assertFalse(Cache::has($this->eventKey('msg_retry')), 'le bail doit être libéré après un échec');

        Schema::rename('activity_log_hors_service', 'activity_log');

        $this->send('msg_retry', $this->delivered())->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->assertSame('done', Cache::get($this->eventKey('msg_retry')));

        // Limite documentée dans le contrôleur : effets « au moins une fois ». Le compteur de statistiques,
        // incrémenté avant l'échec, l'a donc été deux fois.
        $this->assertSame(2, $this->deliveredCount());
    }

    public function test_a_failing_call_never_releases_a_lease_that_now_belongs_to_another_call(): void
    {
        Schema::rename('activity_log', 'activity_log_hors_service');

        // Pendant notre traitement, notre bail a expiré et un autre appel a pris le relais.
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            if ($log->message === 'Resend webhook received') {
                Cache::put($this->eventKey('msg_takeover'), 'processing:autre-appel', 120);
            }
        });

        $this->send('msg_takeover', $this->delivered())->assertStatus(500);

        $this->assertSame('processing:autre-appel', Cache::get($this->eventKey('msg_takeover')));
    }

    public function test_a_processed_event_is_remembered_for_the_configured_duration(): void
    {
        $this->send('msg_ttl', $this->delivered())->assertOk();

        $this->travel(86_399)->seconds();
        $this->send('msg_ttl', $this->delivered())->assertOk()->assertJson(['duplicate' => true]);

        // Limite documentée : passé ce délai, un doublon de livraison serait de nouveau traité.
        $this->travel(2)->seconds();
        $this->send('msg_ttl', $this->delivered())->assertOk()->assertJsonMissing(['duplicate' => true]);
    }

    public function test_the_memory_of_an_event_is_never_shorter_than_twice_the_tolerance(): void
    {
        config(['services.resend.webhook_dedup_ttl' => 10]); // trop court : ramené à 2 × 300 s

        $this->send('msg_min', $this->delivered())->assertOk();

        $this->travel(11)->seconds();
        $this->send('msg_min', $this->delivered())->assertOk()->assertJson(['duplicate' => true]);

        $this->travel(600)->seconds();
        $this->send('msg_min', $this->delivered())->assertOk()->assertJsonMissing(['duplicate' => true]);
    }

    // ------------------------------------------------------------------ données signées uniquement

    public function test_the_event_comes_from_the_signed_body_never_from_the_query_string(): void
    {
        $this->send('msg_query', '{}', ['uri' => '/api/webhooks/resend?type=email.delivered'])->assertOk();

        $this->assertSame(0, $this->deliveredCount());
    }

    public function test_a_signed_body_that_is_not_json_is_acknowledged_without_error(): void
    {
        $this->send('msg_nojson', 'ceci n\'est pas du json')->assertOk();

        $this->assertSame(0, $this->deliveredCount());
    }

    // ------------------------------------------------------------------ journaux

    public function test_logs_never_contain_addresses_subjects_links_or_secrets(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged): void {
            $logged[] = $log->message.' '.json_encode($log->context);
        });

        $this->send('msg_pii_1', ['type' => 'email.bounced', 'data' => ['email_id' => 'e1', 'to' => [self::ADDRESS], 'subject' => 'Sujet confidentiel', 'bounce_type' => 'Permanent']])->assertOk();
        $this->send('msg_pii_2', ['type' => 'email.complained', 'data' => ['email_id' => 'e2', 'to' => [self::ADDRESS], 'subject' => 'Sujet confidentiel']])->assertOk();
        $this->send('msg_pii_3', ['type' => 'email.clicked', 'data' => ['email_id' => 'e3', 'link' => 'https://exemple.test/jeton-secret-de-lien']])->assertOk();
        $this->send('msg_pii_4', ['type' => 'email.sent', 'data' => ['email_id' => 'e4', 'to' => [self::ADDRESS, 'autre-synthetique@example.com']]])->assertOk();
        $this->send('msg_pii_5', $this->delivered(), ['signature' => 'v1,'.base64_encode('signature-forgee-synthetique')])->assertStatus(401);

        $everything = implode("\n", $logged);
        $this->assertNotSame('', $everything);

        foreach ([self::ADDRESS, 'autre-synthetique@example.com', 'Sujet confidentiel', 'jeton-secret-de-lien', base64_encode(self::KEY), 'whsec_', base64_encode('signature-forgee-synthetique')] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everything, "le journal ne doit pas contenir « {$forbidden} »");
        }

        // Le nombre de destinataires, lui, est bien tracé.
        $this->assertStringContainsString('"recipient_count":2', $everything);

        // Entrée d'activité du webhook : nombre de destinataires, ni adresse ni sujet.
        $properties = DB::table('activity_log')->where('description', 'like', 'Webhook Resend:%')->pluck('properties')->implode("\n");
        $this->assertStringContainsString('recipient_count', $properties);
        $this->assertStringNotContainsString(self::ADDRESS, $properties);
        $this->assertStringNotContainsString('Sujet confidentiel', $properties);
    }

    public function test_an_exception_message_is_logged_without_the_email_addresses_it_contains(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged): void {
            $logged[] = ['message' => $log->message, 'context' => $log->context];

            if ($log->message === 'Resend webhook received') {
                throw new \RuntimeException('échec simulé pour '.self::ADDRESS);
            }
        });

        $this->send('msg_exc', $this->delivered())->assertStatus(500);

        $errors = array_values(array_filter($logged, fn ($entry) => $entry['message'] === 'Error processing Resend webhook'));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('[adresse masquée]', $errors[0]['context']['error']);
        $this->assertStringNotContainsString(self::ADDRESS, json_encode($errors[0]['context']));
        $this->assertArrayNotHasKey('trace', $errors[0]['context']);
        $this->assertFalse(Cache::has($this->eventKey('msg_exc')), 'le bail doit être libéré');
    }
}
