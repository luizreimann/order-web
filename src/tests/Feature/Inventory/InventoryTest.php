<?php

namespace Tests\Feature\Inventory;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\InsufficientStockException;
use App\Livewire\Tenant\Cart;
use App\Livewire\Tenant\Checkout;
use App\Livewire\Tenant\ProductShow;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantCounter;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E2.7 (D1) — estoque opt-in por produto.
 */
class InventoryTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenant;

    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'loja', 'is_active' => true]);
        $this->service = new OrderService;
    }

    private function tracked(int $stock, array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1000,
            'track_inventory' => true,
            'stock_quantity' => $stock,
        ], $attrs));
    }

    private function untracked(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 500,
        ], $attrs));
    }

    private function order(array $cart): Order
    {
        return $this->service->createFromCart(
            $this->tenant,
            ['name' => 'Cliente', 'email' => 'c@example.com', 'phone' => '11999990000'],
            $cart,
            'cash_on_delivery'
        );
    }

    private function stock(Product $p): ?int
    {
        return DB::table('products')->where('id', $p->id)->value('stock_quantity');
    }

    // ---------------------------------------------------------------- schema

    public function test_new_columns_default_to_opt_out(): void
    {
        $p = $this->untracked();

        $this->assertFalse($p->fresh()->track_inventory);
        $this->assertNull($p->fresh()->stock_quantity);
    }

    public function test_database_rejects_negative_stock(): void
    {
        $p = $this->tracked(1);

        try {
            DB::transaction(fn () => DB::table('products')->where('id', $p->id)->update(['stock_quantity' => -1]));
            $this->fail('CHECK deveria rejeitar estoque negativo.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('products_stock_quantity_nonnegative', $e->getMessage());
        }
    }

    public function test_database_rejects_tracked_product_without_quantity(): void
    {
        $p = $this->untracked();

        try {
            DB::transaction(fn () => DB::table('products')->where('id', $p->id)->update(['track_inventory' => true]));
            $this->fail('CHECK deveria exigir stock_quantity quando track_inventory = true.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('products_tracked_requires_quantity', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- OrderService

    public function test_untracked_product_is_not_validated_nor_decremented(): void
    {
        $p = $this->untracked();
        $withLeftover = $this->untracked(['stock_quantity' => 3]); // flag off: quantidade é ignorada

        $order = $this->order([
            ['product_id' => $p->id, 'qty' => 9999],
            ['product_id' => $withLeftover->id, 'qty' => 10],
        ]);

        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame(2, $order->items()->count());
        $this->assertNull($this->stock($p));
        $this->assertSame(3, $this->stock($withLeftover));
        $this->assertSame(0, (int) $order->items()->first()->stock_deducted_quantity);
    }

    public function test_tracked_product_with_enough_stock_is_decremented_in_the_same_order(): void
    {
        $p = $this->tracked(5);

        $order = $this->order([['product_id' => $p->id, 'qty' => 2]]);

        $this->assertSame(3, $this->stock($p));
        $this->assertSame(2, (int) $order->items()->firstOrFail()->stock_deducted_quantity);
    }

    public function test_selling_exactly_the_remaining_stock_is_allowed(): void
    {
        $p = $this->tracked(2);

        $this->order([['product_id' => $p->id, 'qty' => 2]]);

        $this->assertSame(0, $this->stock($p));
    }

    public function test_insufficient_stock_throws_domain_exception_and_persists_nothing(): void
    {
        $ok = $this->tracked(10, ['name' => 'Disponível']);
        $short = $this->tracked(1, ['name' => 'Último bolo']);

        // Um pedido anterior consome o número 1: o contador existe.
        $this->order([['product_id' => $ok->id, 'qty' => 1]]);
        $this->assertSame(1, (int) TenantCounter::queryFor($this->tenant->id, 'orders')->value('value'));
        $ordersBefore = Order::query()->count();
        $itemsBefore = OrderItem::query()->count();
        $customersBefore = Customer::query()->count();

        try {
            $this->service->createFromCart(
                $this->tenant,
                ['name' => 'Outro', 'email' => 'outro@example.com', 'phone' => '11888880000'],
                [
                    ['product_id' => $ok->id, 'qty' => 3],     // válido, mas NÃO pode ser decrementado
                    ['product_id' => $short->id, 'qty' => 2],  // só há 1
                ],
                'cash_on_delivery'
            );
            $this->fail('InsufficientStockException esperada.');
        } catch (InsufficientStockException $e) {
            $this->assertSame($short->id, $e->productId);
            $this->assertSame('Último bolo', $e->productName);
            $this->assertSame(2, $e->requested);
            $this->assertSame(1, $e->available);
            $this->assertStringContainsString('Último bolo', $e->getMessage());
        }

        // Rollback total.
        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertSame($itemsBefore, OrderItem::query()->count());
        $this->assertSame($customersBefore, Customer::query()->count());
        $this->assertSame(9, $this->stock($ok));
        $this->assertSame(1, $this->stock($short));

        // O número do pedido NÃO é consumido: contador intacto e o próximo pedido é o #2.
        $this->assertSame(1, (int) TenantCounter::queryFor($this->tenant->id, 'orders')->value('value'));
        $next = $this->order([['product_id' => $ok->id, 'qty' => 1]]);
        $this->assertSame(2, (int) $next->number);
    }

    public function test_out_of_stock_product_cannot_be_ordered(): void
    {
        $p = $this->tracked(0);

        try {
            $this->order([['product_id' => $p->id, 'qty' => 1]]);
            $this->fail('InsufficientStockException esperada.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(0, $e->available);
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_duplicate_lines_of_the_same_product_are_summed_before_validating(): void
    {
        $p = $this->tracked(3);

        try {
            $this->order([
                ['product_id' => $p->id, 'qty' => 2],
                ['product_id' => $p->id, 'qty' => 2],
            ]);
            $this->fail('2 + 2 > 3: deveria falhar mesmo que cada linha caiba isoladamente.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(4, $e->requested);
            $this->assertSame(3, $e->available);
        }

        $this->assertSame(3, $this->stock($p));

        $order = $this->order([
            ['product_id' => $p->id, 'qty' => 2],
            ['product_id' => $p->id, 'qty' => 1],
        ]);

        $this->assertSame(0, $this->stock($p));
        $this->assertSame(3, (int) $order->items()->sum('stock_deducted_quantity'));
    }

    public function test_locks_are_taken_in_stable_id_order(): void
    {
        $a = $this->tracked(5);
        $b = $this->tracked(5);
        [$low, $high] = strcmp($a->id, $b->id) < 0 ? [$a, $b] : [$b, $a];

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (stripos($q->sql, 'for update') !== false && str_contains($q->sql, '"products"')) {
                $queries[] = $q;
            }
        });

        // Carrinho na ordem inversa: o lock tem de ser ordenado por id mesmo assim.
        $this->order([
            ['product_id' => $high->id, 'qty' => 1],
            ['product_id' => $low->id, 'qty' => 1],
        ]);

        $this->assertCount(1, $queries, 'um único SELECT ... FOR UPDATE, ordenado por id');
        $this->assertMatchesRegularExpression('/order by "id" asc/i', $queries[0]->sql);
        $this->assertStringContainsString('for update', strtolower($queries[0]->sql));
    }

    // ---------------------------------------------------------------- cancelamento

    public function test_cancelling_returns_stock_once_and_is_idempotent(): void
    {
        $p = $this->tracked(5);
        $order = $this->order([['product_id' => $p->id, 'qty' => 2]]);
        $this->assertSame(3, $this->stock($p));

        $this->service->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);
        $this->assertSame(5, $this->stock($p));
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);

        // Cancelar de novo (mesmo objeto, objeto novo, ou salvando "cancelado" outra vez) não devolve de novo.
        $this->service->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);
        $this->service->updateOrder($order->fresh(), OrderStatus::CANCELLED, PaymentStatus::REFUNDED, null);

        $this->assertSame(5, $this->stock($p));
        $this->assertSame(PaymentStatus::REFUNDED, $order->fresh()->payment_status);
    }

    public function test_cancelling_a_stale_copy_of_an_already_cancelled_order_does_not_double_return(): void
    {
        $p = $this->tracked(5);
        $order = $this->order([['product_id' => $p->id, 'qty' => 2]]);

        $staleA = Order::query()->findOrFail($order->id);
        $staleB = Order::query()->findOrFail($order->id); // dois admins com a tela aberta

        $this->service->updateOrder($staleA, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);
        $this->service->updateOrder($staleB, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);

        $this->assertSame(5, $this->stock($p));
    }

    public function test_cancelling_does_not_touch_untracked_products(): void
    {
        $p = $this->untracked();
        $order = $this->order([['product_id' => $p->id, 'qty' => 4]]);

        $this->service->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);

        $this->assertNull($this->stock($p));
    }

    public function test_non_cancel_transitions_do_not_touch_stock(): void
    {
        $p = $this->tracked(5);
        $order = $this->order([['product_id' => $p->id, 'qty' => 2]]);

        $this->service->updateOrder($order, OrderStatus::CONFIRMED, PaymentStatus::PAID, 'pix');
        $this->service->updateOrder($order, OrderStatus::DONE, PaymentStatus::PAID, 'pix');

        $this->assertSame(3, $this->stock($p));
        $this->assertSame('pix', $order->fresh()->payment_method);
    }

    public function test_reactivating_a_cancelled_order_takes_the_stock_back_and_cancelling_again_returns_it_once(): void
    {
        $p = $this->tracked(5);
        $order = $this->order([['product_id' => $p->id, 'qty' => 2]]);

        $this->service->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);
        $this->assertSame(5, $this->stock($p));

        $this->service->updateOrder($order, OrderStatus::CONFIRMED, PaymentStatus::UNPAID, null);
        $this->assertSame(3, $this->stock($p));

        $this->service->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);
        $this->assertSame(5, $this->stock($p));
    }

    public function test_reactivating_without_enough_stock_fails_and_changes_nothing(): void
    {
        $p = $this->tracked(2);
        $order = $this->order([['product_id' => $p->id, 'qty' => 2]]);
        $this->service->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, null);

        // Outro cliente leva o estoque devolvido.
        $this->order([['product_id' => $p->id, 'qty' => 2]]);
        $this->assertSame(0, $this->stock($p));

        try {
            $this->service->updateOrder($order, OrderStatus::CONFIRMED, PaymentStatus::UNPAID, null);
            $this->fail('InsufficientStockException esperada ao reativar.');
        } catch (InsufficientStockException) {
            // esperado
        }

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $this->stock($p));
    }

    /**
     * Admin usa o ENDPOINT REAL do Livewire (middleware + policies), como em
     * AdminActionsAuthorizationTest — `Livewire::test()` não exercita o stack de admin.
     *
     * @param  array<string, mixed>  $updates
     * @return array{0: \Illuminate\Testing\TestResponse, 1: array<string, mixed>} resposta e erros de validação
     */
    private function adminCall(string $url, string $component, string $method, array $updates): array
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');

        $snapshot = $this->snapshotFor($this->get($url)->assertOk()->getContent(), $component);
        $response = $this->callLivewire($snapshot, $method, [], $updates)->assertOk();

        $memo = json_decode((string) $response->json('components.0.snapshot'), true)['memo'] ?? [];

        return [$response, (array) $this->unwrap($memo['errors'] ?? [])];
    }

    public function test_admin_cancel_returns_stock_and_a_second_save_does_not(): void
    {
        $p = $this->tracked(5);
        $order = $this->order([['product_id' => $p->id, 'qty' => 2]]);
        $url = "/loja/admin/pedidos/{$order->id}";

        [, $errors] = $this->adminCall($url, 'admin.orders.show', 'updateStatus', ['status' => 'cancelled']);
        $this->assertSame([], $errors);
        $this->assertSame(5, $this->stock($p));
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);

        // Segunda tela (snapshot já "cancelado") salvando de novo: estoque inalterado.
        $this->adminCall($url, 'admin.orders.show', 'updateStatus', ['status' => 'cancelled']);
        $this->assertSame(5, $this->stock($p));
    }

    public function test_admin_order_form_rejects_invalid_status_values(): void
    {
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id]);
        $url = "/loja/admin/pedidos/{$order->id}";

        [, $errors] = $this->adminCall($url, 'admin.orders.show', 'updateStatus', ['status' => 'enviado']);
        $this->assertArrayHasKey('status', $errors);

        [, $errors] = $this->adminCall($url, 'admin.orders.show', 'updateStatus', ['payment_status' => 'parcial']);
        $this->assertArrayHasKey('payment_status', $errors);

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
    }

    public function test_admin_form_saves_inventory_fields(): void
    {
        [, $errors] = $this->adminCall('/loja/admin/produtos/novo', 'admin.products.form', 'save', [
            'name' => 'Ração Premium', 'price' => '99,90', 'track_inventory' => true, 'stock_quantity' => 12,
        ]);

        $this->assertSame([], $errors);
        $p = Product::query()->where('name', 'Ração Premium')->firstOrFail();
        $this->assertTrue($p->track_inventory);
        $this->assertSame(12, $p->stock_quantity);
    }

    public function test_admin_form_requires_valid_quantity_when_tracking_and_clears_it_when_off(): void
    {
        [, $errors] = $this->adminCall('/loja/admin/produtos/novo', 'admin.products.form', 'save', [
            'name' => 'Ração', 'price' => '10,00', 'track_inventory' => true, 'stock_quantity' => null,
        ]);
        $this->assertArrayHasKey('stock_quantity', $errors);

        [, $errors] = $this->adminCall('/loja/admin/produtos/novo', 'admin.products.form', 'save', [
            'name' => 'Ração', 'price' => '10,00', 'track_inventory' => true, 'stock_quantity' => -3,
        ]);
        $this->assertArrayHasKey('stock_quantity', $errors);
        $this->assertSame(0, Product::query()->where('name', 'Ração')->count());

        // Desligado: a quantidade digitada é descartada (o banco não guarda lixo).
        [, $errors] = $this->adminCall('/loja/admin/produtos/novo', 'admin.products.form', 'save', [
            'name' => 'Bolo sob encomenda', 'price' => '10,00', 'track_inventory' => false, 'stock_quantity' => 7,
        ]);
        $this->assertSame([], $errors);

        $p = Product::query()->where('name', 'Bolo sob encomenda')->firstOrFail();
        $this->assertFalse($p->track_inventory);
        $this->assertNull($p->stock_quantity);
    }

    // ---------------------------------------------------------------- pontos de chamada

    public function test_checkout_shows_friendly_error_and_creates_nothing_when_stock_is_short(): void
    {
        $p = $this->tracked(1, ['name' => 'Torta']);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $p->id, 'qty' => 1]]);

        $component = Livewire::test(Checkout::class, ['tenant' => $this->tenant]);

        // Outro cliente compra o último item enquanto este está no checkout.
        $this->order([['product_id' => $p->id, 'qty' => 1]]);
        $ordersBefore = Order::query()->count();

        $component
            ->set('customer_name', 'Maria')
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', 'cash_on_delivery')
            ->call('placeOrderManual')
            ->assertHasErrors(['cart'])
            ->assertNoRedirect();

        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertNotEmpty(session()->get('cart.'.$this->tenant->id), 'carrinho preservado');
    }

    public function test_process_payment_returns_422_not_500_when_stock_is_short(): void
    {
        $tenant = Tenant::factory()->withMercadoPago()->create(['slug' => 'mp-loja', 'is_active' => true]);
        $settings = $tenant->settings;
        $settings['payment']['methods'] = [['id' => 'mp_pix', 'enabled' => true]];
        $tenant->update(['settings' => $settings]);

        $p = Product::factory()->create([
            'tenant_id' => $tenant->id, 'price_cents' => 1000, 'track_inventory' => true, 'stock_quantity' => 0, 'name' => 'Esgotadinho',
        ]);

        $response = $this
            ->withSession(['cart.'.$tenant->id => [['product_id' => $p->id, 'qty' => 1]]])
            ->postJson('/mp-loja/process_payment', [
                'mp_method' => 'mp_pix',
                'formData' => [],
                'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '11999990000'],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Esgotadinho', (string) $response->json('message'));
        $this->assertSame(0, Order::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
    }

    // ---------------------------------------------------------------- storefront

    public function test_product_page_shows_sold_out_and_blocks_adding_to_cart(): void
    {
        $p = $this->tracked(0, ['slug' => 'esgotado', 'name' => 'Brigadeiro Gourmet']);

        Livewire::test(ProductShow::class, ['tenant' => $this->tenant, 'productSlug' => 'esgotado'])
            ->assertSee('Esgotado')
            ->call('addToCart')
            ->assertHasErrors(['qty'])
            ->assertNoRedirect();

        $this->assertEmpty(session()->get('cart.'.$this->tenant->id, []));
    }

    public function test_product_page_blocks_quantity_above_available_including_what_is_already_in_cart(): void
    {
        $p = $this->tracked(3, ['slug' => 'poucos']);

        $component = Livewire::test(ProductShow::class, ['tenant' => $this->tenant, 'productSlug' => 'poucos']);

        $component->set('qty', 4)->call('addToCart')->assertHasErrors(['qty']);
        $this->assertEmpty(session()->get('cart.'.$this->tenant->id, []));

        $component->set('qty', 2)->call('addToCart')->assertHasNoErrors();
        $this->assertSame(2, session()->get('cart.'.$this->tenant->id)[0]['qty']);

        // 2 no carrinho + 2 > 3
        $component->set('qty', 2)->call('addToCart')->assertHasErrors(['qty']);
        $this->assertSame(2, session()->get('cart.'.$this->tenant->id)[0]['qty']);
    }

    public function test_untracked_product_page_has_no_limit(): void
    {
        $p = $this->untracked(['slug' => 'encomenda']);

        Livewire::test(ProductShow::class, ['tenant' => $this->tenant, 'productSlug' => 'encomenda'])
            ->assertDontSee('Esgotado')
            ->set('qty', 500)
            ->call('addToCart')
            ->assertHasNoErrors();

        $this->assertSame(500, session()->get('cart.'.$this->tenant->id)[0]['qty']);
    }

    public function test_cart_increment_stops_at_available_stock(): void
    {
        $p = $this->tracked(2);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $p->id, 'qty' => 2]]);

        Livewire::test(Cart::class, ['tenant' => $this->tenant])
            ->call('increment', $p->id);

        $this->assertSame(2, session()->get('cart.'.$this->tenant->id)[0]['qty']);
    }
}
