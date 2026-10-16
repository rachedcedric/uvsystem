<?php

namespace App\Services\Webhooks;

/**
 * Vérifie la signature d'un webhook Svix (protocole utilisé par Resend).
 *
 * Format, identique au SDK resend/resend-php :
 *  - secret : « whsec_ » + clé en base64 ; la clé HMAC est la valeur DÉCODÉE (jamais le texte brut) ;
 *  - en-têtes : svix-id, svix-timestamp (secondes Unix), svix-signature ;
 *  - svix-signature : « v1,<base64> » ; plusieurs signatures possibles, séparées par une espace (rotation du secret) ;
 *  - signature : base64( HMAC-SHA256( clé, "{svix-id}.{svix-timestamp}.{corps brut}" ) ), HMAC binaire.
 *
 * Aucune méthode ne lève d'exception ni n'émet d'avertissement PHP, quel que soit le contenu des en-têtes :
 * toute anomalie est un refus dont le motif est retourné (code stable, sans donnée sensible). Le secret, les
 * signatures reçues et le corps ne sont jamais exposés dans un motif, une exception ou une trace.
 */
class SvixSignatureVerifier
{
    public const DEFAULT_TOLERANCE = 300;

    public const INVALID_SECRET = 'invalid_secret';

    public const MISSING_HEADERS = 'missing_headers';

    public const BAD_MESSAGE_ID = 'bad_message_id';

    public const BAD_TIMESTAMP = 'bad_timestamp';

    public const TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';

    public const MALFORMED_SIGNATURE = 'malformed_signature';

    public const NO_MATCHING_SIGNATURE = 'no_matching_signature';

    private const SECRET_PREFIX = 'whsec_';

    /** Une liste de signatures légitime tient en quelques centaines d'octets : au-delà, c'est du bruit. */
    private const MAX_SIGNATURE_HEADER_LENGTH = 2048;

    /**
     * @param  int  $tolerance  Écart maximal toléré, en secondes, entre svix-timestamp et l'heure du serveur (passé ou futur).
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $tolerance = self::DEFAULT_TOLERANCE,
    ) {}

    public function verify(?string $messageId, ?string $timestamp, ?string $signatureHeader, string $payload, ?int $now = null): bool
    {
        return $this->failureReason($messageId, $timestamp, $signatureHeader, $payload, $now) === null;
    }

    /**
     * @return string|null null si la requête est authentique, sinon le motif du refus (constantes de la classe)
     */
    public function failureReason(?string $messageId, ?string $timestamp, ?string $signatureHeader, string $payload, ?int $now = null): ?string
    {
        $key = $this->key();

        if ($key === null) {
            return self::INVALID_SECRET;
        }

        if ($messageId === null || $messageId === '' || $timestamp === null || $timestamp === '' || $signatureHeader === null || trim($signatureHeader) === '') {
            return self::MISSING_HEADERS;
        }

        // Le svix-id sert de clé de déduplication : on n'accepte qu'un identifiant court et sans caractère exotique.
        if (preg_match('/\A[A-Za-z0-9_\-]{1,128}\z/', $messageId) !== 1) {
            return self::BAD_MESSAGE_ID;
        }

        if (preg_match('/\A\d{1,12}\z/', $timestamp) !== 1) {
            return self::BAD_TIMESTAMP;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > max(0, $this->tolerance)) {
            return self::TIMESTAMP_OUT_OF_TOLERANCE;
        }

        if (strlen($signatureHeader) > self::MAX_SIGNATURE_HEADER_LENGTH) {
            return self::MALFORMED_SIGNATURE;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$messageId}.{$timestamp}.{$payload}", $key, true));
        $wellFormed = false;

        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $entry) {
            $comma = strpos($entry, ',');

            if ($comma === false || $comma === 0) {
                continue; // pas de la forme « version,signature » : ignoré
            }

            $wellFormed = true;

            if (substr($entry, 0, $comma) === 'v1' && hash_equals($expected, substr($entry, $comma + 1))) {
                return null;
            }
        }

        return $wellFormed ? self::NO_MATCHING_SIGNATURE : self::MALFORMED_SIGNATURE;
    }

    /** Clé HMAC binaire, ou null si le secret configuré n'est pas du base64 valide. */
    private function key(): ?string
    {
        $encoded = str_starts_with($this->secret, self::SECRET_PREFIX)
            ? substr($this->secret, strlen(self::SECRET_PREFIX))
            : $this->secret;

        if ($encoded === '') {
            return null;
        }

        $key = base64_decode($encoded, true);

        return $key === false || $key === '' ? null : $key;
    }
}
