<?php

namespace Tests\Feature\Cart;

use App\Livewire\Tenant\Cart;
use App\Livewire\Tenant\Checkout;
use App\Livewire\Tenant\ProductShow;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E3.1 (ARC-008) — `Cart`, `Checkout`, `ProductShow` e `OrderService` leem o carrinho
 * pela MESMA fonte. Aqui a prova é de comportamento: mesmo carrinho, mesmo total,
 * mesmas linhas excluídas — em cada consumidor.
 */
class CartUnificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'loja', 'is_active' => true]);
    }

    private function product(array $attrs = [], ?Tenant $tenant = null): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'price_cents' => 1000,
        ], $attrs));
    }

    private function putCart(array $lines, ?Tenant $tenant = null): void
    {
        session()->put('cart.'.($tenant ?? $this->tenant)->id, $lines);
    }

    public function test_cart_and_checkout_show_the_same_total_for_the_same_cart(): void
    {
        $a = $this->product(['name' => 'Bolo', 'price_cents' => 1000]);
        $b = $this->product(['name' => 'Torta', 'price_cents' => 1500]);
        $this->putCart([
            ['product_id' => $a->id, 'qty' => 2],
            ['product_id' => $b->id, 'qty' => 1],
        ]);

        // 2×10,00 + 1×15,00
        Livewire::test(Cart::class, ['tenant' => $this->tenant])->assertSee('R$ 35,00');
        Livewire::test(Checkout::class, ['tenant' => $this->tenant])->assertSee('R$ 35,00');
    }

    public function test_cart_and_checkout_agree_after_the_cart_changes(): void
    {
        $a = $this->product(['price_cents' => 1000]);
        $this->putCart([['product_id' => $a->id, 'qty' => 1]]);

        Livewire::test(Cart::class, ['tenant' => $this->tenant])
            ->call('increment', $a->id)
            ->call('increment', $a->id)
            ->assertSee('R$ 30,00');

        Livewire::test(Checkout::class, ['tenant' => $this->tenant])->assertSee('R$ 30,00');
    }

    public function test_inactive_deleted_and_foreign_products_never_enter_the_totals(): void
    {
        $ok = $this->product(['name' => 'Valido', 'price_cents' => 1000]);
        $inactive = $this->product(['name' => 'Produto Inativo', 'price_cents' => 7000, 'is_active' => false]);
        $deleted = $this->product(['name' => 'Produto Excluido', 'price_cents' => 7000]);
        $deleted->delete();
        $other = Tenant::factory()->create();
        $foreign = $this->product(['name' => 'Produto Alheio', 'price_cents' => 7000], $other);

        $this->putCart([
            ['product_id' => $ok->id, 'qty' => 1],
            ['product_id' => $inactive->id, 'qty' => 1],
            ['product_id' => $deleted->id, 'qty' => 1],
            ['product_id' => $foreign->id, 'qty' => 1],
        ]);

        foreach ([Cart::class, Checkout::class] as $component) {
            Livewire::test($component, ['tenant' => $this->tenant])
                ->assertSee('Valido')
                ->assertSee('R$ 10,00')
                ->assertDontSee('Produto Inativo')
                ->assertDontSee('Produto Excluido')
                ->assertDontSee('Produto Alheio')
                ->assertDontSee('R$ 70,00')
                ->assertDontSee('R$ 80,00');
        }
    }

    public function test_order_subtotal_matches_what_cart_and_checkout_displayed(): void
    {
        $a = $this->product(['price_cents' => 1250]);
        $inactive = $this->product(['price_cents' => 9999, 'is_active' => false]);
        $cart = [
            ['product_id' => $a->id, 'qty' => 3],
            ['product_id' => $inactive->id, 'qty' => 1],
        ];
        $this->putCart($cart);

        Livewire::test(Cart::class, ['tenant' => $this->tenant])->assertSee('R$ 37,50');
        Livewire::test(Checkout::class, ['tenant' => $this->tenant])->assertSee('R$ 37,50');

        $order = app(OrderService::class)->createFromCart(
            $this->tenant,
            ['name' => 'Maria', 'email' => 'm@example.com', 'phone' => '11999990000'],
            $cart,
            'cash_on_delivery'
        );

        $this->assertSame(3750, $order->subtotal_cents);
        $this->assertSame(3750, $order->total_cents);
        $this->assertSame(1, $order->items()->count(), 'produto inativo não vira item do pedido');
    }

    public function test_checkout_total_comes_from_the_server_never_from_the_client(): void
    {
        $a = $this->product(['price_cents' => 1000]);
        $this->putCart([['product_id' => $a->id, 'qty' => 1]]);

        // O cliente Livewire pode tentar forçar estado público; o total não é estado público.
        Livewire::test(Checkout::class, ['tenant' => $this->tenant])
            ->set('customer_name', 'Maria')
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', 'cash_on_delivery')
            ->call('placeOrderManual');

        $order = Order::withoutTenantScope()->firstOrFail();
        $this->assertSame(1000, $order->total_cents);
    }

    public function test_checkout_refuses_a_cart_whose_only_products_became_inactive(): void
    {
        $a = $this->product(['price_cents' => 1000]);
        $this->putCart([['product_id' => $a->id, 'qty' => 1]]);
        $a->update(['is_active' => false]);

        Livewire::test(Checkout::class, ['tenant' => $this->tenant])
            ->set('customer_name', 'Maria')
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', 'cash_on_delivery')
            ->call('placeOrderManual')
            ->assertHasErrors(['payment_method']);

        $this->assertSame(0, Order::withoutTenantScope()->count());
    }

    public function test_product_page_refuses_to_add_a_product_deactivated_after_the_page_loaded(): void
    {
        $a = $this->product(['slug' => 'bolo']);

        $component = Livewire::test(ProductShow::class, ['tenant' => $this->tenant, 'productSlug' => 'bolo']);
        $a->update(['is_active' => false]);

        // Em produção é um 404; o Livewire::test() propaga a exceção.
        try {
            $component->call('addToCart');
            $this->fail('deveria recusar produto inativo');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // esperado
        }

        $this->assertEmpty(session()->get('cart.'.$this->tenant->id, []));
    }

    public function test_cart_page_only_changes_the_session_cart_of_its_own_tenant(): void
    {
        $other = Tenant::factory()->create();
        $mine = $this->product();
        $theirs = $this->product([], $other);
        $this->putCart([['product_id' => $mine->id, 'qty' => 1]]);
        $this->putCart([['product_id' => $theirs->id, 'qty' => 1]], $other);

        Livewire::test(Cart::class, ['tenant' => $this->tenant])->call('clear');

        $this->assertEmpty(session()->get('cart.'.$this->tenant->id));
        $this->assertCount(1, session()->get('cart.'.$other->id));
    }

    public function test_header_badge_counts_only_valid_lines(): void
    {
        $ok = $this->product();
        $inactive = $this->product(['is_active' => false]);
        $foreign = $this->product([], Tenant::factory()->create());

        $badge = '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">';

        $response = $this->withSession(['cart.'.$this->tenant->id => [
            ['product_id' => $ok->id, 'qty' => 5],
            ['product_id' => $inactive->id, 'qty' => 1],
            ['product_id' => $foreign->id, 'qty' => 1],
        ]])->get('/loja/carrinho')->assertOk();

        // 1 linha válida (badge conta linhas, não unidades); antes contava 3 (o bruto da sessão).
        $this->assertMatchesRegularExpression('/'.preg_quote($badge, '/').'\s*1\s*<\/span>/', $response->getContent());
    }

    public function test_header_has_no_badge_when_every_line_is_invalid(): void
    {
        $inactive = $this->product(['is_active' => false]);

        $response = $this->withSession(['cart.'.$this->tenant->id => [
            ['product_id' => $inactive->id, 'qty' => 1],
        ]])->get('/loja/carrinho')->assertOk();

        $this->assertStringNotContainsString('translate-middle badge rounded-pill bg-danger', $response->getContent());
    }

    public function test_process_payment_rejects_a_cart_with_only_unavailable_products_without_creating_an_order(): void
    {
        $tenant = Tenant::factory()->withMercadoPago()->create(['slug' => 'mp-loja', 'is_active' => true]);
        $settings = $tenant->settings;
        $settings['payment']['methods'] = [['id' => 'mp_pix', 'enabled' => true]];
        $tenant->update(['settings' => $settings]);

        $inactive = $this->product(['is_active' => false], $tenant);

        $this->withSession(['cart.'.$tenant->id => [['product_id' => $inactive->id, 'qty' => 1]]])
            ->postJson('/mp-loja/process_payment', [
                'mp_method' => 'mp_pix',
                'formData' => [],
                'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '11999990000'],
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Total inválido.']);

        $this->assertSame(0, Order::withoutTenantScope()->count());
    }
}
