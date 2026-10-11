<?php

namespace Tests\Feature\Inventory;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E2.7 — prova de concorrência REAL em PostgreSQL.
 *
 * Cada "cliente" é um PROCESSO PHP separado (conexão própria) executando
 * `OrderService::createFromCart` de verdade. O processo do teste só orquestra:
 * semeia os dados com commit (fora da transação do RefreshDatabase, senão os filhos
 * não os enxergariam), segura uma barreira (`pg_advisory_lock`) para largar todos
 * no mesmo instante e confere o estado final no banco.
 *
 * Sem `lockForUpdate()` no OrderService o teste de "último item" falha: os dois
 * processos leem estoque 1 e o segundo estoura no CHECK (23514) em vez de receber a
 * exceção de domínio.
 */
class InventoryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Connection $admin;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());

        Config::set('database.connections.inv_admin', Config::get('database.connections.pgsql'));
        DB::purge('inv_admin');
        $this->admin = DB::connection('inv_admin');

        $this->tenantId = (string) Str::uuid();
        $this->admin->table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Concorrência',
            'slug' => 'conc-'.substr($this->tenantId, 0, 8),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->admin->transactionLevel() > 0) {
                $this->admin->rollBack();
            }
            $this->admin->table('order_items')->where('tenant_id', $this->tenantId)->delete();
            $this->admin->table('orders')->where('tenant_id', $this->tenantId)->delete();
            $this->admin->table('customers')->where('tenant_id', $this->tenantId)->delete();
            $this->admin->table('tenant_counters')->where('tenant_id', $this->tenantId)->delete();
            $this->admin->table('products')->where('tenant_id', $this->tenantId)->delete();
            $this->admin->table('tenants')->where('id', $this->tenantId)->delete();
            $this->admin->disconnect();
        } finally {
            parent::tearDown();
        }
    }

    private function seedProduct(bool $track, ?int $stock): string
    {
        $id = (string) Str::uuid();
        $this->admin->table('products')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'name' => 'Último bolo',
            'slug' => 'ultimo-bolo',
            'price_cents' => 1000,
            'track_inventory' => $track,
            'stock_quantity' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array{proc: resource, pipes: array<int, resource>, out: string}
     */
    private function spawn(string $productId, int $qty, int $advisoryKey): array
    {
        $cmd = [
            PHP_BINARY,
            __DIR__.'/Support/place_order_child.php',
            $this->tenantId,
            $productId,
            (string) $qty,
            (string) $advisoryKey,
        ];

        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc, 'não foi possível iniciar o processo-filho');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'err' => ''];
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>> resultados JSON, na ordem dos filhos
     */
    private function collect(array $children, int $timeoutSeconds = 60): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $running = false;
            foreach ($children as &$c) {
                $c['out'] .= (string) stream_get_contents($c['pipes'][1]);
                $c['err'] .= (string) stream_get_contents($c['pipes'][2]);
                if (proc_get_status($c['proc'])['running']) {
                    $running = true;
                }
            }
            unset($c);

            if (! $running) {
                break;
            }
            if (microtime(true) > $deadline) {
                foreach ($children as $c) {
                    proc_terminate($c['proc']);
                }
                $this->fail('timeout aguardando processos-filho (deadlock?)');
            }
            usleep(20_000);
        }

        $results = [];
        foreach ($children as $c) {
            $c['out'] .= (string) stream_get_contents($c['pipes'][1]);
            $c['err'] .= (string) stream_get_contents($c['pipes'][2]);
            fclose($c['pipes'][1]);
            fclose($c['pipes'][2]);
            proc_close($c['proc']);

            $line = trim(strrchr("\n".trim($c['out']), "\n") ?: '');
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "saída inesperada do filho: [{$c['out']}] stderr: [{$c['err']}]");
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, int> contagem por resultado, chaves ordenadas
     */
    private function outcomes(array $results): array
    {
        $counts = array_count_values(array_column($results, 'result'));
        ksort($counts);

        return $counts;
    }

    /**
     * Espera todos os filhos estarem parados na barreira e solta todos de uma vez.
     */
    private function runRace(string $productId, int $qty, int $clients): array
    {
        $key = random_int(1_000_000, 2_000_000_000);

        $this->admin->select('select pg_advisory_lock(?)', [$key]);

        $children = [];
        for ($i = 0; $i < $clients; $i++) {
            $children[] = $this->spawn($productId, $qty, $key);
        }

        $deadline = microtime(true) + 60;
        do {
            $waiting = (int) $this->admin->selectOne(
                "select count(*) as n from pg_locks where locktype = 'advisory' and not granted and objid = ?",
                [$key & 0xFFFFFFFF]
            )->n;
            usleep(20_000);
        } while ($waiting < $clients && microtime(true) < $deadline);

        $this->assertSame($clients, $waiting, 'nem todos os processos chegaram à barreira');

        $this->admin->select('select pg_advisory_unlock(?)', [$key]);

        return $this->collect($children);
    }

    public function test_concurrent_sale_of_the_last_item_lets_exactly_one_order_through(): void
    {
        $productId = $this->seedProduct(track: true, stock: 1);

        $results = $this->runRace($productId, qty: 1, clients: 2);

        $this->assertSame(['insufficient' => 1, 'ok' => 1], $this->outcomes($results), json_encode($results));

        $this->assertSame(0, (int) $this->admin->table('products')->where('id', $productId)->value('stock_quantity'));
        $this->assertSame(1, $this->admin->table('orders')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(1, $this->admin->table('order_items')->where('tenant_id', $this->tenantId)->count());

        // O perdedor não consumiu número de pedido: a numeração segue sem buraco.
        $this->assertSame(1, (int) $this->admin->table('tenant_counters')
            ->where('tenant_id', $this->tenantId)->where('key', 'orders')->value('value'));
    }

    public function test_many_clients_never_oversell_and_order_numbers_have_no_gaps(): void
    {
        $productId = $this->seedProduct(track: true, stock: 3);

        $results = $this->runRace($productId, qty: 1, clients: 6);

        $this->assertSame(['insufficient' => 3, 'ok' => 3], $this->outcomes($results), json_encode($results));

        $stock = (int) $this->admin->table('products')->where('id', $productId)->value('stock_quantity');
        $this->assertSame(0, $stock);
        $this->assertGreaterThanOrEqual(0, $stock);

        $numbers = $this->admin->table('orders')->where('tenant_id', $this->tenantId)->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();
        $this->assertSame([1, 2, 3], $numbers);
    }

    public function test_untracked_product_sells_concurrently_without_any_limit(): void
    {
        $productId = $this->seedProduct(track: false, stock: null);

        $results = $this->runRace($productId, qty: 100, clients: 3);

        $this->assertSame(['ok' => 3], $this->outcomes($results), json_encode($results));
        $this->assertNull($this->admin->table('products')->where('id', $productId)->value('stock_quantity'));
        $this->assertSame(3, $this->admin->table('orders')->where('tenant_id', $this->tenantId)->count());
    }

    /**
     * Prova direta do lock: com a linha do produto travada por outra transação, o
     * fechamento do pedido ESPERA (não lê estoque velho) e, ao liberar, decide com o
     * valor commitado.
     */
    public function test_order_waits_on_the_product_row_lock_and_decides_with_the_committed_stock(): void
    {
        $productId = $this->seedProduct(track: true, stock: 1);

        $this->admin->beginTransaction();
        $this->admin->table('products')->where('id', $productId)->lockForUpdate()->value('stock_quantity');

        // key sem ninguém segurando: o filho não espera na barreira, vai direto ao pedido.
        $child = $this->spawn($productId, 1, random_int(1_000_000, 2_000_000_000));

        sleep(2);
        $this->assertTrue(proc_get_status($child['proc'])['running'], 'o pedido deveria estar bloqueado esperando o lock do produto');
        $this->assertSame(0, $this->admin->table('orders')->where('tenant_id', $this->tenantId)->count());

        // Outra "venda" esvazia o estoque e commita; o filho destrava e precisa ver 0.
        $this->admin->table('products')->where('id', $productId)->update(['stock_quantity' => 0]);
        $this->admin->commit();

        [$result] = $this->collect([$child]);

        $this->assertSame('insufficient', $result['result'], json_encode($result));
        $this->assertSame(0, $result['available']);
        $this->assertSame(0, $this->admin->table('orders')->where('tenant_id', $this->tenantId)->count());
    }
}
