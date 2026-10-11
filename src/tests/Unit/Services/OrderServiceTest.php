<?php

namespace Tests\Unit\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderService;
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
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);

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

    public function test_create_from_cart_persists_customer_id_on_order(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1500,
        ]);

        $cart = [
            ['product_id' => $product->id, 'qty' => 1],
        ];

        $customerData = [
            'name' => 'Maria Silva',
            'email' => 'maria@example.com',
            'phone' => '11977777777',
        ];

        $order = $this->service->createFromCart(
            $this->tenant,
            $customerData,
            $cart,
            'cash_on_delivery'
        );

        $customer = Customer::query()
            ->forTenant($this->tenant->id)
            ->where('phone', '11977777777')
            ->first();

        $this->assertNotNull($customer);

        $freshOrder = $order->fresh();

        $this->assertNotNull($freshOrder->customer_id, 'O pedido foi persistido com customer_id nulo.');
        $this->assertEquals($customer->id, $freshOrder->customer_id);

        $this->assertInstanceOf(Customer::class, $freshOrder->customer);
        $this->assertEquals($customer->id, $freshOrder->customer->id);
        $this->assertEquals('Maria Silva', $freshOrder->customer->name);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * E3.2b: antes (`test_resolve_customer_updates_existing_customer`) o checkout
     * SOBRESCREVIA nome/e-mail do cliente encontrado pelo telefone. Como o checkout é
     * público, isso permitia reescrever dados alheios. Variante conservadora: só
     * preenche vazios. Ver também GuestCheckoutCustomerTest.
     */
    public function test_resolve_customer_does_not_overwrite_filled_fields_of_existing_customer(): void
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
        $this->assertEquals('Old Name', $customer->name);
        $this->assertEquals('old@example.com', $customer->email);
        $this->assertDatabaseHas('customers', [
            'id' => $existingCustomer->id,
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);
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

        // A coluna products.id é uuid no PostgreSQL. "Produto inexistente" no
        // mundo real é um UUID BEM FORMADO que não está mais na tabela (ex.:
        // produto excluído depois de entrar no carrinho da sessão), não uma
        // string arbitrária. O carrinho só é populado com ids de models reais
        // (ProductShow::addToCart / Cart::loadCart), então este é o cenário
        // legítimo a cobrir.
        $deletedProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 4200,
        ]);
        $deletedProductId = $deletedProduct->id;
        $deletedProduct->delete();

        $cart = [
            ['product_id' => $product->id, 'qty' => 1],
            ['product_id' => $deletedProductId, 'qty' => 1],
            ['product_id' => (string) Str::uuid(), 'qty' => 1],
        ];

        $lines = $this->service->resolveCartLines($this->tenant, $cart);

        $this->assertCount(1, $lines);
        $this->assertEquals($product->id, $lines[0]['product']->id);
    }

    public function test_resolve_cart_lines_ignores_products_of_another_tenant(): void
    {
        $ownProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1000,
        ]);

        $otherTenant = Tenant::factory()->create();
        $foreignProduct = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'price_cents' => 9999,
        ]);

        $cart = [
            ['product_id' => $ownProduct->id, 'qty' => 1],
            ['product_id' => $foreignProduct->id, 'qty' => 3],
        ];

        $lines = $this->service->resolveCartLines($this->tenant, $cart);

        // O escopo forTenant() precisa impedir que um produto de outro tenant
        // seja precificado neste pedido.
        $this->assertCount(1, $lines);
        $this->assertEquals($ownProduct->id, $lines[0]['product']->id);
        $this->assertEquals(1000, $this->service->calculateSubtotal($lines));
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
