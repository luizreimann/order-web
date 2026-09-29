<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCounterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    private function counterValue(Tenant $tenant): ?int
    {
        $counter = TenantCounter::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'orders')
            ->first();

        return $counter ? (int) $counter->value : null;
    }

    public function test_order_counter_is_isolated_between_tenants(): void
    {
        $n = 5;

        for ($i = 0; $i < $n; $i++) {
            Order::factory()->create(['tenant_id' => $this->tenantA->id]);
        }

        $this->assertSame($n, $this->counterValue($this->tenantA));

        // Um único pedido do tenant B não pode alterar o contador do tenant A.
        Order::factory()->create(['tenant_id' => $this->tenantB->id]);

        $this->assertSame($n, $this->counterValue($this->tenantA));
        $this->assertSame(1, $this->counterValue($this->tenantB));

        // O próximo pedido do tenant A deve continuar a sequência, sem colidir.
        $next = Order::factory()->create(['tenant_id' => $this->tenantA->id]);

        $this->assertSame($n + 1, (int) $next->number);
        $this->assertSame($n + 1, $this->counterValue($this->tenantA));
    }

    public function test_each_tenant_has_its_own_sequence(): void
    {
        $numbersA = [];
        $numbersB = [];

        for ($i = 0; $i < 3; $i++) {
            $numbersA[] = (int) Order::factory()->create(['tenant_id' => $this->tenantA->id])->number;
        }

        for ($i = 0; $i < 3; $i++) {
            $numbersB[] = (int) Order::factory()->create(['tenant_id' => $this->tenantB->id])->number;
        }

        $this->assertSame([1, 2, 3], $numbersA);
        $this->assertSame([1, 2, 3], $numbersB);
    }

    public function test_interleaved_orders_do_not_violate_unique_constraint(): void
    {
        $numbersA = [];
        $numbersB = [];

        for ($i = 0; $i < 5; $i++) {
            $numbersA[] = (int) Order::factory()->create(['tenant_id' => $this->tenantA->id])->number;
            $numbersB[] = (int) Order::factory()->create(['tenant_id' => $this->tenantB->id])->number;
        }

        $this->assertSame([1, 2, 3, 4, 5], $numbersA);
        $this->assertSame([1, 2, 3, 4, 5], $numbersB);

        $this->assertSame(5, Order::query()->where('tenant_id', $this->tenantA->id)->count());
        $this->assertSame(5, Order::query()->where('tenant_id', $this->tenantB->id)->count());
        $this->assertSame(5, $this->counterValue($this->tenantA));
        $this->assertSame(5, $this->counterValue($this->tenantB));
    }

    public function test_first_order_of_a_tenant_creates_counter_starting_at_one(): void
    {
        $this->assertNull($this->counterValue($this->tenantA));

        $order = Order::factory()->create(['tenant_id' => $this->tenantA->id]);

        $this->assertSame(1, (int) $order->number);
        $this->assertSame(1, $this->counterValue($this->tenantA));
    }
}
