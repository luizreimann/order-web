<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantCounter;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cobertura que SÓ existe rodando em PostgreSQL.
 *
 * Todos os testes daqui passavam vazios (ou davam falso-positivo) em SQLite
 * in-memory: constraints eram checadas com outra semântica, `FOR UPDATE` era
 * ignorado pelo grammar do SQLite, `ON CONFLICT DO NOTHING` não era exercitado
 * e os operadores jsonb não existem.
 *
 * Este é o arquivo que comprova o ganho da migração do item E0.2.
 */
class PostgresFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Blindagem: se alguém reverter o phpunit.xml para SQLite, estes testes
        // falham explicitamente em vez de passar sem provar nada.
        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'A suíte precisa rodar em PostgreSQL (serviço db_test). Confira o phpunit.xml.'
        );
    }

    // -----------------------------------------------------------------
    // 1. Constraint unique composta de verdade
    // -----------------------------------------------------------------

    public function test_composite_unique_rejects_duplicate_number_within_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        Order::factory()->create(['tenant_id' => $tenant->id, 'number' => 1000]);

        // orders_tenant_id_number_unique deve barrar a duplicata.
        $this->expectException(UniqueConstraintViolationException::class);

        Order::factory()->create(['tenant_id' => $tenant->id, 'number' => 1000]);
    }

    public function test_composite_unique_allows_same_number_across_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $a = Order::factory()->create(['tenant_id' => $tenantA->id, 'number' => 500]);
        $b = Order::factory()->create(['tenant_id' => $tenantB->id, 'number' => 500]);

        // A constraint é COMPOSTA: o mesmo número em tenants distintos é válido.
        $this->assertSame(500, (int) $a->fresh()->number);
        $this->assertSame(500, (int) $b->fresh()->number);
        $this->assertSame(2, Order::query()->where('number', 500)->count());
    }

    public function test_composite_unique_allows_multiple_null_slugs_for_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        // No PostgreSQL, NULL nunca é igual a NULL, então a unique
        // (tenant_id, slug) aceita várias categorias sem slug do mesmo tenant.
        // A migration depende explicitamente dessa semântica.
        foreach (['Bolos', 'Doces', 'Salgados'] as $name) {
            DB::table('categories')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'name' => $name,
                'slug' => null,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            3,
            Category::query()->where('tenant_id', $tenant->id)->whereNull('slug')->count()
        );

        // Mas um slug NÃO nulo repetido continua sendo rejeitado.
        Category::query()->where('tenant_id', $tenant->id)->limit(1)->update(['slug' => 'bolos']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('categories')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Bolos again',
            'slug' => 'bolos',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // 2. Operadores jsonb
    // -----------------------------------------------------------------

    public function test_jsonb_operators_filter_orders_by_nested_meta(): void
    {
        $tenant = Tenant::factory()->create();

        $mp = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'meta' => ['payment' => ['provider' => 'mercadopago', 'payment_id' => '123456789']],
        ]);

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'meta' => ['payment' => ['provider' => 'manual']],
        ]);

        Order::factory()->create(['tenant_id' => $tenant->id, 'meta' => null]);

        // `meta->payment->>'provider'` — vira #>> no grammar do PG.
        $found = Order::query()
            ->where('tenant_id', $tenant->id)
            ->where('meta->payment->provider', 'mercadopago')
            ->get();

        $this->assertCount(1, $found);
        $this->assertSame($mp->id, $found->first()->id);

        // Contains usa o operador @> de jsonb.
        $contains = Order::query()
            ->where('tenant_id', $tenant->id)
            ->whereJsonContains('meta->payment->provider', 'manual')
            ->count();

        $this->assertSame(1, $contains);

        // Existência de chave aninhada.
        $withPaymentId = Order::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('meta->payment->payment_id')
            ->pluck('id');

        $this->assertSame([$mp->id], $withPaymentId->all());
    }

    public function test_jsonb_column_is_really_jsonb_and_survives_roundtrip(): void
    {
        $tenant = Tenant::factory()->create();

        $type = DB::selectOne(
            "select data_type from information_schema.columns
             where table_name = 'orders' and column_name = 'meta'"
        );

        $this->assertSame('jsonb', $type->data_type);

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'meta' => ['payment' => ['provider' => 'manual'], 'tags' => ['a', 'b'], 'n' => 7],
        ]);

        $fresh = $order->fresh();

        $this->assertSame('manual', $fresh->meta['payment']['provider']);
        $this->assertSame(['a', 'b'], $fresh->meta['tags']);
        $this->assertSame(7, $fresh->meta['n']);
    }

    // -----------------------------------------------------------------
    // 3. SELECT ... FOR UPDATE + ON CONFLICT DO NOTHING
    // -----------------------------------------------------------------

    public function test_next_value_emits_select_for_update_and_on_conflict_do_nothing(): void
    {
        $tenant = Tenant::factory()->create();

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        TenantCounter::nextValue($tenant->id, 'orders');

        $joined = implode("\n", $queries);

        // Em SQLite o grammar simplesmente descarta o `for update`; aqui ele é
        // enviado ao PostgreSQL e de fato trava a linha.
        $this->assertMatchesRegularExpression('/for update/i', $joined, $joined);

        // insertOrIgnore precisa virar ON CONFLICT DO NOTHING (sintaxe PG).
        $this->assertMatchesRegularExpression('/on conflict do nothing/i', $joined, $joined);
    }

    public function test_insert_or_ignore_does_not_reset_existing_counter(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(1, TenantCounter::nextValue($tenant->id, 'orders'));
        $this->assertSame(2, TenantCounter::nextValue($tenant->id, 'orders'));

        // O ON CONFLICT DO NOTHING do nextValue não pode zerar o contador que
        // já existe (seria um reset silencioso da numeração do tenant).
        $this->assertSame(3, TenantCounter::nextValue($tenant->id, 'orders'));

        $this->assertSame(
            3,
            (int) TenantCounter::queryFor($tenant->id, 'orders')->value('value')
        );

        // E continua existindo exatamente UMA linha para a PK composta.
        $this->assertSame(
            1,
            TenantCounter::query()
                ->where('tenant_id', $tenant->id)
                ->where('key', 'orders')
                ->count()
        );
    }

    /**
     * Prova que `lockForUpdate()` produz um lock de linha REAL no PostgreSQL.
     *
     * Usa duas conexões independentes (fora da transação do RefreshDatabase):
     * a conexão A trava a linha do contador, a conexão B tenta travar a mesma
     * linha com `lock_timeout` curto e precisa ESTOURAR o timeout. Depois que A
     * libera, B consegue. Isso é impossível de testar em SQLite `:memory:`,
     * onde cada conexão é um banco separado.
     */
    public function test_for_update_really_blocks_a_concurrent_connection(): void
    {
        $tenantId = (string) Str::uuid();

        $connA = $this->makeIndependentConnection('pg_lock_a');
        $connB = $this->makeIndependentConnection('pg_lock_b');

        // Linha criada FORA da transação de teste, para ser visível às duas
        // conexões. Limpamos manualmente no finally.
        $connA->table('tenant_counters')->insert([
            'tenant_id' => $tenantId,
            'key' => 'orders',
            'value' => 41,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $connA->beginTransaction();

            $locked = $connA->table('tenant_counters')
                ->where('tenant_id', $tenantId)
                ->where('key', 'orders')
                ->lockForUpdate()
                ->value('value');

            $this->assertSame(41, (int) $locked);

            // B não deve conseguir travar a mesma linha enquanto A não commita.
            $connB->statement("set lock_timeout = '750ms'");

            $blocked = false;
            try {
                $connB->table('tenant_counters')
                    ->where('tenant_id', $tenantId)
                    ->where('key', 'orders')
                    ->lockForUpdate()
                    ->value('value');
            } catch (QueryException $e) {
                // 55P03 = lock_not_available
                $blocked = true;
                $this->assertSame('55P03', $e->getCode(), $e->getMessage());
            }

            $this->assertTrue(
                $blocked,
                'SELECT ... FOR UPDATE não bloqueou a conexão concorrente: o lock de linha não está sendo aplicado.'
            );

            // A libera o lock...
            $connA->commit();

            // ...e agora B consegue travar e incrementar em sequência.
            $connB->beginTransaction();
            $value = (int) $connB->table('tenant_counters')
                ->where('tenant_id', $tenantId)
                ->where('key', 'orders')
                ->lockForUpdate()
                ->value('value');

            $connB->table('tenant_counters')
                ->where('tenant_id', $tenantId)
                ->where('key', 'orders')
                ->update(['value' => $value + 1]);

            $connB->commit();

            $this->assertSame(42, (int) $connA->table('tenant_counters')
                ->where('tenant_id', $tenantId)
                ->where('key', 'orders')
                ->value('value'));
        } finally {
            foreach ([$connA, $connB] as $conn) {
                try {
                    if ($conn->transactionLevel() > 0) {
                        $conn->rollBack();
                    }
                } catch (\Throwable) {
                    // ignora
                }
            }

            $connA->table('tenant_counters')->where('tenant_id', $tenantId)->delete();

            $connA->disconnect();
            $connB->disconnect();
        }
    }

    /**
     * Cria uma conexão PostgreSQL nova, independente da conexão de teste (que
     * está dentro da transação aberta pelo RefreshDatabase).
     */
    private function makeIndependentConnection(string $name): \Illuminate\Database\Connection
    {
        Config::set("database.connections.{$name}", Config::get('database.connections.pgsql'));

        DB::purge($name);

        return DB::connection($name);
    }
}
