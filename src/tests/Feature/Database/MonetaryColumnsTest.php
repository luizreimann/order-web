<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Database\Support\InteractsWithSchema;
use Tests\TestCase;

/**
 * E2.3 (DB-003) — todas as colunas monetárias em 64 bits.
 *
 * O PostgreSQL não tem `unsigned`: `unsignedBigInteger` vira `bigint`, e a
 * não-negatividade é garantida por CHECK (`>= 0`) nas migrations.
 */
class MonetaryColumnsTest extends TestCase
{
    use InteractsWithSchema, RefreshDatabase;

    /** > 2^31 - 1 (2.147.483.647) e > 2^32: R$ 50 milhões em centavos. */
    private const BIG = 5_000_000_000;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function moneyColumns(): array
    {
        return [
            'orders.subtotal_cents' => ['orders', 'subtotal_cents'],
            'orders.discount_cents' => ['orders', 'discount_cents'],
            'orders.delivery_fee_cents' => ['orders', 'delivery_fee_cents'],
            'orders.total_cents' => ['orders', 'total_cents'],
            'order_items.unit_price_cents' => ['order_items', 'unit_price_cents'],
            'order_items.total_cents' => ['order_items', 'total_cents'],
            'payments.amount_cents' => ['payments', 'amount_cents'],
            'products.price_cents' => ['products', 'price_cents'],
            'products.compare_at_cents' => ['products', 'compare_at_cents'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moneyColumns')]
    public function test_money_column_is_bigint(string $table, string $column): void
    {
        $this->assertSame('bigint', $this->columnType($table, $column), "{$table}.{$column}");
    }

    public function test_order_persists_and_reloads_values_above_2_pow_31(): void
    {
        $tenant = Tenant::factory()->create();

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'subtotal_cents' => self::BIG,
            'discount_cents' => self::BIG + 1,
            'delivery_fee_cents' => self::BIG + 2,
            'total_cents' => self::BIG + 3,
        ]);

        $fresh = Order::query()->findOrFail($order->id);
        $this->assertSame(self::BIG, $fresh->subtotal_cents);
        $this->assertSame(self::BIG + 1, $fresh->discount_cents);
        $this->assertSame(self::BIG + 2, $fresh->delivery_fee_cents);
        $this->assertSame(self::BIG + 3, $fresh->total_cents);
    }

    public function test_order_item_persists_and_reloads_values_above_2_pow_31(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id]);

        $item = OrderItem::query()->create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'name' => 'Banquete',
            'quantity' => 1, 'unit_price_cents' => self::BIG, 'total_cents' => self::BIG,
        ]);

        $fresh = OrderItem::query()->findOrFail($item->id);
        $this->assertSame(self::BIG, $fresh->unit_price_cents);
        $this->assertSame(self::BIG, $fresh->total_cents);
    }

    public function test_payment_persists_and_reloads_values_above_2_pow_31(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'amount_cents' => self::BIG,
        ]);

        $this->assertSame(self::BIG, Payment::query()->findOrFail($payment->id)->amount_cents);
    }

    public function test_product_persists_and_reloads_values_above_2_pow_31(): void
    {
        $tenant = Tenant::factory()->create();

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id, 'price_cents' => self::BIG, 'compare_at_cents' => self::BIG + 1,
        ]);

        $fresh = Product::query()->findOrFail($product->id);
        $this->assertSame(self::BIG, $fresh->price_cents);
        $this->assertSame(self::BIG + 1, $fresh->compare_at_cents);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moneyColumns')]
    public function test_money_column_rejects_negative_values(string $table, string $column): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertSqlState(self::SQLSTATE_CHECK_VIOLATION, function () use ($table, $column, $tenant, $order) {
            match ($table) {
                'orders' => $order->newQuery()->whereKey($order->id)->update([$column => -1]),
                'order_items' => OrderItem::query()->create([
                    'tenant_id' => $tenant->id, 'order_id' => $order->id, 'name' => 'x', $column => -1,
                ]),
                'payments' => Payment::factory()->create([
                    'tenant_id' => $tenant->id, 'order_id' => $order->id, $column => -1,
                ]),
                'products' => Product::factory()->create(['tenant_id' => $tenant->id, $column => -1]),
                default => $this->fail("tabela inesperada: {$table}"),
            };
        }, "{$table}.{$column} aceitou valor negativo");
    }
}
