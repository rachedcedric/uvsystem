<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Inscription publique en attente de vérification d'e-mail, puis trace du tenant créé (registre des essais).
 * Volontairement NON tenantisée : elle précède l'existence du tenant. Jamais exposée à un tenant.
 * Le lien de vérification n'est stocké que haché ; la charge utile est chiffrée et vidée une fois consommée.
 */
class SubscriptionSignup extends Model
{
    protected $fillable = ['token_hash', 'email', 'email_normalized', 'phone_normalized', 'ip', 'plan_slug', 'cycle', 'payload', 'expires_at', 'verified_at', 'tenant_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    /** @param  array<string, mixed>  $data */
    public function setPayloadData(array $data): void
    {
        $this->payload = Crypt::encryptString(json_encode($data));
    }

    /** @return array<string, mixed> */
    public function payloadData(): array
    {
        return $this->payload === '' ? [] : (json_decode(Crypt::decryptString($this->payload), true) ?: []);
    }

    public function isUsable(): bool
    {
        return $this->verified_at === null && $this->expires_at->isFuture();
    }
}
