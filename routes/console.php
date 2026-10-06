<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes - Scheduler Configuration
|--------------------------------------------------------------------------
|
| Tâches planifiées pour le système de gestion UV
|
*/

// ============================================
// SURVEILLANCE - Planificateur et file d'attente
// ============================================

// Battement de cœur : prouve que le cron « schedule:run » tourne (voir l'écran
// « Diagnostic des notifications »).
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put(\App\Services\NotificationDiagnostics::HEARTBEAT_KEY, now()->timestamp, now()->addHour()))
    ->name('scheduler-heartbeat')
    ->everyMinute();

// Worker de la file d'attente : traite e-mails et alertes push en attente.
// Sur un hébergement mutualisé sans worker permanent, un seul cron
// « schedule:run » suffit ainsi à tout faire partir.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->name('queue-worker')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// ============================================
// BACKUPS - Sauvegardes automatiques
// ============================================

// Backup complet (fichiers + base de données) - Tous les jours à 2h du matin
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup.log'));

// Backup base de données uniquement - Toutes les 6 heures
Schedule::command('backup:run --only-db')
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-db.log'));

// Nettoyage des anciennes sauvegardes - Tous les jours à 3h
Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-cleanup.log'));

// Vérification de la santé des backups - Tous les jours à 4h
Schedule::command('backup:monitor')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-monitor.log'));

// ============================================
// SÉCURITÉ - Vérifications et rapports
// ============================================

// Vérification d'intégrité des transactions - Tous les jours à 5h
Schedule::command('security:verify-integrity')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/security-integrity.log'));

// Rapport de sécurité quotidien - Tous les jours à 7h (envoyé par email)
Schedule::command('security:report --output=json')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputTo(config('security.audit.notification_emails', ['admin@votreentreprise.ci']));

// ============================================
// ALERTES ET RAPPELS
// ============================================

// Calcul automatique des commissions - Tous les jours à 18h02 (heure de clôture métier)
Schedule::command('commissions:calculer')
    ->dailyAt('18:02')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/commissions.log'));

// Rattrapage automatique - si le calcul de 18h02 a été manqué (serveur
// arrêté, erreur, scheduler indisponible...), la prochaine exécution ici
// (au plus 15 minutes après) détecte et corrige la journée manquante.
Schedule::command('commissions:rattraper')
    ->everyFifteenMinutes()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/commissions-rattrapage.log'));

// Abonnements : expiration des essais/abonnements échus et avertissements J-7/J-3/J-1.
// Idempotent (peut être relancé sans doublon) ; s'exécute dans le même planificateur que le reste.
Schedule::command('subscriptions:process')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/subscriptions.log'));

// Sauvegarde dédiée des tenants dont l'offre l'inclut : chaque dimanche à 03h30 (rotation : 7 dernières).
Schedule::command('tenancy:backup-dedicated')
    ->weeklyOn(0, '03:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/tenant-backups.log'));

// Processus de recouvrement automatisé - 2x par jour (9h et 15h)
Schedule::command('recovery:process')
    ->twiceDaily(9, 15)
    ->weekdays()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/recovery-process.log'));

// Vérification des alertes de dette - Toutes les heures
Schedule::command('uv:check-debt-alerts')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/debt-alerts.log'));

// Rappel "Notifications Récentes" pour toute dette non soldée - Toutes les 2h
// (08h, 10h, 12h, 14h, 16h...), un seul rappel par agent et par cycle.
Schedule::command('uv:send-debt-reminders')
    ->everyTwoHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/debt-reminders.log'));

// Envoi des rappels EMAIL - Tous les jours à 9h
Schedule::command('uv:send-reminders --min-ratio=70')
    ->dailyAt('09:00')
    ->weekdays()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/payment-reminders.log'));

// Envoi des rappels SMS - Tous les jours à 10h (agents critiques uniquement)
Schedule::command('sms:reminders --min-ratio=85')
    ->dailyAt('10:00')
    ->weekdays()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/sms-reminders.log'));

// Rappels SMS hebdomadaires - Chaque lundi à 11h (tous les agents avec dette)
Schedule::command('sms:reminders --min-ratio=50')
    ->weeklyOn(1, '11:00') // Lundi à 11h
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/sms-reminders-weekly.log'));

// ============================================
// RAPPORTS PLANIFIÉS
// ============================================

// Rapport quotidien - Tous les jours à 6h du matin
Schedule::command('reports:generate daily --format=both')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/reports-daily.log'));

// Rapport hebdomadaire - Chaque lundi à 7h
Schedule::command('reports:generate weekly --format=both')
    ->weeklyOn(1, '07:00') // Lundi à 7h
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/reports-weekly.log'));

// Rapport mensuel - Le 1er de chaque mois à 8h
Schedule::command('reports:generate monthly --format=both')
    ->monthlyOn(1, '08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/reports-monthly.log'));

// Ancien rapport journalier (compatibilité)
Schedule::command('uv:daily-report --save')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/daily-reports.log'));

// ============================================
// MAINTENANCE
// ============================================

// Nettoyage des fichiers d'export (>1 jour) - Tous les jours à 3h
Schedule::command('exports:cleanup --days=1')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/exports-cleanup.log'));

// Nettoyage des anciennes activités (>90 jours) - Chaque dimanche à 3h30
Schedule::call(function () {
    \App\Models\Activity::where('created_at', '<', now()->subDays(90))->delete();
})->weekly()->sundays()->at('03:30');

// Nettoyage des tokens API expirés - Tous les jours à 4h30
Schedule::call(function () {
    \Laravel\Sanctum\PersonalAccessToken::where('expires_at', '<', now())->delete();
})->dailyAt('04:30');

// Purge des tokens API expirés (config/sanctum.php : expiration 7 jours par défaut).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
