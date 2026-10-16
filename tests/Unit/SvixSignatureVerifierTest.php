<?php

namespace Tests\Unit;

use App\Services\Webhooks\SvixSignatureVerifier as Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Resend\WebhookSignature;

/**
 * Vérificateur Svix (Resend) : format réel des secrets et des en-têtes, tolérance de date, refus propres.
 * Tous les secrets sont synthétiques.
 */
class SvixSignatureVerifierTest extends TestCase
{
    private const KEY = 'cle-synthetique-unitaire-0123456789';

    private const ID = 'msg_unit_0001';

    private const NOW = 1_800_000_000;

    private const BODY = '{"type":"email.bounced","data":{"email_id":"e1"}}';

    private function secret(): string
    {
        return 'whsec_'.base64_encode(self::KEY);
    }

    private function sign(string $id = self::ID, int|string $timestamp = self::NOW, string $body = self::BODY, string $key = self::KEY): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    private function reason(?string $signature, ?string $id = self::ID, int|string|null $timestamp = self::NOW, string $body = self::BODY, ?string $secret = null, int $tolerance = 300, ?int $now = self::NOW): ?string
    {
        return (new Verifier($secret ?? $this->secret(), $tolerance))
            ->failureReason($id, $timestamp === null ? null : (string) $timestamp, $signature, $body, $now);
    }

    // ---------------------------------------------------------------- signatures valides

    public function test_accepts_a_genuine_svix_signature(): void
    {
        $this->assertNull($this->reason($this->sign()));
        $this->assertTrue((new Verifier($this->secret()))->verify(self::ID, (string) self::NOW, $this->sign(), self::BODY, self::NOW));
    }

    public function test_accepts_the_matching_signature_among_several_space_separated_ones(): void
    {
        $other = $this->sign(key: 'ancienne-cle-synthetique-rotation');

        $this->assertNull($this->reason("{$other} {$this->sign()}"));
        $this->assertNull($this->reason("{$this->sign()} {$other}"));
        $this->assertNull($this->reason("  {$other}   {$this->sign()}  "));
    }

