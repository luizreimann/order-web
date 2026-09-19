<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderService();
        $this->tenant = Tenant::factory()->create();
    }

    public function test_create_from_cart_creates_customer_order_and_items(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1000,
        ]);

        $cart = [
            ['product_id' => $product->id, 'qty' => 2],
        ];

        $customerData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '11999999999',
        ];

        $order = $this->service->createFromCart(
            $this->tenant,
            $customerData,
            $cart,
            'cash_on_delivery',
            ['provider' => 'manual']
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals($this->tenant->id, $order->tenant_id);
        $this->assertEquals('cash_on_delivery', $order->payment_method);
        $this->assertEquals(2000, $order->total_cents);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('unpaid', $order->payment_status);

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '11999999999',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price_cents' => 1000,
            'total_cents' => 2000,
        ]);
    }

    public function test_resolve_customer_updates_existing_customer(): void
    {
        $existingCustomer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'phone' => '11999999999',
        ]);

        $customerData = [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'phone' => '11999999999',
        ];

        $customer = $this->service->resolveCustomer($this->tenant, $customerData);

        $this->assertEquals($existingCustomer->id, $customer->id);
        $this->assertEquals('New Name', $customer->name);
        $this->assertEquals('new@example.com', $customer->email);
    }

    public function test_resolve_customer_creates_new_customer_if_not_found(): void
    {
        $customerData = [
            'name' => 'New Customer',
            'email' => 'new@example.com',
            'phone' => '11888888888',
        ];

        $customer = $this->service->resolveCustomer($this->tenant, $customerData);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'New Customer',
            'email' => 'new@example.com',
            'phone' => '11888888888',
        ]);
    }

    public function test_resolve_cart_lines_ignores_nonexistent_products(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1000,
        ]);

        $cart = [
            ['product_id' => $product->id, 'qty' => 1],
            ['product_id' => 'nonexistent-id', 'qty' => 1],
        ];

        $lines = $this->service->resolveCartLines($this->tenant, $cart);

        $this->assertCount(1, $lines);
        $this->assertEquals($product->id, $lines[0]['product']->id);
    }

    public function test_resolve_cart_lines_ignores_zero_quantity(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1000,
        ]);

        $cart = [
            ['product_id' => $product->id, 'qty' => 0],
        ];

        $lines = $this->service->resolveCartLines($this->tenant, $cart);

        $this->assertCount(0, $lines);
    }

    public function test_calculate_subtotal_sums_correctly(): void
    {
        $lines = [
            ['total_cents' => 1000],
            ['total_cents' => 2500],
            ['total_cents' => 500],
        ];

        $subtotal = $this->service->calculateSubtotal($lines);

        $this->assertEquals(4000, $subtotal);
    }

    public function test_calculate_subtotal_returns_zero_for_empty_lines(): void
    {
        $subtotal = $this->service->calculateSubtotal([]);

        $this->assertEquals(0, $subtotal);
    }

    public function test_create_from_cart_with_payment_meta(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1000,
        ]);

        $cart = [
            ['product_id' => $product->id, 'qty' => 1],
        ];

        $customerData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '',
        ];

        $paymentMeta = [
            'provider' => 'mercadopago',
            'payment_id' => '12345',
        ];

        $order = $this->service->createFromCart(
            $this->tenant,
            $customerData,
            $cart,
            'mp_pix',
            $paymentMeta
        );

        $this->assertEquals('mercadopago', $order->meta['payment']['provider']);
        $this->assertEquals('12345', $order->meta['payment']['payment_id']);
    }
}
