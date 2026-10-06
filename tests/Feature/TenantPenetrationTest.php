<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Crud;
use App\Livewire\Mobile\Remboursements;
use App\Livewire\Mobile\Ventes;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Commission;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\CaisseService;
use App\Services\RemboursementService;
use App\Services\UvDistributionService;
use App\Support\Mobile\Crud\Registry;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deuxième passe « auditeur » : on attaque volontairement l'isolation.
 * L'attaquant est un master du tenant A (toutes les permissions : un 403 ne peut donc pas masquer une
 * faille de tenant) ; la victime est le tenant B, rempli de vraies données métier.
 */
class TenantPenetrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $attacker;

    private User $victim;

    private ?string $victimNotificationId = null;

    /** @var array<class-string, int> premier identifiant de chaque modèle chez la victime */
    private array $victimIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);

        $this->a = Tenant::create(['name' => 'Attaquant SA']);
        $this->b = Tenant::create(['name' => 'Victime SA']);
        $this->attacker = $this->master($this->a);
        $this->victim = $this->master($this->b);

        $this->actingAs($this->victim);
        $this->populate('Victime');
        $this->victim->notify(new \App\Notifications\TestNotification);
        $this->victimNotificationId = $this->victim->notifications()->value('id');

        // Données de l'attaquant : témoin positif (il doit, lui, accéder à ses propres ressources).
        $this->actingAs($this->attacker);
        $this->populate('Attaquant');

        foreach ([Agent::class, UvTransaction::class, Remboursement::class, Charge::class, Commission::class, Fournisseur::class, Approvisionnement::class, Activity::class] as $model) {
            $this->victimIds[$model] = (int) $model::withoutGlobalScopes()->where('tenant_id', $this->b->id)->orderBy('id')->value('id');
        }
    }

    private function master(Tenant $tenant): User
    {
        return TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
    }

    /** Un jeu complet de données dans le tenant de l'utilisateur connecté. */
    private function populate(string $label): void
    {
        $user = auth()->user();

        PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'is_active' => true, 'sort_order' => 1]);
        $fournisseur = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true, 'name' => "Fournisseur {$label}"]);
        Approvisionnement::create(['user_id' => $user->id, 'fournisseur_id' => $fournisseur->id, 'amount' => 3000000, 'date_approvisionnement' => now()]);
        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 1000000, 'name' => "Agent {$label}"]);
        (new UvDistributionService)->distribute($agent->fresh(), 200000);
        app(RemboursementService::class)->process($agent->fresh(), 50000);
        $category = ChargeCategorie::create(['code' => 'loyer', 'name' => 'Loyer', 'is_active' => true]);
        Charge::create(['category_id' => $category->id, 'libelle' => "Loyer {$label}", 'montant' => 90000, 'date_charge' => today(), 'created_by' => $user->id]);
        app(CaisseService::class)->enregistrer('entree', 40000, "Apport {$label}");
        Commission::create(['is_global' => true, 'date_commission' => today(), 'volume_total_jour' => 1200000, 'commission_globale' => 6360, 'montant_vente' => 1200000, 'montant_commission' => 6360, 'nombre_transactions' => 3, 'seuil_min' => 1000000, 'seuil_max' => 1499999]);
        activity()->causedBy($user)->log("Action {$label}");
        \App\Models\PalierCommission::create(['seuil_min' => 1000000, 'seuil_max' => 1499999, 'commission' => 6360, 'is_active' => true]);
        \App\Models\CaisseCategorie::create(['name' => "Catégorie {$label}", 'sort_order' => 1, 'is_active' => true, 'is_system' => false]);
        \App\Models\SousDistributeur::create(['agent_id' => $agent->id, 'nom' => "Sous-dist {$label}", 'telephone' => '07'.random_int(10000000, 99999999), 'type' => 'standard', 'statut' => 'actif']);
        \App\Models\AgentPartenaire::create(['nom_entreprise' => "Partenaire {$label}", 'numero_telephone' => '05'.random_int(10000000, 99999999), 'lieu' => 'Abidjan', 'is_active' => true]);
    }

    // ---- 1. balayage de TOUTES les routes avec l'identifiant d'un autre tenant ------------------------------

    public function test_every_route_taking_an_id_refuses_the_other_tenants_record(): void
    {
        $this->actingAs($this->attacker);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $byParameter = [
            'agent' => Agent::class, 'charge' => Charge::class, 'transaction' => UvTransaction::class,
            'uvTransaction' => UvTransaction::class, 'remboursement' => Remboursement::class,
            'commission' => Commission::class, 'fournisseur' => Fournisseur::class, 'activity' => Activity::class,
        ];

        // slug de ressource Filament → modèle
        $filamentModels = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $filamentModels[$resource::getSlug()] = $resource::getModel();
        }
        $mobileModels = [];
        foreach (Registry::all() as $resource) {
            $mobileModels[$resource::slug()] = $resource::model();
        }

        $attacks = $skipped = $leaks = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $parameters = $route->parameterNames();

            if ($parameters === [] || preg_match('#^(livewire|sanctum|_|storage|up$|platform|agent/)#', $uri) || str_contains($uri, 'webhooks')) {
                continue;
            }

            $path = null;
            $model = null;

            if (str_starts_with($uri, 'admin/') && in_array('record', $parameters, true)) {
                $slug = explode('/', $uri)[1];
                $model = $filamentModels[$slug] ?? null;
                $replace = ['record' => $model ? $this->victimIdOf($model) : null];
            } elseif (str_starts_with($uri, 'm/g/') && $parameters === ['resource', 'id']) {
                foreach ($mobileModels as $slug => $class) {
                    $id = $this->victimIdOf($class);
                    $this->assertNotNull($id, "La victime n'a aucun enregistrement pour {$slug} : le balayage ne le couvrirait pas.");
                    $attacks[] = [['GET'], '/m/g/'.$slug.'/'.$id];
                    $attacks[] = [['GET'], '/m/g/'.$slug.'/'.$id.'/modifier'];
                }

                continue;
            } else {
                $replace = [];
                foreach ($parameters as $parameter) {
                    if (isset($byParameter[$parameter])) {
                        $replace[$parameter] = $this->victimIds[$byParameter[$parameter]] ?? null;
                    }
                }
            }

            // Seules les routes qui désignent une ressource d'un tenant sont attaquées ; une route qui ne prend
            // qu'une date ou un format (ex. /m/caisse/{date}) lit ses PROPRES données, ce n'est pas un IDOR.
            $targetsARecord = array_filter($replace, fn ($v) => $v !== null) !== [] || in_array('id', $parameters, true);
            if (! $targetsARecord) {
                continue;
            }

            // paramètres qui ne désignent pas une ressource d'un tenant : valeurs d'exemple valides
            $fillers = ['format' => 'pdf', 'period' => 'month', 'module' => 'ventes', 'type' => 'transactions', 'date' => today()->toDateString()];
            foreach ($parameters as $parameter) {
                if (! isset($replace[$parameter]) && isset($fillers[$parameter])) {
                    $replace[$parameter] = $fillers[$parameter];
                }
            }

            // `id` : journal d'activité ou notifications de la victime selon la route
            if (in_array('id', $parameters, true) && ! isset($replace['id'])) {
                $replace['id'] = str_starts_with($uri, 'api/notifications') ? $this->victimNotificationId : ($this->victimIds[Activity::class] ?? null);
            }

            $unmapped = array_diff($parameters, array_keys(array_filter($replace, fn ($v) => $v !== null)));

            // Routes volontairement hors balayage, avec la raison :
            //  - m/g/{resource}[/nouveau] : liste / création, aucun identifiant d'enregistrement ;
            //  - filament/exports|imports : téléchargements propres à Filament, liés à l'utilisateur (non utilisés) ;
            //  - vente-uvs / remboursement-agents : tables héritées qui n'existent plus en base.
            if ($unmapped !== [] && preg_match('#^(m/g/\{resource\}|filament/(exports|imports)/|admin/(vente-uvs|remboursement-agents)/)#', $uri)) {
                continue;
            }

            if ($unmapped !== []) {
                $skipped[] = "{$uri} (".implode(',', $unmapped).')';

                continue;
            }

            $url = $uri;
            foreach ($replace as $name => $id) {
                $url = preg_replace('/\{'.$name.'\??\}/', (string) $id, $url);
            }
            $attacks[] = [array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS'])), '/'.ltrim($url, '/')];
        }

        $this->assertGreaterThan(40, count($attacks), 'Le balayage doit couvrir de nombreuses routes.');

        foreach ($attacks as [$methods, $url]) {
            foreach ($methods as $method) {
                if (str_starts_with($url, '/api/')) {
                    Sanctum::actingAs($this->attacker);
                    $response = $this->json($method, $url, []);
                } else {
                    $response = $this->call($method, $url);
                }

                if (! in_array($response->getStatusCode(), [403, 404, 405, 419], true)) {
                    $leaks[] = "{$method} {$url} → {$response->getStatusCode()}";
                }
            }
        }

        $this->assertSame([], $leaks, "Routes qui répondent autre chose que 403/404 pour une ressource d'un autre tenant :\n".implode("\n", $leaks));
        $this->assertSame([], array_values(array_unique($skipped)), 'Routes à identifiant non couvertes par le balayage : '.implode(', ', $skipped));
    }

    /** Témoin positif : le même balayage ne tombe pas à côté, l'attaquant accède bien à SES ressources. */
    public function test_the_attacker_can_open_his_own_resources(): void
    {
        $this->actingAs($this->attacker);
        $own = fn (string $model) => (int) $model::withoutGlobalScopes()->where('tenant_id', $this->a->id)->orderBy('id')->value('id');

        $this->get("/m/agents/{$own(Agent::class)}")->assertOk();
        $this->get("/m/charges/{$own(Charge::class)}")->assertOk();
        $this->get("/m/ventes/{$own(UvTransaction::class)}")->assertOk();
        $this->get("/m/g/fournisseurs/{$own(Fournisseur::class)}")->assertOk();

        // API : la liaison au modèle fonctionne pour ses propres transactions
        Sanctum::actingAs($this->attacker);
        $this->getJson("/api/transactions/{$own(UvTransaction::class)}")->assertOk()->assertJsonPath('data.id', $own(UvTransaction::class));
    }

    private function victimIdOf(?string $model): ?int
    {
        if (! $model) {
            return null;
        }

        return $this->victimIds[$model] ??= (($id = $model::withoutGlobalScopes()->where('tenant_id', $this->b->id)->orderBy('id')->value('id')) ? (int) $id : null);
    }

    // ---- 2. paramètres de requête et corps JSON ---------------------------------------------------------------

    public function test_filters_and_forged_ids_never_return_the_other_tenants_data(): void
    {
        Sanctum::actingAs($this->attacker);
        $victimAgent = $this->victimIds[Agent::class];

        $queries = [
            '/api/agents?tenant_id='.$this->b->id,
            '/api/agents?user_id='.$this->victim->id.'&organization_id='.$this->b->id,
            '/api/transactions?agent_id='.$victimAgent,
            '/api/remboursements?agent_id='.$victimAgent,
            '/api/fournisseurs?tenant_id='.$this->b->id.'&search=Fournisseur',
            '/api/activities?causer_id='.$this->victim->id.'&subject_id='.$victimAgent,
            '/api/dashboard/realtime?tenant_id='.$this->b->id,
            '/api/reports/agents?agent_id='.$victimAgent,
        ];

        foreach ($queries as $url) {
            $response = $this->getJson($url);
            $this->assertLessThan(500, $response->getStatusCode(), "Erreur serveur sur {$url}");
            $body = $response->getContent();
            $this->assertStringNotContainsString('Victime', $body, "Fuite via {$url}");
            $this->assertStringNotContainsString($this->victim->email, $body, "Fuite via {$url}");
        }
    }

    public function test_mass_assignment_of_tenant_owner_and_role_fields_is_ignored(): void
    {
        Sanctum::actingAs($this->attacker);

        $id = $this->postJson('/api/agents', [
            'name' => 'Pirate', 'phone' => '0788888888',
            'tenant_id' => $this->b->id, 'user_id' => $this->victim->id, 'organization_id' => $this->b->id,
            'current_debt' => 0, 'status' => 'active',
        ])->assertCreated()->json('data.id');

        $agent = Agent::withoutGlobalScopes()->find($id);
        $this->assertSame($this->a->id, (int) $agent->tenant_id);
        $this->assertNotSame($this->victim->id, (int) $agent->user_id);

        // tenter de se promouvoir via le profil : aucun champ de rôle n'est accepté
        $this->putJson("/api/agents/{$id}", ['tenant_id' => $this->b->id])->assertOk();
        $this->assertSame($this->a->id, (int) Agent::withoutGlobalScopes()->find($id)->tenant_id);
    }

    // ---- 3. manipulation des propriétés Livewire envoyées par le client --------------------------------------

    public function test_a_forged_agent_id_in_a_livewire_form_cannot_target_the_other_tenant(): void
    {
        $this->actingAs($this->attacker);
        $victimAgent = $this->victimIds[Agent::class];
        $before = UvTransaction::withoutGlobalScopes()->where('agent_id', $victimAgent)->count();
        $paymentsBefore = Remboursement::withoutGlobalScopes()->where('agent_id', $victimAgent)->count();

        Livewire::test(Ventes\Create::class)
            ->set('agentId', $victimAgent)->set('amount', '10000')
            ->call('save')->assertHasErrors('agentId');

        Livewire::test(Remboursements\Create::class)
            ->set('agentId', $victimAgent)->set('amount', '10000')
            ->call('save');

        $this->assertSame($before, UvTransaction::withoutGlobalScopes()->where('agent_id', $victimAgent)->count());
        $this->assertSame($paymentsBefore, Remboursement::withoutGlobalScopes()->where('agent_id', $victimAgent)->count());
    }

    public function test_the_generic_crud_screens_refuse_the_other_tenants_record_ids(): void
    {
        $this->actingAs($this->attacker);

        foreach (Registry::all() as $resource) {
            $id = $this->victimIdOf($resource::model());
            if ($id === null) {
                continue;
            }

            foreach ([Crud\Show::class, Crud\Form::class] as $component) {
                try {
                    Livewire::test($component, ['resource' => $resource::slug(), 'id' => (string) $id]);
                    $this->fail("{$component} a rendu l'enregistrement {$resource::slug()}#{$id} d'un autre tenant.");
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $this->assertTrue(true); // 404/403 : refusé
                }
            }
        }
    }

    // ---- 4. élévation de privilèges ---------------------------------------------------------------------------------

    public function test_a_tenant_master_cannot_grant_himself_or_others_the_super_admin_role(): void
    {
        $this->actingAs($this->attacker);
        $target = $this->master($this->a);

        $component = Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs', 'id' => (string) $target->id]);
        $component->set('data.roles', ['super_admin'])->call('save');

        $this->assertFalse(User::withoutGlobalScopes()->find($target->id)->hasRole('super_admin'));
        $this->assertFalse($this->attacker->fresh()->hasRole('super_admin'));

        // Même attribué par un accès direct à la base, il n'ouvre ni /platform ni les sauvegardes.
        $this->attacker->assignRole('super_admin');
        $this->actingAs($this->attacker->fresh())->get('/platform')->assertForbidden();
        $this->get('/backup/download/'.base64_encode('app/x.zip'))->assertForbidden();
    }

    // ---- 5. un compte d'un tenant ne peut rien sur un utilisateur d'un autre --------------------------------

    public function test_users_of_other_tenants_cannot_be_read_edited_or_deleted(): void
    {
        $this->actingAs($this->attacker);

        $this->assertNull(User::find($this->victim->id));
        $this->get("/m/g/utilisateurs/{$this->victim->id}")->assertNotFound();
        $this->get("/m/g/utilisateurs/{$this->victim->id}/modifier")->assertNotFound();
        $this->assertTrue(User::withoutGlobalScopes()->whereKey($this->victim->id)->exists());
    }

    // ---- 6. tenant suspendu avec une session déjà ouverte ---------------------------------------------------------

    public function test_a_suspended_tenants_open_session_cannot_download_receipts_exports_or_files(): void
    {
        $remboursement = $this->victimIds[Remboursement::class];
        $charge = $this->victimIds[Charge::class];
        $victim = $this->victim->fresh();

        $this->actingAs($victim)->get("/remboursement/{$remboursement}/receipt")->assertOk();

        // La suspension survient alors que la session existe encore : elle ne doit plus rien ouvrir.
        $this->b->suspend();
        $victim = $victim->fresh();

        foreach (["/remboursement/{$remboursement}/receipt", "/remboursement/{$remboursement}/receipt/download", "/charges/justificatif/{$charge}/download", '/exports/download/x.xlsx', '/exports/mes-donnees', '/m'] as $url) {
            $this->actingAs($victim)->get($url)->assertForbidden();
        }
    }
}
