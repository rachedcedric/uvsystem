<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Exceptions\SubscriptionLimitReached;
use App\Models\Agent;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;

/**
 * Risque « limites non atomiques ». Ici : la preuve de STRUCTURE (une transaction, le verrou du tenant pris AVANT le
 * comptage, le comptage AVANT l'insertion). La preuve de comportement concurrent réel (8 processus simultanés sur
 * MariaDB) est le script scripts/check-limit-concurrency.php (voir docs/SUBSCRIPTIONS.md).
 */
class SubscriptionAtomicityTest extends SubscriptionTestCase
{
    private function tenantWithAgentLimit(int $limit): Tenant
    {
        $tenant = $this->tenant();
        $this->plan('essentiel')->update(['max_agents' => $limit]);
        app(SubscriptionManager::class)->activate($tenant, $this->plan('essentiel'), SubscriptionCycle::Monthly);

        return $tenant;
    }

    /** @return list<string> requêtes SQL et bornes de transaction (« begin », « commit », « rollback »), dans l'ordre */
    private function sqlOf(callable $callback): array
    {
        $sequence = [];
        $events = app('events');
        $events->listen(QueryExecuted::class, function ($e) use (&$sequence) {
            $sequence[] = $e->sql;
        });
        $events->listen(TransactionBeginning::class, function () use (&$sequence) {
            $sequence[] = 'begin';
        });
        $events->listen(TransactionCommitted::class, function () use (&$sequence) {
            $sequence[] = 'commit';
        });
        $events->listen(TransactionRolledBack::class, function () use (&$sequence) {
            $sequence[] = 'rollback';
        });

        $callback();

        return $sequence;
    }

    private function firstIndex(array $queries, callable $match): ?int
    {
        foreach ($queries as $i => $sql) {
            if ($match(strtolower($sql))) {
                return $i;
            }
        }

        return null;
    }

    public function test_creating_an_agent_locks_the_tenant_then_counts_then_inserts_in_one_transaction(): void
    {
        $tenant = $this->tenantWithAgentLimit(5);

        $queries = $this->sqlOf(fn () => TenantContext::runAs($tenant->id, fn () => Agent::factory()->create()));

        $begin = $this->firstIndex($queries, fn ($q) => $q === 'begin');
        $lock = $this->firstIndex($queries, fn ($q) => str_contains($q, 'from "tenants"') || str_contains($q, 'from `tenants`'));
        $count = $this->firstIndex($queries, fn ($q) => str_contains($q, 'count(*)') && (str_contains($q, '"agents"') || str_contains($q, '`agents`')));
        $insert = $this->firstIndex($queries, fn ($q) => str_starts_with($q, 'insert into "agents"') || str_starts_with($q, 'insert into `agents`'));
        $release = $this->firstIndex($queries, fn ($q) => $q === 'commit');

        foreach (['begin' => $begin, 'lock' => $lock, 'count' => $count, 'insert' => $insert, 'release' => $release] as $name => $index) {
            $this->assertNotNull($index, "étape « $name » absente : ".implode(' | ', $queries));
        }
        $this->assertTrue($begin < $lock && $lock < $count && $count < $insert && $insert < $release,
            'ordre attendu : transaction, verrou du tenant, comptage, insertion, validation');
    }

    public function test_the_tenant_row_lock_is_a_real_for_update_on_engines_that_support_it(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite ignore FOR UPDATE (écrivain unique) : vérifié sur MariaDB/MySQL.');
        }

        $tenant = $this->tenantWithAgentLimit(5);
        $queries = $this->sqlOf(fn () => TenantContext::runAs($tenant->id, fn () => Agent::factory()->create()));

        $this->assertNotNull($this->firstIndex($queries, fn ($q) => str_contains($q, 'from `tenants`') && str_contains($q, 'for update')));
    }

    public function test_a_refused_creation_inserts_nothing_and_leaves_no_open_transaction(): void
    {
        $tenant = $this->tenantWithAgentLimit(1);
        TenantContext::runAs($tenant->id, fn () => Agent::factory()->create());
        $levelBefore = DB::transactionLevel();

        $queries = $this->sqlOf(function () use ($tenant) {
            $this->assertThrows(fn () => TenantContext::runAs($tenant->id, fn () => Agent::factory()->create()), SubscriptionLimitReached::class);
        });

        $this->assertNull($this->firstIndex($queries, fn ($q) => str_starts_with($q, 'insert into "agents"') || str_starts_with($q, 'insert into `agents`')), 'aucune insertion');
        $this->assertSame($levelBefore, DB::transactionLevel(), 'la transaction est bien refermée (rollback)');
        $this->assertSame(1, Agent::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_restoring_a_soft_deleted_agent_is_also_serialised(): void
    {
        $tenant = $this->tenantWithAgentLimit(5);
        $agent = TenantContext::runAs($tenant->id, fn () => Agent::factory()->create());
        TenantContext::runAs($tenant->id, fn () => $agent->delete());

        $queries = $this->sqlOf(fn () => TenantContext::runAs($tenant->id, fn () => $agent->restore()));

        $lock = $this->firstIndex($queries, fn ($q) => str_contains($q, 'from "tenants"') || str_contains($q, 'from `tenants`'));
        $update = $this->firstIndex($queries, fn ($q) => str_starts_with($q, 'update "agents"') || str_starts_with($q, 'update `agents`'));
        $this->assertNotNull($lock);
        $this->assertTrue($lock < $update, 'verrou avant la mise à jour de restauration');
    }

    public function test_the_lock_of_one_tenant_is_scoped_to_its_own_row(): void
    {
        $a = $this->tenantWithAgentLimit(5);
        $queries = $this->sqlOf(fn () => TenantContext::runAs($a->id, fn () => Agent::factory()->create()));

        $lockQuery = collect($queries)->first(fn ($q) => str_contains(strtolower($q), 'from "tenants"') || str_contains(strtolower($q), 'from `tenants`'));
        $this->assertStringContainsString('"id" = ?', str_replace('`', '"', $lockQuery), 'verrou par identifiant de tenant, pas global');
    }
}
