<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionSignup;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Subscriptions\VerifySignupNotification;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Souscription publique en DEUX temps (double confirmation) :
 *  1. request()  : contrôles anti-abus puis envoi d'un lien signé, à usage unique, valable quelques heures. RIEN n'est
 *                  créé : ni entreprise, ni compte, ni essai. Seul le hash du lien est stocké ; la charge utile est
 *                  chiffrée et contient un mot de passe DÉJÀ HACHÉ.
 *  2. complete() : au clic de confirmation, l'entreprise, son master et l'essai sont créés en une transaction, après
 *                  nouveau contrôle. L'adresse e-mail est donc PROUVÉE avant tout essai.
 * Tout ce qui compte (offre, prix, limites, durée d'essai, rôle) est recalculé ici d'après la base : le navigateur
 * n'envoie que des champs de saisie ; le rôle est toujours « master ».
 *
 * Anti-abus d'essais répétés : adresse normalisée (minuscules, sans +alias, sans points gmail) unique parmi les
 * inscriptions vérifiées, comptes et tenants ; téléphone unique ; domaines jetables refusés ; plafond d'inscriptions
 * par IP et par jour ; envois de lien limités par adresse. Limite inhérente : plusieurs vraies boîtes mail distinctes
 * restent possibles.
 */
class TenantSignupService
{
    public function __construct(private readonly TenantProvisioner $provisioner, private readonly SubscriptionManager $manager) {}

    public static function normalizeEmail(string $email): string
    {
        $email = Str::lower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $local = explode('+', $local, 2)[0];

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }

    /**
     * Étape 1 : enregistre la demande et envoie le lien. Retourne le jeton en clair UNIQUEMENT pour l'appelant de confiance
     * (tests) : il n'est jamais stocké ni journalisé.
     *
     * @param  array{company_name: string, name: string, email: string, phone?: ?string, password: string}  $data  déjà validées
     */
    public function request(SubscriptionPlan $plan, SubscriptionCycle $cycle, array $data, ?string $ip): string
    {
        $email = Str::lower(trim($data['email']));
        $normalized = self::normalizeEmail($email);
        $phone = self::normalizePhone($data['phone'] ?? null);
        $config = config('subscriptions.signup');

        $this->assertAllowed($data['company_name'], $email, $normalized, $phone, $ip, (array) $config['disposable_domains']);

        $key = 'signup-mail:'.$normalized;
        if (RateLimiter::tooManyAttempts($key, (int) $config['max_mails_per_email_per_hour'])) {
            throw ValidationException::withMessages(['email' => 'Trop de demandes pour cette adresse. Réessayez dans une heure.']);
        }
        RateLimiter::hit($key, 3600);

        $token = Str::random(64);
        $ttl = (int) $config['link_ttl_hours'];

        $signup = DB::transaction(function () use ($plan, $cycle, $data, $email, $normalized, $phone, $ip, $token, $ttl) {
            SubscriptionSignup::query()->where('email_normalized', $normalized)->whereNull('verified_at')->delete(); // un seul lien valide à la fois

            $signup = new SubscriptionSignup([
                'token_hash' => hash('sha256', $token), 'email' => $email, 'email_normalized' => $normalized, 'phone_normalized' => $phone,
                'ip' => $ip, 'plan_slug' => $plan->slug, 'cycle' => $cycle->value, 'expires_at' => now()->addHours($ttl),
            ]);
            $signup->setPayloadData([
                'company_name' => trim($data['company_name']), 'name' => $data['name'], 'phone' => $data['phone'] ?? null,
                'password_hash' => Hash::make($data['password']),
            ]);
            $signup->save();

            return $signup;
        });

        $url = URL::temporarySignedRoute('souscrire.verifier', now()->addHours($ttl), ['token' => $token]);

        try {
            Notification::route('mail', $email)->notify(new VerifySignupNotification($url, trim($data['company_name']), $ttl));
        } catch (Throwable $e) {
            $signup->delete();
            report($e);
            throw ValidationException::withMessages(['email' => 'Impossible d\'envoyer l\'e-mail de confirmation pour le moment. Réessayez plus tard.']);
        }

        return $token;
    }

    /** Étape 2 : confirmation de l'adresse, puis création de l'entreprise, du master et de l'essai. */
    public function complete(string $token): Tenant
    {
        try {
            return DB::transaction(function () use ($token) {
                $signup = SubscriptionSignup::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

                if ($signup === null || ! $signup->isUsable()) {
                    throw ValidationException::withMessages(['token' => 'Ce lien de confirmation est invalide, expiré ou déjà utilisé. Recommencez la souscription.']);
                }

                $data = $signup->payloadData();
                $plan = SubscriptionPlan::active()->where('slug', $signup->plan_slug)->first()
                    ?? throw ValidationException::withMessages(['plan' => 'Cette offre n\'est plus disponible.']);

                // Les conditions ont pu changer pendant l'attente : on revérifie au moment de créer.
                $this->assertAllowed($data['company_name'], $signup->email, $signup->email_normalized, $signup->phone_normalized, null, [], ignoreSignupId: $signup->id);

                $tenant = $this->provisioner->provision([
                    'name' => $data['company_name'],
                    'contact_name' => $data['name'],
                    'contact_email' => $signup->email,
                    'contact_phone' => $data['phone'] ?? null,
                    'settings' => ['terms_accepted_at' => $signup->created_at->toIso8601String(), 'terms_version' => config('subscriptions.terms_version'), 'signup' => 'public', 'email_verified_at' => now()->toIso8601String()],
                ], ['name' => $data['name'], 'email' => $signup->email, 'password_hash' => $data['password_hash']],
                    fn (Tenant $new) => $this->manager->startTrial($new, $plan, SubscriptionCycle::from($signup->cycle)));

                $signup->setPayloadData([]);
                $signup->forceFill(['verified_at' => now(), 'tenant_id' => $tenant->id])->save();

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['token' => 'Un compte existe déjà avec cette adresse e-mail ou ce nom d\'entreprise.']);
        } catch (InvalidSubscriptionTransition $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }
    }

    /** @param  list<string>  $disposable */
    private function assertAllowed(string $company, string $email, string $normalized, ?string $phone, ?string $ip, array $disposable, ?int $ignoreSignupId = null): void
    {
        $errors = [];
        $domain = Str::after($email, '@');

        if (in_array($domain, $disposable, true)) {
            $errors['email'] = 'Les adresses e-mail jetables ne sont pas acceptées.';
        }

        $verified = fn () => SubscriptionSignup::query()->whereNotNull('verified_at')->when($ignoreSignupId, fn ($q) => $q->where('id', '!=', $ignoreSignupId));

        if (User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->exists()
            || Tenant::query()->whereRaw('LOWER(contact_email) = ?', [$email])->exists()
            || $verified()->where('email_normalized', $normalized)->exists()
            || ($phone !== null && $verified()->where('phone_normalized', $phone)->exists())) {
            // Même message pour un compte, un ancien essai ou un téléphone déjà utilisés : on ne révèle pas lequel.
            $errors['email'] = 'Un compte ou un essai existe déjà avec ces informations.';
        }

        if (Tenant::query()->where('slug', Str::slug($company))->exists()) {
            $errors['company_name'] = 'Une entreprise portant ce nom existe déjà.';
        }

        if ($ip !== null && $verified()->where('ip', $ip)->where('verified_at', '>=', now()->subDay())->count() >= (int) config('subscriptions.signup.max_per_ip_per_day')) {
            $errors['email'] = 'Trop d\'inscriptions depuis votre connexion aujourd\'hui. Réessayez demain.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
