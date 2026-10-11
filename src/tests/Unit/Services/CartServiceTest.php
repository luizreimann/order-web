<?php

namespace Tests\Unit\Services;

use App\Exceptions\CartStockLimitException;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E3.1 — CartService: única fonte de verdade do carrinho (sessão `cart.{tenant_id}`).
 */
class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    private CartService $cart;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cart = new CartService;
        $this->tenant = Tenant::factory()->create();
    }

    private function product(array $attrs = [], ?Tenant $tenant = null): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'price_cents' => 1000,
        ], $attrs));
    }

    private function tracked(int $stock, array $attrs = []): Product
    {
        return $this->product(array_merge(['track_inventory' => true, 'stock_quantity' => $stock], $attrs));
    }

    private function cartSession(): array
    {
        return session()->get('cart.'.$this->tenant->id, []);
    }

    // ------------------------------------------------------------------ leitura

    public function test_session_key_is_per_tenant(): void
    {
        $this->assertSame('cart.'.$this->tenant->id, CartService::sessionKey($this->tenant));
        $this->assertSame('cart.abc', CartService::sessionKey('abc'));
    }

    public function test_empty_cart(): void
    {
        $this->assertSame([], $this->cart->items($this->tenant));
        $this->assertSame([], $this->cart->lines($this->tenant));
        $this->assertSame(0, $this->cart->count($this->tenant));
        $this->assertSame(0, $this->cart->totalCents([]));
    }

    public function test_lines_are_priced_from_the_database_and_totals_sum_in_cents(): void
    {
        $a = $this->product(['name' => 'Bolo', 'price_cents' => 1250]);
        $b = $this->product(['name' => 'Torta', 'price_cents' => 990]);
        session()->put('cart.'.$this->tenant->id, [
            ['product_id' => $a->id, 'qty' => 3],
            ['product_id' => $b->id, 'qty' => 1],
        ]);

        $lines = $this->cart->lines($this->tenant);

        $this->assertCount(2, $lines);
        $this->assertSame($a->id, $lines[0]['product_id']);
        $this->assertSame(1250, $lines[0]['unit_cents']);
        $this->assertSame(3750, $lines[0]['total_cents']);
        $this->assertSame(4740, $this->cart->subtotalCents($lines));
        $this->assertSame(4740, $this->cart->totalCents($lines));
        $this->assertSame(2, $this->cart->count($this->tenant));
    }

    public function test_a_price_change_is_reflected_immediately(): void
    {
        $a = $this->product(['price_cents' => 1000]);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $a->id, 'qty' => 2]]);

        $a->update(['price' => 15]);

        $this->assertSame(3000, $this->cart->subtotalCents($this->cart->lines($this->tenant)));
    }

    public function test_inactive_deleted_foreign_and_unknown_products_are_excluded(): void
    {
        $ok = $this->product(['price_cents' => 1000]);
        $inactive = $this->product(['price_cents' => 5000, 'is_active' => false]);
        $deleted = $this->product(['price_cents' => 5000]);
        $deleted->delete();
        $foreign = $this->product(['price_cents' => 5000], Tenant::factory()->create());

        session()->put('cart.'.$this->tenant->id, [
            ['product_id' => $ok->id, 'qty' => 1],
            ['product_id' => $inactive->id, 'qty' => 1],
            ['product_id' => $deleted->id, 'qty' => 1],
            ['product_id' => $foreign->id, 'qty' => 1],
            ['product_id' => (string) Str::uuid(), 'qty' => 1],
        ]);

        $lines = $this->cart->lines($this->tenant);

        $this->assertCount(1, $lines);
        $this->assertSame($ok->id, $lines[0]['product_id']);
        $this->assertSame(1000, $this->cart->subtotalCents($lines));
        $this->assertSame(1, $this->cart->count($this->tenant));
    }

    public function test_malformed_items_are_ignored_instead_of_breaking_the_query(): void
    {
        $ok = $this->product();

        session()->put('cart.'.$this->tenant->id, [
            ['product_id' => 'nao-e-uuid', 'qty' => 1],
            ['product_id' => $ok->id, 'qty' => 0],
            ['product_id' => $ok->id, 'qty' => -2],
            ['product_id' => $ok->id],
            ['qty' => 1],
            'lixo',
            ['product_id' => $ok->id, 'qty' => 2],
        ]);

        $this->assertSame([['product_id' => $ok->id, 'qty' => 2]], $this->cart->items($this->tenant));
        $this->assertCount(1, $this->cart->lines($this->tenant));

        session()->put('cart.'.$this->tenant->id, 'corrompido');
        $this->assertSame([], $this->cart->items($this->tenant));
    }

    public function test_resolve_lines_accepts_an_explicit_cart_and_keeps_order(): void
    {
        $a = $this->product(['price_cents' => 100]);
        $b = $this->product(['price_cents' => 200]);

        $lines = $this->cart->resolveLines($this->tenant, [
            ['product_id' => $b->id, 'qty' => 1],
            ['product_id' => $a->id, 'qty' => 2],
        ]);

        $this->assertSame([$b->id, $a->id], array_column($lines, 'product_id'));
        $this->assertSame(400, $this->cart->subtotalCents($lines));
    }

    public function test_line_exposes_available_stock(): void
    {
        $tracked = $this->tracked(4);
        $free = $this->product();

        $lines = $this->cart->resolveLines($this->tenant, [
            ['product_id' => $tracked->id, 'qty' => 1],
            ['product_id' => $free->id, 'qty' => 1],
        ]);

        $this->assertSame(4, $lines[0]['available']);
        $this->assertNull($lines[1]['available'], 'sem controle de estoque = ilimitado');
    }

    public function test_order_service_uses_the_same_lines_and_subtotal(): void
    {
        $a = $this->product(['price_cents' => 1250]);
        $inactive = $this->product(['price_cents' => 9999, 'is_active' => false]);
        $items = [
            ['product_id' => $a->id, 'qty' => 3],
            ['product_id' => $inactive->id, 'qty' => 1],
        ];
        session()->put('cart.'.$this->tenant->id, $items);

        $orders = new OrderService($this->cart);
        $viaOrder = $orders->resolveCartLines($this->tenant, $items);
        $viaCart = $this->cart->lines($this->tenant);

        $this->assertSame(array_column($viaCart, 'product_id'), array_column($viaOrder, 'product_id'));
        $this->assertSame($this->cart->subtotalCents($viaCart), $orders->calculateSubtotal($viaOrder));
        $this->assertSame(3750, $orders->calculateSubtotal($viaOrder));
    }

    // ------------------------------------------------------------------ escrita

    public function test_add_creates_and_accumulates_lines(): void
    {
        $a = $this->product();
        $b = $this->product();

        $this->cart->add($this->tenant, $a->id, 2);
        $this->cart->add($this->tenant, $b->id);
        $this->cart->add($this->tenant, $a->id, 3);

        $this->assertSame([
            ['product_id' => $a->id, 'qty' => 5],
            ['product_id' => $b->id, 'qty' => 1],
        ], $this->cartSession());
    }

    public function test_add_clamps_non_positive_quantity_to_one(): void
    {
        $a = $this->product();

        $this->cart->add($this->tenant, $a->id, 0);
        $this->cart->add($this->tenant, $a->id, -5);

        $this->assertSame(2, $this->cartSession()[0]['qty']);
    }

    public function test_add_refuses_inactive_deleted_foreign_and_unknown_products(): void
    {
        $inactive = $this->product(['is_active' => false]);
        $deleted = $this->product();
        $deleted->delete();
        $foreign = $this->product([], Tenant::factory()->create());

        foreach ([$inactive->id, $deleted->id, $foreign->id, (string) Str::uuid()] as $id) {
            try {
                $this->cart->add($this->tenant, $id);
                $this->fail("deveria recusar {$id}");
            } catch (ModelNotFoundException) {
                // esperado
            }
        }

        $this->assertSame([], $this->cartSession());
    }

    public function test_add_respects_the_stock_limit_including_what_is_already_in_the_cart(): void
    {
        $p = $this->tracked(3);

        $this->assertStockLimit('Só é possível adicionar mais 3 unidade(s) deste produto.', fn () => $this->cart->add($this->tenant, $p->id, 4));
        $this->assertSame([], $this->cartSession());

        $this->cart->add($this->tenant, $p->id, 2);
        $this->assertStockLimit('Só é possível adicionar mais 1 unidade(s) deste produto.', fn () => $this->cart->add($this->tenant, $p->id, 2));
        $this->assertSame(2, $this->cartSession()[0]['qty']);

        $this->cart->add($this->tenant, $p->id, 1);
        $this->assertStockLimit('Você já tem todas as 3 unidade(s) disponíveis no carrinho.', fn () => $this->cart->add($this->tenant, $p->id));
        $this->assertSame(3, $this->cartSession()[0]['qty']);
    }

    public function test_add_refuses_a_sold_out_product(): void
    {
        $p = $this->tracked(0);

        $this->assertStockLimit('Produto esgotado.', fn () => $this->cart->add($this->tenant, $p->id));
        $this->assertSame([], $this->cartSession());
    }

    public function test_add_has_no_limit_without_inventory_control(): void
    {
        $p = $this->product();

        $this->cart->add($this->tenant, $p->id, 500);

        $this->assertSame(500, $this->cartSession()[0]['qty']);
    }

    public function test_increment_adds_one_up_to_the_stock(): void
    {
        $p = $this->tracked(2, ['name' => 'Torta']);
        $this->cart->add($this->tenant, $p->id, 1);

        $this->cart->increment($this->tenant, $p->id);
        $this->assertSame(2, $this->cartSession()[0]['qty']);

        $this->assertStockLimit('Só temos 2 unidade(s) de "Torta" disponíveis.', fn () => $this->cart->increment($this->tenant, $p->id));
        $this->assertSame(2, $this->cartSession()[0]['qty']);
    }

    public function test_increment_reads_stock_from_the_database_not_from_the_caller(): void
    {
        $p = $this->tracked(5);
        $this->cart->add($this->tenant, $p->id, 2);

        // o estoque cai depois de o produto entrar no carrinho
        $p->update(['stock_quantity' => 2]);

        $this->assertStockLimit('Só temos 2 unidade(s) de "'.$p->name.'" disponíveis.', fn () => $this->cart->increment($this->tenant, $p->id));
    }

    public function test_increment_is_unlimited_without_inventory_control(): void
    {
        $p = $this->product();
        $this->cart->add($this->tenant, $p->id, 99);

        $this->cart->increment($this->tenant, $p->id);

        $this->assertSame(100, $this->cartSession()[0]['qty']);
    }

    public function test_increment_ignores_products_not_in_the_cart_or_no_longer_available(): void
    {
        $inCart = $this->product();
        $outside = $this->product();
        $this->cart->add($this->tenant, $inCart->id);

        $this->cart->increment($this->tenant, $outside->id);
        $this->assertSame([['product_id' => $inCart->id, 'qty' => 1]], $this->cartSession());

        $inCart->update(['is_active' => false]);
        $this->cart->increment($this->tenant, $inCart->id);
        $this->assertSame(1, $this->cartSession()[0]['qty']);
    }

    public function test_decrement_stops_at_one(): void
    {
        $p = $this->product();
        $this->cart->add($this->tenant, $p->id, 2);

        $this->cart->decrement($this->tenant, $p->id);
        $this->cart->decrement($this->tenant, $p->id);
        $this->cart->decrement($this->tenant, $p->id);

        $this->assertSame([['product_id' => $p->id, 'qty' => 1]], $this->cartSession());
    }

    public function test_remove_and_clear(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->cart->add($this->tenant, $a->id);
        $this->cart->add($this->tenant, $b->id);

        $this->cart->remove($this->tenant, $a->id);
        $this->assertSame([['product_id' => $b->id, 'qty' => 1]], $this->cartSession());

        $this->cart->clear($this->tenant);
        $this->assertFalse(session()->has('cart.'.$this->tenant->id));
    }

    public function test_carts_are_isolated_per_tenant(): void
    {
        $other = Tenant::factory()->create();
        $mine = $this->product();
        $theirs = $this->product([], $other);

        $this->cart->add($this->tenant, $mine->id);
        $this->cart->add($other, $theirs->id, 2);
        $this->cart->clear($this->tenant);

        $this->assertSame([], $this->cart->items($this->tenant));
        $this->assertSame([['product_id' => $theirs->id, 'qty' => 2]], $this->cart->items($other));

        // um produto do tenant B colocado à força na sessão do tenant A não é precificado
        session()->put('cart.'.$this->tenant->id, [['product_id' => $theirs->id, 'qty' => 1]]);
        $this->assertSame([], $this->cart->lines($this->tenant));
    }

    private function assertStockLimit(string $message, callable $action): void
    {
        try {
            $action();
            $this->fail('deveria lançar CartStockLimitException');
        } catch (CartStockLimitException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
