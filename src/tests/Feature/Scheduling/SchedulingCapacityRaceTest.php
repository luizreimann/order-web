<?php

namespace Tests\Feature\Scheduling;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Duas gravações ao mesmo tempo no último lugar. Cada cliente é um processo PHP
 * com conexão própria. O pai segura `pg_advisory_lock` de um argumento — número
 * próprio, não `hashtext('order-schedule')` — e solta os dois juntos. O lock da
 * vaga é o da aplicação, dentro da transação do pedido.
 *
 * O relógio não é congelado: a data fica dois dias à frente em America/Sao_Paulo,
 * dentro da janela mesmo se o dia virar entre o pai e o filho.
 */
class SchedulingCapacityRaceTest extends TestCase
{
    use RefreshDatabase;

    private Connection $admin;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());

        Config::set('database.connections.sched_admin', Config::get('database.connections.pgsql'));
        DB::purge('sched_admin');
        $this->admin = DB::connection('sched_admin');

        $this->tenantId = (string) Str::uuid();
        $now = now();

        $this->admin->insert(
            'insert into tenants (id, name, slug, is_active, settings, created_at, updated_at) values (?, ?, ?, ?, ?::jsonb, ?, ?)',
            [
                $this->tenantId,
                'Concorrência de vaga',
                'vaga-'.substr($this->tenantId, 0, 8),
                true,
                json_encode($this->settings(), JSON_THROW_ON_ERROR),
                $now,
                $now,
            ],
        );
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

    private function seedProduct(): string
    {
        $id = (string) Str::uuid();
        $this->admin->table('products')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'name' => 'Bolo do dia',
            'slug' => 'bolo-do-dia',
            'price_cents' => 2000,
            'is_active' => true,
            'track_inventory' => false,
            'stock_quantity' => null,
            'min_days_notice' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array{proc: resource, pipes: array<int, resource>, out: string, err: string}
     */
    private function spawn(string $productId, string $date, int $advisoryKey): array
    {
        $cmd = [
            PHP_BINARY,
            __DIR__.'/Support/place_scheduled_order_child.php',
            $this->tenantId,
            $productId,
            $date,
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
     * @return list<array<string, mixed>>
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
     * @return array<string, int>
     */
    private function outcomes(array $results): array
    {
        $counts = array_count_values(array_column($results, 'result'));
        ksort($counts);

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runRace(string $productId, string $date, int $clients): array
    {
        // Um argumento só. O lock da vaga é o par hashtext('order-schedule'), hashtext(tenant|data).
        $key = random_int(1_000_000, 2_000_000_000);

        $this->admin->select('select pg_advisory_lock(?)', [$key]);

        $children = [];
        for ($i = 0; $i < $clients; $i++) {
            $children[] = $this->spawn($productId, $date, $key);
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

    public function test_two_closes_on_the_last_slot_let_exactly_one_order_through(): void
    {
        $productId = $this->seedProduct();
        $date = Carbon::now('America/Sao_Paulo')->startOfDay()->addDays(2)->toDateString();

        $results = $this->runRace($productId, $date, clients: 2);

        $this->assertSame(['ok' => 1, 'refused' => 1], $this->outcomes($results), json_encode($results));

        $refused = array_values(array_filter(
            $results,
            fn (array $result): bool => $result['result'] === 'refused',
        ));
        $this->assertSame('scheduled_on', $refused[0]['field']);
        $this->assertSame('Não há vaga neste dia.', $refused[0]['message']);

        $this->assertSame(1, Order::query()->where('tenant_id', $this->tenantId)->where('scheduled_on', $date)->count());
    }

    /**
     * Capacidade 1 em todo dia da semana, com a mesma opção 10:00. A data da corrida
     * cai em qualquer um deles e os dois processos mandam esse horário.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $days = [];

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $days[$day] = [
                'enabled' => true,
                'capacity' => 1,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                ],
            ];
        }

        return [
            'pickup' => ['enabled' => true, 'fee_cents' => 0],
            'business_hours' => ['timezone' => 'America/Sao_Paulo'],
            'scheduling' => [
                'enabled' => true,
                'min_days' => 0,
                'max_days' => 30,
                'weekdays' => $days,
                'off_days' => [],
            ],
        ];
    }
}
