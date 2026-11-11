<?php

namespace Tests\Feature\Orders;

use App\Enums\FulfillmentType;
use App\Exceptions\BelowMinimumOrderException;
use App\Exceptions\FulfillmentException;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O pedido grava o que a loja cobra por retirada/entrega: taxa no total, endereço, bairro, e um
 * snapshot em `meta.fulfillment`. Nada do que o cliente envia define dinheiro: o servidor refaz a
 * conta. Recusas (mínimo, tipo, bairro, endereço) não gravam nada, nem consomem o número do pedido.
 */
class OrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    private Tenant $tenant;

    private Product $product;

    private const CUSTOMER = ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '11999990000'];

    private const ADDRESS = ['line1' => 'Rua das Flores, 120', 'line2' => 'Apto 3', 'city' => 'Curitiba', 'state' => 'PR', 'postal_code' => '80010-000'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderService;
        $this->tenant = Tenant::factory()->create(['settings' => $this->settings()]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 2000]);
    }

    private function settings(array $override = []): array
    {
        return array_replace_recursive([
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
        ], $override);
    }

    private function configure(array $override): void
    {
        $this->tenant->update(['settings' => $this->settings($override)]);
        $this->tenant->refresh();
    }

    private function place(int $qty = 1, array $fulfillment = []): Order
    {
        return $this->service->createFromCart(
            $this->tenant,
            self::CUSTOMER,
            [['product_id' => $this->product->id, 'qty' => $qty]],
            'cash_on_delivery',
            [],
            $fulfillment,
        );
    }

    private function snapshotOfWrites(): array
    {
        return [
            Order::withoutTenantScope()->count(),
            DB::table('customers')->count(),
            DB::table('tenant_counters')->count(),
            (int) DB::table('tenant_counters')->where('tenant_id', $this->tenant->id)->sum('value'),
        ];
    }

    public function test_default_is_pickup_with_no_fee_and_the_total_equals_the_subtotal(): void
    {
        $order = $this->place(2);

        $this->assertSame(FulfillmentType::Pickup, $order->fulfillment_type);
        $this->assertSame(4000, $order->subtotal_cents);
        $this->assertSame(0, $order->delivery_fee_cents);
        $this->assertSame(4000, $order->total_cents);
        $this->assertNull($order->address_line1);
    }

    public function test_pickup_fee_is_added_to_the_total(): void
    {
        $this->configure(['pickup' => ['fee_cents' => 300]]);

        $order = $this->place(1, ['type' => 'pickup']);

        $this->assertSame(300, $order->delivery_fee_cents);
        $this->assertSame(2300, $order->total_cents);
        $this->assertSame('pickup', data_get($order->meta, 'fulfillment.fee_kind'));
    }

    public function test_delivery_stores_the_address_the_region_the_fee_and_the_snapshot(): void
    {
        $order = $this->place(1, ['type' => 'delivery', 'region_id' => 'r-alto', 'address' => self::ADDRESS]);
        $fresh = Order::withoutTenantScope()->findOrFail($order->id);

        $this->assertSame(FulfillmentType::Delivery, $fresh->fulfillment_type);
        $this->assertSame(900, $fresh->delivery_fee_cents);
        $this->assertSame(2900, $fresh->total_cents);
        $this->assertSame('Rua das Flores, 120', $fresh->address_line1);
        $this->assertSame('Apto 3', $fresh->address_line2);
        $this->assertSame('Curitiba', $fresh->address_city);
        $this->assertSame('PR', $fresh->address_state);
        $this->assertSame('80010-000', $fresh->address_postal_code);
        $this->assertEquals(['type' => 'delivery', 'fee_cents' => 900, 'fee_kind' => 'region', 'region_id' => 'r-alto', 'region_name' => 'Bairro Alto', 'minimum_cents' => 0, 'estimated_minutes' => null], data_get($fresh->meta, 'fulfillment'));
    }

    public function test_the_snapshot_survives_the_store_changing_its_prices_later(): void
    {
        $order = $this->place(1, ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS]);

        $this->configure(['delivery' => ['regions' => [['id' => 'r-centro', 'name' => 'Centro (novo nome)', 'fee_cents' => 5000, 'active' => true]]]]);

        $fresh = Order::withoutTenantScope()->findOrFail($order->id);
        $this->assertSame(500, $fresh->delivery_fee_cents);
        $this->assertSame('Centro', data_get($fresh->meta, 'fulfillment.region_name'));
    }

    public function test_free_delivery_threshold_zeroes_the_fee(): void
    {
        $this->configure(['delivery' => ['free_delivery_above_cents' => 4000]]);

        $order = $this->place(2, ['type' => 'delivery', 'region_id' => 'r-alto', 'address' => self::ADDRESS]);

        $this->assertSame(0, $order->delivery_fee_cents);
        $this->assertSame(4000, $order->total_cents);
        $this->assertSame('free', data_get($order->meta, 'fulfillment.fee_kind'));
    }

    public function test_the_flat_fee_applies_when_the_store_has_no_active_regions(): void
    {
        $this->configure(['delivery' => ['regions' => []]]);
        $this->tenant->update(['settings' => array_replace($this->tenant->settings, ['delivery' => array_replace($this->tenant->settings['delivery'], ['regions' => []])])]);

        $order = $this->place(1, ['type' => 'delivery', 'address' => self::ADDRESS]);

        $this->assertSame(700, $order->delivery_fee_cents);
        $this->assertSame('default', data_get($order->meta, 'fulfillment.fee_kind'));
    }

    public function test_client_supplied_money_is_ignored(): void
    {
        $order = $this->place(1, ['type' => 'delivery', 'region_id' => 'r-alto', 'address' => self::ADDRESS, 'fee_cents' => 1, 'total_cents' => 1, 'delivery_fee_cents' => 0]);

        $this->assertSame(900, $order->delivery_fee_cents);
        $this->assertSame(2900, $order->total_cents);
    }

    public function test_below_the_minimum_is_refused_for_every_type_and_nothing_is_written(): void
    {
        $this->configure(['delivery' => ['minimum_order_cents' => 3000]]);

        foreach ([['type' => 'pickup'], ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS]] as $fulfillment) {
            $before = $this->snapshotOfWrites();

            try {
                $this->place(1, $fulfillment);
                $this->fail('abaixo do mínimo não pode virar pedido');
            } catch (BelowMinimumOrderException $e) {
                $this->assertSame(3000, $e->minimumCents);
                $this->assertSame(1000, $e->shortfallCents);
                $this->assertSame('Pedido mínimo de R$ 30,00. Faltam R$ 10,00.', $e->getMessage());
            }

            $this->assertSame($before, $this->snapshotOfWrites(), 'nada gravado, número do pedido intacto');
        }
    }

    public function test_the_fee_never_counts_towards_the_minimum(): void
    {
        $this->configure(['pickup' => ['fee_cents' => 5000], 'delivery' => ['minimum_order_cents' => 2500]]);

        $this->expectException(BelowMinimumOrderException::class);

        $this->place(1, ['type' => 'pickup']);
    }

    public function test_exactly_the_minimum_is_accepted(): void
    {
        $this->configure(['delivery' => ['minimum_order_cents' => 2000]]);

        $this->assertSame(2000, $this->place(1)->total_cents);
    }

    public function test_delivery_without_address_or_with_a_bad_one_is_refused_field_by_field_and_nothing_is_written(): void
    {
        $before = $this->snapshotOfWrites();

        try {
            $this->place(1, ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => ['line1' => '', 'city' => '', 'state' => 'X']]);
            $this->fail('endereço inválido');
        } catch (FulfillmentException $e) {
            $this->assertEqualsCanonicalizing(['address_line1', 'address_city', 'address_state'], array_keys($e->errors));
        }

        $this->assertSame($before, $this->snapshotOfWrites());
    }

    public function test_a_missing_or_inactive_region_is_refused(): void
    {
        foreach ([null, 'nao-existe'] as $region) {
            try {
                $this->place(1, ['type' => 'delivery', 'region_id' => $region, 'address' => self::ADDRESS]);
                $this->fail('bairro inválido');
            } catch (FulfillmentException $e) {
                $this->assertArrayHasKey('region_id', $e->errors);
            }
        }
    }

    public function test_a_type_the_store_turned_off_is_refused(): void
    {
        $this->configure(['delivery' => ['enabled' => false]]);

        $this->expectException(FulfillmentException::class);

        $this->place(1, ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS]);
    }

    public function test_a_refusal_does_not_touch_the_stock(): void
    {
        $this->product->update(['track_inventory' => true, 'stock_quantity' => 5]);
        $this->configure(['delivery' => ['minimum_order_cents' => 10000]]);

        try {
            $this->place(2, ['type' => 'pickup']);
            $this->fail('abaixo do mínimo');
        } catch (BelowMinimumOrderException) {
        }

        $this->assertSame(5, (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity'));
    }

    public function test_insufficient_stock_still_wins_over_a_valid_fulfillment_and_writes_nothing(): void
    {
        $this->product->update(['track_inventory' => true, 'stock_quantity' => 1]);
        $before = $this->snapshotOfWrites();

        $this->expectException(InsufficientStockException::class);

        try {
            $this->place(3, ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS]);
        } finally {
            $this->assertSame($before, $this->snapshotOfWrites());
        }
    }

    public function test_the_order_check_constraint_rejects_an_unknown_fulfillment_type(): void
    {
        $order = $this->place();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('orders')->where('id', $order->id)->update(['fulfillment_type' => 'drone']);
    }
}
