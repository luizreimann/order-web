<?php

namespace Tests\Feature\Scheduling;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\SchedulingException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vaga do dia. Relógio em 2026-11-09 15:00 UTC, segunda-feira 12:00 em
 * America/Sao_Paulo. Com antecedência 0, a primeira candidata é a terça
 * 2026-11-10. A terça seguinte é 2026-11-17. A opção cadastrada é 10:00.
 */
class SchedulingCapacityTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    private SchedulingService $scheduling;

    private Tenant $tenant;

    private Product $product;

    private const CUSTOMER = ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '11999990000'];

    private const ADDRESS = ['line1' => 'Rua das Flores, 120', 'line2' => 'Apto 3', 'city' => 'Curitiba', 'state' => 'PR', 'postal_code' => '80010-000'];

    private const STOCK = 40;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        $this->orders = new OrderService;
        $this->scheduling = new SchedulingService;
        $this->tenant = Tenant::factory()->create(['settings' => $this->fulfillmentSettings()]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 2000,
            'track_inventory' => true,
            'stock_quantity' => self::STOCK,
            'min_days_notice' => 0,
        ]);
    }

    public function test_null_capacity_allows_three_orders_on_the_same_day(): void
    {
        $this->configure($this->schedulingBlock(null));

        for ($i = 0; $i < 3; $i++) {
            $order = $this->place(['type' => 'pickup'], $this->schedule('2026-11-10'));
            $this->assertSame('2026-11-10', $order->scheduled_on?->toDateString());
        }

        $this->assertSame(3, Order::withoutTenantScope()->count());
        $this->assertSame(
            ['state' => 'open', 'reason' => null],
            $this->scheduling->calendar($this->tenant)['days']['2026-11-10'],
        );
    }

    public function test_capacity_zero_disables_the_day_as_full_and_refuses_the_order(): void
    {
        $this->configure($this->schedulingBlock(0));

        $this->assertSame(
            ['state' => 'disabled', 'reason' => 'full'],
            $this->scheduling->calendar($this->tenant)['days']['2026-11-10'],
        );
        $this->assertSame(
            ['state' => 'disabled', 'reason' => 'full'],
            $this->scheduling->calendar($this->tenant)['days']['2026-11-17'],
        );

        $this->assertRefused(['type' => 'pickup'], $this->schedule('2026-11-10'), 'scheduled_on');
        $this->assertSame(0, Order::withoutTenantScope()->count());
    }

    public function test_capacity_one_is_per_date_shared_by_pickup_and_delivery_and_ignores_item_quantity(): void
    {
        $this->configure($this->schedulingBlock(1));

        $pickup = $this->place(['type' => 'pickup'], $this->schedule('2026-11-10'));

        $this->assertRefused(
            ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS],
            $this->schedule('2026-11-10'),
            'scheduled_on',
        );

        $days = $this->scheduling->calendar($this->tenant)['days'];
        $this->assertSame(['state' => 'disabled', 'reason' => 'full'], $days['2026-11-10']);
        $this->assertSame(['state' => 'open', 'reason' => null], $days['2026-11-17']);

        $bulk = $this->place(['type' => 'pickup'], $this->schedule('2026-11-17'), 10);

        $this->assertRefused(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS], $this->schedule('2026-11-17'), 'scheduled_on');

        $this->assertSame('2026-11-10', $pickup->scheduled_on?->toDateString());
        $this->assertSame('2026-11-17', $bulk->scheduled_on?->toDateString());
        $this->assertSame(10, (int) DB::table('order_items')->where('order_id', $bulk->id)->sum('quantity'));
        $this->assertSame(2, Order::withoutTenantScope()->count());
        $this->assertSame(
            ['state' => 'disabled', 'reason' => 'full'],
            $this->scheduling->calendar($this->tenant)['days']['2026-11-17'],
        );
    }

    public function test_cancelling_frees_the_slot(): void
    {
        $this->configure($this->schedulingBlock(1));
        $order = $this->place(['type' => 'pickup'], $this->schedule('2026-11-10'));

        $this->orders->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, 'cash_on_delivery');

        $next = $this->place(
            ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS],
            $this->schedule('2026-11-10'),
        );

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()?->status);
        $this->assertSame(OrderStatus::PENDING, $next->fresh()?->status);
        $this->assertSame('2026-11-10', $next->scheduled_on?->toDateString());
        $this->assertSame(2, Order::withoutTenantScope()->count());
    }

    public function test_reopening_without_a_slot_fails_and_the_order_stays_cancelled(): void
    {
        $this->configure($this->schedulingBlock(1));
        $first = $this->place(['type' => 'pickup'], $this->schedule('2026-11-10'));
        $this->orders->updateOrder($first, OrderStatus::CANCELLED, PaymentStatus::UNPAID, 'cash_on_delivery');
        $second = $this->place(['type' => 'pickup'], $this->schedule('2026-11-10'));

        try {
            $this->orders->updateOrder($first->fresh() ?? $first, OrderStatus::PENDING, PaymentStatus::UNPAID, 'cash_on_delivery');
            $this->fail('reabrir sem vaga não pode gravar');
        } catch (SchedulingException $e) {
            $this->assertSame(['status'], array_keys($e->errors));
            $this->assertSame('Não há vaga neste dia.', $e->errors['status']);
            $this->assertSame($e->errors['status'], $e->getMessage());
        }

        $this->assertSame(OrderStatus::CANCELLED, Order::withoutTenantScope()->findOrFail($first->id)->status);
        $this->assertSame(OrderStatus::PENDING, Order::withoutTenantScope()->findOrFail($second->id)->status);
    }

    public function test_lowering_capacity_does_not_delete_orders_and_refuses_a_new_one(): void
    {
        $this->configure($this->schedulingBlock(null));
        $first = $this->place(['type' => 'pickup'], $this->schedule('2026-11-10'));
        $second = $this->place(
            ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS],
            $this->schedule('2026-11-10'),
        );

        $this->configure($this->schedulingBlock(1));

        $this->assertRefused(['type' => 'pickup'], $this->schedule('2026-11-10'), 'scheduled_on');

        $stored = Order::withoutTenantScope()->orderBy('number')->get();

        $this->assertCount(2, $stored);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $stored->pluck('id')->all());
        $this->assertTrue($stored->every(fn (Order $order) => $order->status === OrderStatus::PENDING));
    }

    public function test_an_off_day_wins_over_full(): void
    {
        $this->configure($this->schedulingBlock(0, ['2026-11-10']));

        $days = $this->scheduling->calendar($this->tenant)['days'];

        $this->assertSame(['state' => 'disabled', 'reason' => 'off'], $days['2026-11-10']);
        $this->assertNotSame('full', $days['2026-11-10']['reason']);
        $this->assertSame(['state' => 'disabled', 'reason' => 'full'], $days['2026-11-17']);
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     * @param  array<string, mixed>  $schedule
     */
    private function assertRefused(array $fulfillment, array $schedule, string $field, int $qty = 1): void
    {
        $ordersBefore = Order::withoutTenantScope()->count();
        $stockBefore = $this->stock();

        try {
            $this->place($fulfillment, $schedule, $qty);
            $this->fail('o pedido não pode nascer');
        } catch (SchedulingException $e) {
            $this->assertSame([$field], array_keys($e->errors));
            $this->assertSame('Não há vaga neste dia.', $e->errors[$field]);
            $this->assertSame($e->errors[$field], $e->getMessage());
        }

        $this->assertSame($ordersBefore, Order::withoutTenantScope()->count());
        $this->assertSame($stockBefore, $this->stock());
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     * @param  array<string, mixed>  $schedule
     */
    private function place(array $fulfillment, array $schedule, int $qty = 1): Order
    {
        return $this->orders->createFromCart(
            $this->tenant,
            self::CUSTOMER,
            [['product_id' => $this->product->id, 'qty' => $qty]],
            'cash_on_delivery',
            [],
            $fulfillment,
            $schedule,
        );
    }

    /**
     * @return array{date: string, option: array{kind: string, start: string, end: null}}
     */
    private function schedule(string $date): array
    {
        return [
            'date' => $date,
            'option' => ['kind' => 'time', 'start' => '10:00', 'end' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fulfillmentSettings(): array
    {
        return [
            'pickup' => ['enabled' => true, 'fee_cents' => 0],
            'delivery' => [
                'enabled' => true,
                'minimum_order_cents' => 0,
                'default_fee_cents' => 700,
                'free_delivery_above_cents' => 0,
                'regions' => [
                    ['id' => 'r-centro', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
                    ['id' => 'r-alto', 'name' => 'Bairro Alto', 'fee_cents' => 900, 'active' => true],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $offDays
     * @return array<string, mixed>
     */
    private function schedulingBlock(?int $capacity, array $offDays = []): array
    {
        $days = [];

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $rule = [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                ],
            ];

            if ($day === 'tuesday') {
                $rule['capacity'] = $capacity;
            }

            $days[$day] = $rule;
        }

        return [
            'enabled' => true,
            'min_days' => 0,
            'max_days' => 30,
            'weekdays' => $days,
            'off_days' => $offDays,
        ];
    }

    /**
     * @param  array<string, mixed>  $scheduling
     */
    private function configure(array $scheduling): void
    {
        $settings = $this->fulfillmentSettings();
        $settings['scheduling'] = $scheduling;

        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity');
    }
}
