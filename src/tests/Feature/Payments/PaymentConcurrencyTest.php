<?php

namespace Tests\Feature\Payments;

use App\Support\EncryptionHelper;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E3.3 / E3.4 — concorrência REAL em PostgreSQL (padrão de InventoryConcurrencyTest).
 *
 * Cada "cliente" é um PROCESSO PHP separado, com conexão própria, executando o código de
 * verdade (o kernel HTTP para o webhook; `PaymentService::begin` para o checkout). O teste
 * semeia com commit (fora da transação do RefreshDatabase, senão os filhos não veriam),
 * segura uma barreira (`pg_advisory_lock`) e solta todos juntos.
 *
 * O que quebra se o código regredir:
 *  - sem `lockForUpdate` + comparação de estado: duas transições, estoque devolvido 2x;
 *  - sem o UNIQUE/adoção sob lock: `UniqueConstraintViolation` (500) ou Payment duplicado;
 *  - sem o UNIQUE da idempotency_key: dois pedidos e dupla baixa de estoque no duplo submit.
 */
class PaymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-concurrency';

    private Connection $admin;

    private string $tenantId;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());

        Config::set('database.connections.pay_admin', Config::get('database.connections.pgsql'));
        DB::purge('pay_admin');
        $this->admin = DB::connection('pay_admin');

        $this->tenantId = (string) Str::uuid();
        $this->admin->table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pagamentos concorrentes',
            'slug' => 'pay-'.substr($this->tenantId, 0, 8),
            'is_active' => true,
            'settings' => json_encode(['payment' => ['mercadopago' => [
                'public_key' => 'TEST-pk',
                'access_token' => EncryptionHelper::encryptIfNeeded('TEST-token'),
                'webhook_secret' => EncryptionHelper::encryptIfNeeded(self::SECRET),
            ]]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (string) Str::uuid();
        $this->admin->table('products')->insert([
            'id' => $this->productId,
            'tenant_id' => $this->tenantId,
            'name' => 'Bolo',
            'slug' => 'bolo',
            'price_cents' => 1000,
            'track_inventory' => true,
            'stock_quantity' => 3, // 5 em estoque, 2 já baixados pelo pedido semeado
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
            foreach (['payment_webhook_events', 'payments', 'order_items', 'orders', 'customers', 'tenant_counters', 'products'] as $table) {
                $this->admin->table($table)->where('tenant_id', $this->tenantId)->delete();
            }
            $this->admin->table('tenants')->where('id', $this->tenantId)->delete();
            $this->admin->disconnect();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Pedido pendente que já baixou 2 unidades do estoque, com um pagamento.
     *
     * @return array{order: string, payment: string}
     */
    private function seedOrder(?string $gatewayPaymentId, string $paymentStatus): array
    {
        $orderId = (string) Str::uuid();
        $paymentId = (string) Str::uuid();

        $this->admin->table('orders')->insert([
            'id' => $orderId, 'tenant_id' => $this->tenantId, 'number' => 1,
            'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'mp_pix',
            'customer_name' => 'Cliente', 'subtotal_cents' => 2000, 'total_cents' => 2000,
            'meta' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('order_items')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'order_id' => $orderId,
            'product_id' => $this->productId, 'name' => 'Bolo', 'quantity' => 2, 'stock_deducted_quantity' => 2,
            'unit_price_cents' => 1000, 'total_cents' => 2000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('payments')->insert([
            'id' => $paymentId, 'tenant_id' => $this->tenantId, 'order_id' => $orderId, 'gateway' => 'mercadopago',
            'gateway_payment_id' => $gatewayPaymentId, 'idempotency_key' => 'seed-'.$paymentId, 'amount_cents' => 2000,
            'status' => $paymentStatus, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['order' => $orderId, 'payment' => $paymentId];
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function spawn(string $mode, array $args): array
    {
        $cmd = [PHP_BINARY, __DIR__.'/Support/payments_child.php', $mode, ...$args];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc, 'não foi possível iniciar o processo-filho');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'err' => ''];
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>>
     */
    private function collect(array $children, int $timeoutSeconds = 90): array
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
     * Larga N processos juntos. `$argsFor($barrierKey)` monta os argumentos do filho.
     *
     * @param  callable(int): list<string>  $argsFor
     * @return list<array<string, mixed>>
     */
    private function race(string $mode, int $clients, callable $argsFor): array
    {
        $key = random_int(1_000_000, 2_000_000_000);

        $this->admin->select('select pg_advisory_lock(?)', [$key]);

        $children = [];
        for ($i = 0; $i < $clients; $i++) {
            $children[] = $this->spawn($mode, $argsFor($key));
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

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertAllOk(array $results): void
    {
        foreach ($results as $r) {
            $this->assertSame('http', $r['result'], json_encode($r));
            $this->assertSame(200, $r['status'], 'webhook concorrente respondeu erro: '.json_encode($r));
        }
    }

    private function stock(): int
    {
        return (int) $this->admin->table('products')->where('id', $this->productId)->value('stock_quantity');
    }

    // ------------------------------------------------------------------ E3.3

    public function test_concurrent_approved_webhooks_cause_a_single_transition_and_no_error(): void
    {
        $ids = $this->seedOrder('880000001', 'pending');

        $results = $this->race('webhook', 6, fn ($key) => [$this->tenantId, $ids['order'], '880000001', 'approved', self::SECRET, (string) $key]);

        $this->assertAllOk($results);
        $transitions = array_filter(array_map(fn ($r) => $r['body']['transitioned'] ?? null, $results));
        $this->assertCount(1, $transitions, 'mais de um webhook aplicou a transição: '.json_encode($results));

        $this->assertSame('approved', $this->admin->table('payments')->where('id', $ids['payment'])->value('status'));
        $this->assertSame('paid', $this->admin->table('orders')->where('id', $ids['order'])->value('payment_status'));
        $this->assertSame(1, $this->admin->table('payment_webhook_events')->where('gateway_payment_id', '880000001')->count());
    }

    public function test_concurrent_rejected_webhooks_cancel_the_order_and_return_the_stock_exactly_once(): void
    {
        $ids = $this->seedOrder('880000002', 'pending');
        $this->assertSame(3, $this->stock());

        $results = $this->race('webhook', 6, fn ($key) => [$this->tenantId, $ids['order'], '880000002', 'rejected', self::SECRET, (string) $key]);

        $this->assertAllOk($results);
        $this->assertCount(1, array_filter(array_map(fn ($r) => $r['body']['transitioned'] ?? null, $results)));

        $this->assertSame('cancelled', $this->admin->table('orders')->where('id', $ids['order'])->value('status'));
        $this->assertSame(5, $this->stock(), 'estoque devolvido mais de uma vez (ou nenhuma) sob concorrência');
        $this->assertSame(1, $this->admin->table('payment_webhook_events')->where('gateway_payment_id', '880000002')->count());
    }

    public function test_concurrent_webhooks_for_an_unpersisted_gateway_id_adopt_the_attempt_once(): void
    {
        $ids = $this->seedOrder(null, 'created');

        $results = $this->race('webhook', 5, fn ($key) => [$this->tenantId, $ids['order'], '880000003', 'approved', self::SECRET, (string) $key]);

        $this->assertAllOk($results);
        $this->assertCount(1, array_filter(array_map(fn ($r) => $r['body']['transitioned'] ?? null, $results)));

        $payments = $this->admin->table('payments')->where('order_id', $ids['order'])->get();
        $this->assertCount(1, $payments, 'a adoção concorrente criou Payment duplicado');
        $this->assertSame('880000003', $payments[0]->gateway_payment_id);
        $this->assertSame('approved', $payments[0]->status);
    }

    // ------------------------------------------------------------------ E3.4

    public function test_concurrent_double_submit_creates_one_order_one_attempt_and_one_stock_deduction(): void
    {
        $this->admin->table('products')->where('id', $this->productId)->update(['stock_quantity' => 5]);
        $checkoutKey = hash('sha256', 'checkout-concorrente');

        $results = $this->race('begin', 4, fn ($key) => [$this->tenantId, $this->productId, '1', $checkoutKey, (string) $key]);

        $outcomes = array_count_values(array_column($results, 'result'));
        ksort($outcomes);
        $this->assertSame(['duplicate' => 3, 'fresh' => 1], $outcomes, json_encode($results));

        $this->assertSame(1, $this->admin->table('orders')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(1, $this->admin->table('payments')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(4, $this->stock(), 'a baixa de estoque aconteceu mais de uma vez no duplo submit');
        $this->assertSame([1], array_map('intval', $this->admin->table('orders')->where('tenant_id', $this->tenantId)->pluck('number')->all()));

        $orderIds = array_unique(array_column($results, 'order_id'));
        $this->assertCount(1, $orderIds, 'os duplicados deveriam apontar para o MESMO pedido');
    }
}
