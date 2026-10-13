<?php

/**
 * Preuve de comportement CONCURRENT de la souscription publique (idempotence et transaction).
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=… DB_DATABASE=<…scratch…> DB_USERNAME=… DB_PASSWORD= \
 *   APP_ENV=testing php scripts/check-signup-concurrency.php [travailleurs=8]
 *
 * Scénario A : N processus confirment au même instant LE MÊME lien -> exactement 1 entreprise, 1 master, 1 abonnement ;
 *              un processus crée, les autres reçoivent le tenant déjà créé (répétition idempotente).
 * Scénario B : N prospects DIFFÉRENTS (e-mails distincts) confirment au même instant le MÊME nom d'entreprise
 *              -> exactement 1 entreprise ; les autres sont refusés proprement, sans compte ni abonnement orphelin.
 * Écrit dans la base visée : réservé à une base jetable (refuse de tourner si son nom ne contient pas « scratch »).
 */

use App\Enums\SubscriptionCycle;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\TenantSignupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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
    [, , $token, $startAt] = $argv;
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }

    try {
        app(TenantSignupService::class)->complete($token, $created);
        echo $created ? "CREATED\n" : "REPLAY\n";
    } catch (ValidationException $e) {
        echo 'REFUSED '.collect($e->errors())->flatten()->first()."\n";
    } catch (Throwable $e) {
        echo 'ERROR '.get_class($e).': '.$e->getMessage()."\n";
    }
    exit(0);
}

// ---------------------------------------------------------------- orchestrateur
$workers = (int) ($argv[1] ?? 8);
Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
Notification::fake();
$plan = SubscriptionPlan::query()->where('slug', 'pro')->firstOrFail();
$service = app(TenantSignupService::class);
$run = uniqid();

$fire = function (array $tokens): array {
    $startAt = microtime(true) + 3.0;
    $procs = [];
    foreach ($tokens as $i => $token) {
        $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--worker', $token, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, getenv());
    }
    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim(stream_get_contents($pipes[$i][1]).stream_get_contents($pipes[$i][2]));
        proc_close($proc);
    }

    return $out;
};
$count = fn (array $out, string $prefix) => count(array_filter($out, fn ($o) => str_starts_with($o, $prefix)));

// A : même lien, N processus
$data = ['company_name' => "Course A {$run}", 'name' => 'Gérant A', 'email' => "a-{$run}@scratch.example", 'phone' => '0700000001', 'password' => 'Un-Mot-De-Passe-12'];
$token = $service->request($plan, SubscriptionCycle::Monthly, $data, '203.0.113.1');
$outA = $fire(array_fill(1, $workers, $token));
$tenantsA = Tenant::where('contact_email', $data['email'])->count();
$usersA = User::withoutGlobalScopes()->where('email', $data['email'])->count();
$subsA = DB::table('tenant_subscriptions')->whereIn('tenant_id', Tenant::where('contact_email', $data['email'])->pluck('id'))->count();

// B : N prospects distincts, même nom d'entreprise
$tokensB = [];
for ($i = 1; $i <= $workers; $i++) {
    $tokensB[$i] = $service->request($plan, SubscriptionCycle::Monthly, ['company_name' => "Course B {$run}", 'name' => "Gérant B{$i}", 'email' => "b{$i}-{$run}@scratch.example", 'phone' => sprintf('07100000%02d', $i), 'password' => 'Un-Mot-De-Passe-12'], "203.0.113.{$i}");
}
$outB = $fire($tokensB);
$tenantsB = Tenant::where('name', "Course B {$run}")->count();
$usersB = User::withoutGlobalScopes()->whereIn('email', array_map(fn ($i) => "b{$i}-{$run}@scratch.example", range(1, $workers)))->count();
$orphanSubsB = DB::table('tenant_subscriptions')->whereNotIn('tenant_id', DB::table('tenants')->pluck('id'))->count();

printf("Base : %s — %d processus simultanés\n", $database, $workers);
printf("A (même lien)        : créations=%d répétitions=%d refus=%d erreurs=%d | entreprises=%d masters=%d abonnements=%d\n", $count($outA, 'CREATED'), $count($outA, 'REPLAY'), $count($outA, 'REFUSED'), $count($outA, 'ERROR'), $tenantsA, $usersA, $subsA);
printf("B (même entreprise)  : créations=%d répétitions=%d refus=%d erreurs=%d | entreprises=%d masters=%d abonnements orphelins=%d\n", $count($outB, 'CREATED'), $count($outB, 'REPLAY'), $count($outB, 'REFUSED'), $count($outB, 'ERROR'), $tenantsB, $usersB, $orphanSubsB);
foreach (array_filter(array_merge($outA, $outB), fn ($o) => str_starts_with($o, 'ERROR') || str_starts_with($o, 'REFUSED')) as $line) {
    echo "  {$line}\n";
}

$okA = $count($outA, 'CREATED') === 1 && $count($outA, 'REPLAY') === $workers - 1 && $count($outA, 'ERROR') === 0 && $tenantsA === 1 && $usersA === 1 && $subsA === 1;
$okB = $tenantsB === 1 && $usersB === 1 && $count($outB, 'CREATED') === 1 && $count($outB, 'ERROR') === 0 && $orphanSubsB === 0;
echo $okA ? "PASS A : une seule entreprise malgré {$workers} confirmations simultanées du même lien.\n" : "FAIL A\n";
echo $okB ? "PASS B : une seule entreprise pour le même nom malgré {$workers} prospects simultanés, sans résidu.\n" : "FAIL B\n";

exit($okA && $okB ? 0 : 1);
