<?php

namespace App\Security;

use App\Models\Activity;
use App\Models\AgentSolde;
use App\Models\MasterSolde;
use App\Models\PushToken;
use App\Models\SousDistributeurSolde;
use App\Models\SubscriptionNotificationLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Rôle « lecture seule » : refus d'écriture au niveau du modèle, donc valable pour le panneau web, les écrans
 * Livewire, l'application mobile et l'API, y compris si un bouton est masqué par erreur ou si l'URL est forgée.
 * Appelé par BelongsToTenant à chaque insertion, modification et suppression (avant même le contrôle d'abonnement).
 *
 * Comme SubscriptionAccessPolicy, il ne bride que les requêtes d'un utilisateur authentifié : le code de
 * confiance (commandes, jobs, runAs/withoutTenancy) n'est jamais concerné.
 */
class ReadOnlyGuard
{
    /**
     * Écritures que le système fait pour tout compte connecté, sans action métier : journal d'activité, appareil
     * de notification, soldes dérivés recalculés, journal d'e-mails d'abonnement. Le compte lui-même n'est
     * modifiable que par lui-même (mot de passe, 2FA, dernière connexion).
     *
     * @var list<class-string<Model>>
     */
    private const SYSTEM_WRITES = [
        Activity::class,
        PushToken::class,
        AgentSolde::class,
        MasterSolde::class,
        SousDistributeurSolde::class,
        SubscriptionNotificationLog::class,
    ];

    public static function assertWritable(Model $model): void
    {
        if (TenantContext::hasExplicitContext()) {
            return;
        }

        $user = Auth::guard()->user();

        if (! $user instanceof User || ! $user->isReadOnly()) {
            return;
        }

        foreach (self::SYSTEM_WRITES as $allowed) {
            if ($model instanceof $allowed) {
                return;
            }
        }

        if ($model instanceof User && $model->getKey() === $user->getKey()) {
            return;
        }

        throw new AuthorizationException(self::message());
    }

    public static function message(): string
    {
        return 'Votre compte est en lecture seule : aucune modification n\'est possible.';
    }
}