    public function test_ignores_unsupported_versions_but_accepts_a_v1_next_to_them(): void
    {
        $v1 = $this->sign();

        $this->assertNull($this->reason('v2,AAAA '.$v1));
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason('v2,'.substr($v1, 3)));
    }

    public function test_accepts_a_secret_without_the_whsec_prefix(): void
    {
        $this->assertNull($this->reason($this->sign(), secret: base64_encode(self::KEY)));
    }

    public function test_the_hmac_key_is_the_decoded_secret_not_the_raw_text(): void
    {
        // Signature calculée (à tort) avec le texte brut du secret comme clé : doit être refusée.
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason($this->sign(key: $this->secret())));
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason($this->sign(key: base64_encode(self::KEY))));
    }

    // ---------------------------------------------------------------- signatures invalides

    public function test_rejects_a_wrong_secret_a_tampered_body_and_altered_headers(): void
    {
        $signature = $this->sign();

        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason($signature, secret: 'whsec_'.base64_encode('autre-cle-synthetique')));
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason($signature, body: self::BODY.' '));
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason($signature, id: 'msg_autre'));
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason($signature, timestamp: self::NOW + 1));
    }

    public function test_rejects_the_legacy_equals_sign_format(): void
    {
        // Ancien (mauvais) format : « v1=<base64> ».
        $this->assertSame(Verifier::MALFORMED_SIGNATURE, $this->reason('v1='.substr($this->sign(), 3)));
    }

    public function test_a_truncated_signature_is_refused(): void
    {
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason(substr($this->sign(), 0, -4)));
        $this->assertSame(Verifier::NO_MATCHING_SIGNATURE, $this->reason('v1,'));
    }

    // ---------------------------------------------------------------- horodatage

    public function test_timestamp_tolerance_is_symmetric_and_inclusive(): void
    {
        foreach ([-300, -299, 0, 299, 300] as $offset) {
            $this->assertNull($this->reason($this->sign(timestamp: self::NOW + $offset), timestamp: self::NOW + $offset), "décalage {$offset} s");
        }

        foreach ([-301, 301, -86_400, 86_400] as $offset) {
            $this->assertSame(
                Verifier::TIMESTAMP_OUT_OF_TOLERANCE,
                $this->reason($this->sign(timestamp: self::NOW + $offset), timestamp: self::NOW + $offset),
                "décalage {$offset} s",
            );
        }
    }

    public function test_tolerance_is_configurable(): void
    {
        $past = self::NOW - 61;

        $this->assertNull($this->reason($this->sign(timestamp: $past), timestamp: $past, tolerance: 120));
        $this->assertSame(Verifier::TIMESTAMP_OUT_OF_TOLERANCE, $this->reason($this->sign(timestamp: $past), timestamp: $past, tolerance: 60));
        // Tolérance 0 (ou négative, ramenée à 0) : seule la seconde exacte passe.
        $this->assertNull($this->reason($this->sign(), tolerance: 0));
        $this->assertSame(Verifier::TIMESTAMP_OUT_OF_TOLERANCE, $this->reason($this->sign(timestamp: $past), timestamp: $past, tolerance: -50));
    }

    #[DataProvider('badTimestamps')]
    public function test_rejects_non_numeric_timestamps(string $timestamp): void
    {
        $this->assertSame(Verifier::BAD_TIMESTAMP, $this->reason($this->sign(timestamp: $timestamp), timestamp: $timestamp));
    }

    public static function badTimestamps(): array
    {
        return [
            'lettres' => ['abc'],
            'décimal' => ['1800000000.5'],
            'négatif' => ['-1800000000'],
            'espace devant' => [' 1800000000'],
            'espace derrière' => ['1800000000 '],
            'exposant' => ['1.8e9'],
            'hexadécimal' => ['0x6B49D200'],
            'trop long' => ['18000000000000'],
        ];
    }

    // ---------------------------------------------------------------- en-têtes absents ou mal formés

    public function test_rejects_missing_headers_one_at_a_time(): void
    {
        $this->assertSame(Verifier::MISSING_HEADERS, $this->reason($this->sign(), id: null));
        $this->assertSame(Verifier::MISSING_HEADERS, $this->reason($this->sign(), id: ''));
        $this->assertSame(Verifier::MISSING_HEADERS, $this->reason($this->sign(), timestamp: null));
        $this->assertSame(Verifier::MISSING_HEADERS, $this->reason($this->sign(), timestamp: ''));
        $this->assertSame(Verifier::MISSING_HEADERS, $this->reason(null));
        $this->assertSame(Verifier::MISSING_HEADERS, $this->reason(''));
        $this->assertSame(Verifier::MISSING_HEADERS, $this->reason("  \t\n "));
    }

    #[DataProvider('badMessageIds')]
    public function test_rejects_message_ids_unfit_for_use_as_a_cache_key(string $id): void
    {
        $this->assertSame(Verifier::BAD_MESSAGE_ID, $this->reason($this->sign(id: $id), id: $id));
    }

    public static function badMessageIds(): array
    {
        return [
            'espace' => ['msg 1'],
            'slash' => ['msg/1'],
            'retour ligne' => ["msg\n1"],
            'deux-points' => ['msg:1'],
            'trop long' => [str_repeat('a', 129)],
            'accent' => ['msg_é'],
        ];
    }

    #[DataProvider('malformedSignatureHeaders')]
    public function test_malformed_signature_headers_are_refused_without_error(string $header, string $expected): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->assertSame($expected, $this->reason($header));
        } finally {
            restore_error_handler();
        }
    }

    public static function malformedSignatureHeaders(): array
    {
        return [
            'version seule' => ['v1', Verifier::MALFORMED_SIGNATURE],
            'virgule seule' => [',', Verifier::MALFORMED_SIGNATURE],
            'signature sans version' => [',abcd', Verifier::MALFORMED_SIGNATURE],
            'texte libre' => ['garbage', Verifier::MALFORMED_SIGNATURE],
            'deux mots' => ['abc def', Verifier::MALFORMED_SIGNATURE],
            'signe égal' => ['v1=abcd', Verifier::MALFORMED_SIGNATURE],
            'bruit de 5 Ko' => [str_repeat('x', 5000), Verifier::MALFORMED_SIGNATURE],
            'bruit de 5 Ko avec virgules' => [str_repeat('v1,x ', 1200), Verifier::MALFORMED_SIGNATURE],
            'signature vide' => ['v1,', Verifier::NO_MATCHING_SIGNATURE],
            'version inconnue' => ['v9,abcd', Verifier::NO_MATCHING_SIGNATURE],
            'base64 invalide' => ['v1,%%%%', Verifier::NO_MATCHING_SIGNATURE],
        ];
    }

    public function test_never_raises_a_warning_or_an_exception_on_random_input(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $verifier = new Verifier($this->secret());

            for ($i = 0; $i < 300; $i++) {
                $junk = random_int(0, 9) === 0 ? '' : random_bytes(random_int(1, 300)); // random_bytes(0) lève une ValueError
                $this->assertFalse($verifier->verify(bin2hex(random_bytes(4)), (string) random_int(0, PHP_INT_MAX), $junk, $junk, self::NOW));
                $this->assertFalse($verifier->verify($junk, $junk, $junk, $junk, self::NOW));
            }
        } finally {
            restore_error_handler();
        }
    }

    // ---------------------------------------------------------------- secret mal formé

    #[DataProvider('invalidSecrets')]
    public function test_an_invalid_secret_refuses_everything(string $secret): void
    {
        $this->assertSame(Verifier::INVALID_SECRET, $this->reason($this->sign(), secret: $secret));
        $this->assertFalse((new Verifier($secret))->verify(self::ID, (string) self::NOW, $this->sign(), self::BODY, self::NOW));
    }

    public static function invalidSecrets(): array
    {
        return [
            'vide' => [''],
            'préfixe seul' => ['whsec_'],
            'base64 invalide' => ['whsec_%%%pas-du-base64%%%'],
            'ponctuation' => ['!!!'],
        ];
    }

    // ---------------------------------------------------------------- parité avec le SDK officiel

    /** @return array<string, array{0: string, 1: int}> */
    public static function sdkScenarios(): array
    {
        return [
            'valide' => ['valid', 0],
            'valide parmi plusieurs' => ['several', 0],
            'passé hors tolérance' => ['valid', -400],
            'futur hors tolérance' => ['valid', 400],
            'limite basse' => ['valid', -299],
            'limite haute' => ['valid', 299],
            'signature fausse' => ['wrong', 0],
            'corps modifié' => ['tampered', 0],
        ];
    }

    #[DataProvider('sdkScenarios')]
    public function test_gives_the_same_verdict_as_the_official_resend_sdk(string $scenario, int $offset): void
    {
        $timestamp = time() + $offset;
        $body = self::BODY;
        $signature = $this->sign(timestamp: $timestamp);

        $header = match ($scenario) {
            'several' => 'v1,AAAA '.$signature,
            'wrong' => $this->sign(timestamp: $timestamp, key: 'autre-cle-synthetique'),
            default => $signature,
        };

        $received = $scenario === 'tampered' ? $body.'x' : $body; // la signature, elle, couvre le corps d'origine

        $ours = (new Verifier($this->secret()))->verify(self::ID, (string) $timestamp, $header, $received);

        try {
            $sdk = WebhookSignature::verify(
                $received,
                ['svix-id' => self::ID, 'svix-timestamp' => (string) $timestamp, 'svix-signature' => $header],
                $this->secret(),
            );
        } catch (\Throwable) {
            $sdk = false;
        }

        $this->assertSame($sdk, $ours, "scénario « {$scenario} » ({$offset} s)");
    }
}
