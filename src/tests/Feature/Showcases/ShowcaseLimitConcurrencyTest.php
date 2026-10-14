<?php

namespace Tests\Feature\Showcases;

use App\Models\Showcase;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VAL-004 (sprint 03) — o limite de vitrines por tenant não pode ser ultrapassado
 * por criações simultâneas. Prova REAL em PostgreSQL, no padrão do
 * `InventoryConcurrencyTest`: cada "admin" é um PROCESSO PHP separado, solto no mesmo
 * instante por uma barreira (`pg_advisory_lock`); o estado final é conferido no banco.
 *
 * Sem o `lock` da linha do tenant em `Showcase::createWithinLimit()`, todos os
 * processos contam 8 e passam: o teste falha com mais de 10 vitrines.
 */
class ShowcaseLimitConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Connection $admin;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());

        Config::set('database.connections.showcase_admin', Config::get('database.connections.pgsql'));
        DB::purge('showcase_admin');
        $this->admin = DB::connection('showcase_admin');

        $this->tenantId = (string) Str::uuid();
        $this->admin->table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Concorrência de vitrines',
            'slug' => 'vit-'.substr($this->tenantId, 0, 8),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->admin->table('showcases')->where('tenant_id', $this->tenantId)->delete();
            $this->admin->table('tenants')->where('id', $this->tenantId)->delete();
            $this->admin->disconnect();
        } finally {
            parent::tearDown();
        }
    }

    private function seedShowcases(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->admin->table('showcases')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'name' => "Existente {$i}",
                'slug' => "existente-{$i}",
                'type' => Showcase::TYPE_ALL,
                'display_mode' => 'list',
                'max_items' => 8,
                'sort_order' => $i,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array{proc: resource, pipes: array<int, resource>, out: string, err: string}
     */
    private function spawn(int $advisoryKey): array
    {
        $cmd = [PHP_BINARY, __DIR__.'/Support/create_showcase_child.php', $this->tenantId, (string) $advisoryKey];
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
     * @return list<array<string, mixed>>
     */
    private function runRace(int $clients): array
    {
        $key = random_int(1_000_000, 2_000_000_000);

        $this->admin->select('select pg_advisory_lock(?)', [$key]);

        $children = [];
        for ($i = 0; $i < $clients; $i++) {
            $children[] = $this->spawn($key);
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
     * @return array<string, int>
     */
    private function outcomes(array $results): array
    {
        $counts = array_count_values(array_column($results, 'result'));
        ksort($counts);

        return $counts;
    }

    public function test_simultaneous_creations_never_exceed_the_limit(): void
    {
        $this->seedShowcases(Showcase::MAX_SHOWCASES_PER_TENANT - 2);

        $results = $this->runRace(clients: 6);

        $this->assertSame(['limit' => 4, 'ok' => 2], $this->outcomes($results), json_encode($results));
        $this->assertSame(
            Showcase::MAX_SHOWCASES_PER_TENANT,
            $this->admin->table('showcases')->where('tenant_id', $this->tenantId)->count(),
        );
    }

    public function test_with_one_slot_left_exactly_one_of_many_wins(): void
    {
        $this->seedShowcases(Showcase::MAX_SHOWCASES_PER_TENANT - 1);

        $results = $this->runRace(clients: 4);

        $this->assertSame(['limit' => 3, 'ok' => 1], $this->outcomes($results), json_encode($results));
        $this->assertSame(10, $this->admin->table('showcases')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_sort_order_is_unique_among_simultaneous_creations(): void
    {
        $results = $this->runRace(clients: 5);

        $this->assertSame(['ok' => 5], $this->outcomes($results), json_encode($results));

        $orders = $this->admin->table('showcases')->where('tenant_id', $this->tenantId)->orderBy('sort_order')->pluck('sort_order')->map(fn ($n) => (int) $n)->all();
        $this->assertSame([1, 2, 3, 4, 5], $orders);
    }
}
