<?php

/**
 * Preuve de comportement CONCURRENT des limites d'abonnement (risque « limites non atomiques »).
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=… DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=… \
 *   APP_ENV=testing php scripts/check-limit-concurrency.php [travailleurs=8] [limite=1]
 *
 * Lance N processus PHP qui tentent au même instant de créer un agent pour un tenant dont l'offre en autorise `limite`.
 *  - mode « réel »  : Agent::create() de l'application (transaction + verrou du tenant) -> exactement `limite` créations.
 *  - mode « naïf »  : même contrôle SANS verrou ni transaction, avec une pause entre contrôle et insertion -> dépasse la
 *                     limite. Ce témoin prouve que le montage SAIT détecter la course (sans lui, un succès ne prouverait rien).
 * Écrit dans la base visée : réservé à une base jetable (refuse de tourner si son nom ne contient pas « scratch »).
 * Code de sortie 0 = limite respectée en mode réel ET course bien observée en mode naïf.
 */

use App\Enums\SubscriptionCycle;
use App\Exceptions\SubscriptionLimitReached;
use App\Models\Agent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();
if (! str_contains((string) $database, 'scratch') || DB::getDriverName() === 'sqlite') {
    fwrite(STDERR, "REFUS : la base « {$database} » n'est pas une base jetable (nom sans « scratch ») ou le moteur ne supporte pas les verrous.\n");
    exit(2);
}

// ---------------------------------------------------------------- travailleur
if (($argv[1] ?? '') === '--worker') {
    [, , $tenantId, $startAt, $mode, $index] = $argv;
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }

    try {
        if ($mode === 'real') {
            TenantContext::runAs((int) $tenantId, fn () => Agent::create(['name' => "Agent {$index}", 'phone' => sprintf('07%08d', $index), 'debt_limit' => 0, 'status' => 'active']));
        } else { // naïf : contrôle puis insertion, sans transaction ni verrou, avec une pause qui élargit la fenêtre de course
            app(SubscriptionEntitlements::class)->assertCanCreateAgent((int) $tenantId);
            usleep(150_000);
            DB::table('agents')->insert(['tenant_id' => $tenantId, 'name' => "Naïf {$index}", 'phone' => sprintf('06%08d', $index), 'debt_limit' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        echo "OK\n";
    } catch (SubscriptionLimitReached) {
        echo "REFUSED\n";
    } catch (Throwable $e) {
        echo 'ERROR '.get_class($e).': '.$e->getMessage()."\n";
    }
    exit(0);
}

// ---------------------------------------------------------------- orchestrateur
$workers = (int) ($argv[1] ?? 8);
$limit = (int) ($argv[2] ?? 1);

$run = function (string $mode) use ($workers, $limit): array {
    $plan = SubscriptionPlan::create([
        'name' => "Course {$mode} ".uniqid(), 'slug' => "course-{$mode}-".uniqid(), 'monthly_price' => 1, 'yearly_price' => 1,
        'max_agents' => $limit, 'features' => [], 'is_active' => true,
    ]);
    $tenant = Tenant::create(['name' => "Course {$mode} ".uniqid()]);
    app(SubscriptionManager::class)->activate($tenant, $plan, SubscriptionCycle::Monthly);

    $startAt = microtime(true) + 3.0; // départ simultané : tous les processus attendent cet instant
    $procs = [];
    for ($i = 1; $i <= $workers; $i++) {
        $cmd = [PHP_BINARY, __FILE__, '--worker', (string) $tenant->id, (string) $startAt, $mode, (string) $i];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, getenv());
    }

    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim(stream_get_contents($pipes[$i][1]).stream_get_contents($pipes[$i][2]));
        proc_close($proc);
    }

    $created = DB::table('agents')->where('tenant_id', $tenant->id)->count();

    return ['created' => $created, 'ok' => count(array_filter($out, fn ($o) => $o === 'OK')), 'refused' => count(array_filter($out, fn ($o) => $o === 'REFUSED')),
        'errors' => array_values(array_filter($out, fn ($o) => str_starts_with($o, 'ERROR')))];
};

$real = $run('real');
$naive = $run('naive');

printf("Base : %s — %d processus simultanés, limite %d\n", $database, $workers, $limit);
printf("  réel  : agents créés = %d (OK %d, refusés %d, erreurs %d)\n", $real['created'], $real['ok'], $real['refused'], count($real['errors']));
printf("  naïf  : agents créés = %d (OK %d, refusés %d, erreurs %d)  <- témoin : la course existe sans verrou\n", $naive['created'], $naive['ok'], $naive['refused'], count($naive['errors']));
foreach (array_merge($real['errors'], $naive['errors']) as $error) {
    echo "  {$error}\n";
}

$realOk = $real['created'] === $limit && $real['ok'] === $limit && $real['refused'] === $workers - $limit && $real['errors'] === [];
$raceSeen = $naive['created'] > $limit;

echo $realOk ? "PASS : le mode réel respecte la limite sous concurrence.\n" : "FAIL : la limite a été dépassée ou une erreur est survenue en mode réel.\n";
echo $raceSeen ? "TÉMOIN : le mode naïf dépasse la limite ({$naive['created']} > {$limit}) : le montage détecte bien la course.\n" : "ATTENTION : le témoin n'a pas observé de course (preuve non concluante, relancer).\n";

exit($realOk && $raceSeen ? 0 : 1);
