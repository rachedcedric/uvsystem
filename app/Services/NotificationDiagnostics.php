<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\DebtReminderNotification;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôle de la configuration des notifications : cloche (base de données),
 * planificateur, file d'attente, e-mail, SMS et push Firebase. Chaque contrôle
 * dit ce qui ne va pas et comment le corriger. Aucun secret n'est exposé.
 */
class NotificationDiagnostics
{
    public const HEARTBEAT_KEY = 'scheduler:heartbeat';

    /**
     * @return list<array{key:string, label:string, status:'ok'|'warn'|'error'|'info', detail:string, hint:?string}>
     */
    public function checks(?User $user = null): array
    {
        return [
            $this->recipients(),
            $this->scheduler(),
            $this->queue(),
            $this->reminders(),
            $this->mail(),
            $this->sms(),
            $this->push($user),
            $this->environment(),
        ];
    }

    /** @return array{ok:int, warn:int, error:int} */
    public function summary(array $checks): array
    {
        $counts = collect($checks)->countBy('status');

        return ['ok' => $counts['ok'] ?? 0, 'warn' => $counts['warn'] ?? 0, 'error' => $counts['error'] ?? 0];
    }

    private function check(string $key, string $label, string $status, string $detail, ?string $hint = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'hint');
    }

    private function recipients(): array
    {
        $count = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['master', 'admin']))->count();

        return $count > 0
            ? $this->check('recipients', 'Destinataires des alertes', 'ok', "{$count} compte(s) master ou admin reçoivent les alertes et rappels de dette.")
            : $this->check('recipients', 'Destinataires des alertes', 'error', 'Aucun compte master ou admin : personne ne reçoit les alertes.', 'Attribuez le rôle master ou admin à au moins un utilisateur.');
    }

    private function scheduler(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);

        if (! $last) {
            return $this->check('scheduler', 'Planificateur (cron)', 'error',
                'Aucune exécution détectée : les rappels automatiques, les commissions de 18h02 et les sauvegardes ne partent pas toutes seules.',
                'Ajoutez une tâche cron chez l\'hébergeur, chaque minute : php /chemin/du/site/artisan schedule:run >> /dev/null 2>&1');
        }

        $minutes = (int) now()->diffInMinutes(Carbon::createFromTimestamp((int) $last), true);

        return $minutes <= 3
            ? $this->check('scheduler', 'Planificateur (cron)', 'ok', 'Actif — dernière exécution il y a '.($minutes === 0 ? 'moins d\'une minute' : $minutes.' min').'.')
            : $this->check('scheduler', 'Planificateur (cron)', 'error', "Arrêté depuis {$minutes} minutes.", 'Vérifiez que la tâche cron schedule:run est toujours active chez l\'hébergeur.');
    }

    private function queue(): array
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            return $this->check('queue', 'File d\'attente', 'ok', 'Mode immédiat (sync) : e-mails et notifications partent tout de suite.');
        }

        if ($connection !== 'database' || ! Schema::hasTable(config('queue.connections.database.table', 'jobs'))) {
            return $this->check('queue', 'File d\'attente', 'info', "Connexion « {$connection} » : état non vérifiable ici.");
        }

        $pending = DB::table(config('queue.connections.database.table', 'jobs'))->count();
        $oldest = DB::table(config('queue.connections.database.table', 'jobs'))->min('created_at');
        $age = $oldest ? (int) now()->diffInMinutes(Carbon::createFromTimestamp((int) $oldest), true) : 0;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        if ($pending > 0 && $age >= 3) {
            return $this->check('queue', 'File d\'attente', 'error',
                "Des tâches attendent depuis plus de {$age} min : aucun « worker » ne les traite. Les e-mails et alertes push de dette restent bloqués. Contactez l'administrateur de la plateforme.",
                'Le planificateur lance désormais le worker chaque minute (cron schedule:run requis). Sinon, mettez QUEUE_CONNECTION=sync dans le .env du serveur.');
        }

        if ($failed > 0) {
            return $this->check('queue', 'File d\'attente', 'warn', 'Des tâches ont échoué (e-mail ou push refusé). Contactez l\'administrateur de la plateforme.', 'Les détails techniques sont réservés à l\'administrateur de la plateforme.');
        }

        return $this->check('queue', 'File d\'attente', 'ok', $pending > 0 ? 'Des tâches sont en cours de traitement.' : 'Vide — rien en attente, aucun échec.');
    }

    private function reminders(): array
    {
        $debtors = Agent::active()->withDebt()->count();
        $last = DatabaseNotification::query()->where('type', DebtReminderNotification::class)->latest()->value('created_at');

        if ($debtors === 0) {
            return $this->check('reminders', 'Rappels de dette', 'ok', 'Aucun agent endetté : aucun rappel à envoyer.');
        }

        if (! $last) {
            return $this->check('reminders', 'Rappels de dette', 'warn', "{$debtors} agent(s) endetté(s) mais aucun rappel n'a encore été envoyé.", 'Lancez « Envoyer les rappels maintenant » depuis Dettes, ou vérifiez le planificateur.');
        }

        $hours = (int) now()->diffInHours(Carbon::parse($last), true);

        return $hours <= 3
            ? $this->check('reminders', 'Rappels de dette', 'ok', 'Dernier rappel il y a '.($hours === 0 ? 'moins d\'une heure' : "{$hours} h").' (cycle de 2 h).')
            : $this->check('reminders', 'Rappels de dette', 'warn', "Dernier rappel il y a {$hours} h alors que {$debtors} agent(s) sont endettés.", 'Les rappels devraient partir toutes les 2 heures : vérifiez le planificateur.');
    }

    private function mail(): array
    {
        $mailer = config('mail.default');
        $from = config('mail.from.address');

        if ($mailer === 'resend' && ! config('services.resend.key')) {
            return $this->check('mail', 'E-mail', 'error', 'Le service Resend est choisi mais RESEND_KEY est vide : aucun e-mail ne part.', 'Renseignez RESEND_KEY dans le .env du serveur.');
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            return $this->check('mail', 'E-mail', 'warn', "Mode « {$mailer} » : les e-mails sont écrits dans les journaux, pas envoyés.", 'Choisissez MAIL_MAILER=resend (ou smtp) en production.');
        }

        return $this->check('mail', 'E-mail', 'ok', "Service « {$mailer} » configuré.", 'Utilisez le bouton « Envoyer un e-mail de test » pour confirmer la livraison (le domaine doit être validé chez le fournisseur).');
    }

    private function sms(): array
    {
        if (! config('services.sms.enabled')) {
            return $this->check('sms', 'SMS', 'info', 'Désactivés (SMS_ENABLED=false).');
        }

        $missing = collect(['TWILIO_ACCOUNT_SID' => config('services.twilio.account_sid'), 'TWILIO_AUTH_TOKEN' => config('services.twilio.auth_token'), 'TWILIO_FROM' => config('services.twilio.from')])
            ->filter(fn ($v) => blank($v))->keys();

        return $missing->isEmpty()
            ? $this->check('sms', 'SMS', 'ok', 'Twilio configuré.')
            : $this->check('sms', 'SMS', 'error', 'SMS activés mais '.$missing->implode(', ').' manquant(s) : aucun SMS ne part.', 'Renseignez ces variables dans le .env du serveur (numéro Twilio avec indicatif, ex. +1…).');
    }

    private function push(?User $user): array
    {
        $credentials = config('services.firebase.credentials');
        $project = config('services.firebase.project_id');
        $total = PushToken::count();
        $mine = $user ? PushToken::where('user_id', $user->id)->count() : 0;

        if (blank($credentials) && blank($project)) {
            return $this->check('push', 'Notifications push (Firebase)', 'error',
                'Firebase n\'est pas configuré sur le serveur : aucune notification ne peut atteindre le téléphone quand l\'appli est fermée.',
                'Suivez docs/PUSH_NOTIFICATIONS.md : créez le projet Firebase, ajoutez FIREBASE_PROJECT_ID et FIREBASE_CREDENTIALS dans le .env, puis google-services.json dans l\'APK.');
        }

        if (! is_string($credentials) || ! is_file($credentials) || ! is_readable($credentials)) {
            return $this->check('push', 'Notifications push (Firebase)', 'error', 'Le fichier de compte de service est introuvable ou illisible.', 'Vérifiez FIREBASE_CREDENTIALS (chemin absolu, hors du dossier public).');
        }

        $json = json_decode((string) file_get_contents($credentials), true);
        if (($json['type'] ?? null) !== 'service_account' || empty($json['private_key']) || empty($json['client_email'])) {
            return $this->check('push', 'Notifications push (Firebase)', 'error', 'Le fichier de compte de service est invalide.', 'Téléchargez à nouveau la clé JSON du compte de service depuis la console Google Cloud.');
        }

        if ($total === 0) {
            return $this->check('push', 'Notifications push (Firebase)', 'warn', 'Serveur prêt, mais aucun téléphone n\'est enregistré.', 'Installez l\'APK construit avec google-services.json, connectez-vous en master/admin et autorisez les notifications.');
        }

        return $this->check('push', 'Notifications push (Firebase)', 'ok', "Serveur prêt — {$total} appareil(s) enregistré(s), dont {$mine} pour vous.");
    }

    private function environment(): array
    {
        $issues = [];
        if (config('app.timezone') !== 'Africa/Abidjan') {
            $issues[] = 'fuseau « '.config('app.timezone').' » (les horaires planifiés — 18h02, rappels — supposent Africa/Abidjan)';
        }
        if (config('app.debug')) {
            $issues[] = 'APP_DEBUG est actif (à désactiver en production)';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $issues[] = 'APP_URL n\'est pas en https (liens des e-mails)';
        }

        return $issues === []
            ? $this->check('env', 'Environnement', 'ok', 'Fuseau, URL et mode production corrects.')
            : $this->check('env', 'Environnement', 'warn', ucfirst(implode(' ; ', $issues)).'.', 'Corrigez ces valeurs dans le .env du serveur puis videz le cache : php artisan config:clear');
    }
}
