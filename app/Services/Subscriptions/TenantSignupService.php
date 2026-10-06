<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Souscription publique : crée l'entreprise, son premier master et son essai en UNE transaction.
 * Tout ce qui compte (offre, prix, limites, durée d'essai, rôle, statut) est recalculé ICI d'après la base :
 * rien de ce que envoie le navigateur n'est cru, et le rôle est toujours « master » (jamais super_admin).
 */
class TenantSignupService
{
    public function __construct(private readonly TenantProvisioner $provisioner, private readonly SubscriptionManager $manager) {}

    /**
     * @param  array{company_name: string, name: string, email: string, phone?: ?string, password: string}  $data  déjà validées
     */
    public function register(SubscriptionPlan $plan, SubscriptionCycle $cycle, array $data): Tenant
    {
        $email = Str::lower(trim($data['email']));
        $this->assertNotTaken($data['company_name'], $email);

        try {
            return DB::transaction(function () use ($plan, $cycle, $data, $email) {
                $tenant = $this->provisioner->provision([
                    'name' => trim($data['company_name']),
                    'contact_name' => $data['name'],
                    'contact_email' => $email,
                    'contact_phone' => $data['phone'] ?? null,
                    'settings' => ['terms_accepted_at' => now()->toIso8601String(), 'terms_version' => config('subscriptions.terms_version'), 'signup' => 'public'],
                ], ['name' => $data['name'], 'email' => $email, 'password' => $data['password']]);

                $this->manager->startTrial($tenant, $plan, $cycle);

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux soumissions simultanées : la base tranche, la seconde est refusée proprement.
            throw ValidationException::withMessages(['email' => 'Un compte existe déjà avec cette adresse e-mail ou ce nom d\'entreprise.']);
        } catch (InvalidSubscriptionTransition $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }
    }

    private function assertNotTaken(string $company, string $email): void
    {
        $errors = [];

        if (User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->exists()
            || Tenant::query()->whereRaw('LOWER(contact_email) = ?', [$email])->exists()) {
            // Même message pour un compte ou un ancien essai : on ne révèle pas lequel, et un essai n'est jamais rejoué.
            $errors['email'] = 'Un compte existe déjà avec cette adresse e-mail.';
        }

        if (Tenant::query()->where('slug', Str::slug($company))->exists()) {
            $errors['company_name'] = 'Une entreprise portant ce nom existe déjà.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
